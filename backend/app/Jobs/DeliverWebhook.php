<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Webhooks\Signer;
use App\Services\Webhooks\UrlGuard;
use App\Services\Webhooks\Webhooks;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/** POSTs one signed delivery. Non-2xx, timeouts and network errors retry on the backoff schedule. */
class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Endpoints failing this many times in a row, with no success for a day, are paused. */
    public const DISABLE_AFTER = 50;

    public function __construct(public string $deliveryId) {}

    public function handle(): void
    {
        $claimed = DB::table('webhook_deliveries')->where('id', $this->deliveryId)->whereIn('status', ['pending', 'retrying'])
            ->update(['status' => 'sending', 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);
        if (! $claimed) {
            return;
        }
        $d = WebhookDelivery::findOrFail($this->deliveryId);
        $endpoint = WebhookEndpoint::find($d->endpoint_id);
        if (! $endpoint || ! $endpoint->enabled) {
            $d->forceFill(['status' => 'cancelled', 'error' => 'Endpoint disabled or deleted.'])->save();

            return;
        }
        self::attempt($d, $endpoint);
    }

    /** Sends now and records the outcome. Also used by "Send test", synchronously. */
    public static function attempt(WebhookDelivery $d, WebhookEndpoint $endpoint): void
    {
        $body = (string) json_encode($d->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $host = (string) parse_url($endpoint->url, PHP_URL_HOST);
        $port = (int) (parse_url($endpoint->url, PHP_URL_PORT) ?: 443);
        $started = microtime(true);
        $code = null;
        $responseBody = null;
        [$error, $ip] = UrlGuard::vet($endpoint->url); // connect to exactly the address that was checked

        if ($error === null) {
            try {
                $res = Http::withHeaders(Signer::headers($endpoint->secret, 'msg_'.$d->id, time(), $body) + ['User-Agent' => 'MailVesper-Webhooks/1.0'])
                    ->withBody($body, 'application/json')->timeout(10)->connectTimeout(5)->withoutRedirecting()
                    ->withOptions($ip ? ['curl' => [CURLOPT_RESOLVE => ["$host:$port:$ip"]]] : [])
                    ->post($endpoint->url);
                $code = $res->status();
                $responseBody = mb_substr($res->body(), 0, 500);
                $error = $res->successful() ? null : "HTTP $code";
            } catch (Throwable $e) {
                $error = str_contains($e->getMessage(), 'timed out') ? 'Timed out after 10 seconds' : 'Connection failed: '.mb_substr($e->getMessage(), 0, 160);
            }
        }
        $ms = (int) round((microtime(true) - $started) * 1000);

        if ($error === null) {
            $d->forceFill(['status' => 'success', 'response_code' => $code, 'response_body' => $responseBody, 'error' => null, 'duration_ms' => $ms, 'delivered_at' => now(), 'next_attempt_at' => null])->save();
            $endpoint->forceFill(['failure_streak' => 0, 'last_success_at' => now()])->save();

            return;
        }

        $test = $d->event_type === 'email.test';
        $delay = $test ? null : (Webhooks::BACKOFF[$d->attempts - 1] ?? null);
        $d->forceFill(['status' => $delay ? 'retrying' : 'failed', 'response_code' => $code, 'response_body' => $responseBody, 'error' => mb_substr($error, 0, 255), 'duration_ms' => $ms,
            'next_attempt_at' => $delay ? now()->addSeconds($delay) : null])->save();
        if ($test) {
            return; // a test ping doesn't count against the endpoint
        }
        $endpoint->forceFill(['failure_streak' => $endpoint->failure_streak + 1, 'last_failure_at' => now()])->save();
        if ($endpoint->failure_streak >= self::DISABLE_AFTER && (! $endpoint->last_success_at || $endpoint->last_success_at->lt(now()->subDay()))) {
            $endpoint->forceFill(['enabled' => false, 'disabled_reason' => 'Paused automatically after '.self::DISABLE_AFTER.' failed deliveries in a row. Fix the endpoint, then turn it back on.'])->save();
        }
        if ($delay) {
            self::dispatch($d->id)->onConnection('database')->delay(now()->addSeconds($delay));
        }
    }
}
