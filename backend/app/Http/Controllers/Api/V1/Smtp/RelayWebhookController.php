<?php

namespace App\Http\Controllers\Api\V1\Smtp;

use App\Http\Controllers\Controller;
use App\Models\DeliveryHook;
use App\Services\Smtp\RelayEvents;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The SMTP relay provider's event webhook (one secret URL per workspace). SendGrid and Mailgun requests are also
 * signature-checked when the workspace saved the provider's key. Answers 200 quickly for anything it can read, so the
 * provider doesn't retry events MailVesper has no use for.
 */
class RelayWebhookController extends Controller
{
    public function __invoke(Request $request, string $token, RelayEvents $events): Response
    {
        $hook = DeliveryHook::where('token_hash', DeliveryHook::hashToken($token))->first();
        if (! $hook) {
            return response('', 404);
        }
        $body = $request->getContent();
        try {
            $payload = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $hook->forceFill(['last_error' => 'A request that wasn’t JSON arrived ('.now()->toDateTimeString().' UTC).'])->save();

            return response('', 400);
        }
        if (! is_array($payload)) {
            return response('', 400);
        }
        if (! RelayEvents::verify($hook, $body, $payload, $request->header('X-Twilio-Email-Event-Webhook-Signature'), $request->header('X-Twilio-Email-Event-Webhook-Timestamp'))) {
            $hook->forceFill(['last_error' => 'A request with a wrong signature was refused ('.now()->toDateTimeString().' UTC). Check the signing key.'])->save();

            return response('', 403);
        }
        try {
            $n = $events->apply($hook, $payload);
        } catch (Throwable $e) {
            report($e);
            Log::warning('Delivery report couldn’t be applied', ['hook' => $hook->id, 'error' => $e->getMessage()]);

            return response('', 500); // the provider retries
        }
        $hook->increment('events', $n, ['last_event_at' => now(), 'last_error' => null]);

        return response('', 200);
    }
}
