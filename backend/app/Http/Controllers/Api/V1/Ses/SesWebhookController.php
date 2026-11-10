<?php

namespace App\Http\Controllers\Api\V1\Ses;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessSesEvents;
use App\Models\SesConnection;
use Aws\Sns\Exception\InvalidSnsMessageException;
use Aws\Sns\Message;
use Aws\Sns\MessageValidator;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Receives SES events from the customer's SNS topic. Every message is signature-checked against
 * Amazon's certificate and must come from the topic we created for this connection. Events are only stored here;
 * ProcessSesEvents applies them.
 */
class SesWebhookController extends Controller
{
    public function __invoke(Request $request, string $token, MessageValidator $validator): Response
    {
        $conn = SesConnection::where('webhook_token_hash', SesConnection::hashToken($token))->first();
        if (! $conn) {
            return response('', 404);
        }

        try {
            $message = new Message((array) json_decode($request->getContent(), true, 64, JSON_THROW_ON_ERROR));
            $validator->validate($message);
        } catch (InvalidSnsMessageException|\InvalidArgumentException|\JsonException $e) {
            Log::notice('SES webhook: rejected message', ['connection' => $conn->id, 'reason' => $e->getMessage()]);

            return response('', 403);
        }
        if (! $conn->sns_topic_arn || $message['TopicArn'] !== $conn->sns_topic_arn) {
            Log::notice('SES webhook: message from an unexpected topic', ['connection' => $conn->id, 'topic' => $message['TopicArn'], 'expected' => $conn->sns_topic_arn]);

            return response('', 403);
        }

        return match ($message['Type']) {
            'SubscriptionConfirmation' => $this->confirm($conn, $message),
            'Notification' => $this->store($conn, $message),
            default => response('', 204),
        };
    }

    private function confirm(SesConnection $conn, Message $message): Response
    {
        $url = (string) $message['SubscribeURL'];
        $host = (string) parse_url($url, PHP_URL_HOST);
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || ! preg_match('/^sns\.[a-z0-9-]+\.amazonaws\.com(\.cn)?$/', $host)) {
            return response('', 403);
        }
        try {
            $ok = Http::timeout(10)->get($url)->successful();
        } catch (Throwable) {
            $ok = false;
        }
        if (! $ok) {
            return response('', 502); // SNS retries the confirmation
        }
        $steps = $conn->setup_steps ?? [];
        $steps['subscribe'] = ['status' => 'done'];
        $conn->forceFill(['subscription_confirmed_at' => now(), 'setup_steps' => $steps])->save();

        return response('', 200);
    }

    private function store(SesConnection $conn, Message $message): Response
    {
        $event = json_decode((string) $message['Message'], true);
        if (! is_array($event)) {
            return response('', 204);
        }
        $type = (string) ($event['eventType'] ?? $event['notificationType'] ?? 'Unknown');
        $mail = (array) ($event['mail'] ?? []);
        $at = $event[lcfirst($type)]['timestamp'] ?? $mail['timestamp'] ?? null;

        $inserted = DB::table('ses_events')->insertOrIgnore([
            'workspace_id' => $conn->workspace_id,
            'event_type' => mb_substr($type, 0, 32),
            'message_id' => isset($mail['messageId']) ? mb_substr((string) $mail['messageId'], 0, 128) : null,
            'sns_message_id' => mb_substr((string) $message['MessageId'], 0, 64),
            'recipients' => json_encode(array_slice((array) ($mail['destination'] ?? []), 0, 50)),
            'occurred_at' => $at ? Carbon::parse((string) $at)->utc()->toDateTimeString() : null,
            'payload' => json_encode($event),
            'created_at' => now(),
        ]);
        if (! $conn->last_event_at || $conn->last_event_at->lt(now()->subMinute())) {
            $conn->forceFill(['last_event_at' => now()])->saveQuietly();
        }
        // Kept, then applied by a background job: Amazon gets its answer at once, even while the server is busy
        // sending, so no notice is lost. SNS can deliver the same message twice; the unique key keeps one.
        if ($inserted) {
            ProcessSesEvents::kick();
        }

        return response('', 200);
    }
}
