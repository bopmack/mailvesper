<?php

namespace App\Services\Webhooks;

use App\Jobs\DeliverWebhook;
use App\Models\Message;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;

/** Fans an event out to the workspace's webhook endpoints. */
class Webhooks
{
    public const EVENTS = [
        'email.sent' => 'SES accepted the email',
        'email.delivered' => 'The receiving server accepted it',
        'email.delivery_delayed' => 'Temporary problem; SES is retrying',
        'email.bounced' => 'Hard or soft bounce',
        'email.complained' => 'The recipient marked it as spam',
        'email.rejected' => 'SES refused to send it',
        'email.opened' => 'Opened (machine opens flagged)',
        'email.clicked' => 'A link was clicked (scanner clicks flagged)',
        'email.failed' => 'Couldn’t be sent',
        'email.replied' => 'The recipient replied (read from your mailbox)',
        'email.suppressed' => 'Not sent: address is suppressed',
        'email.unsubscribed' => 'The recipient unsubscribed',
    ];

    /** Retry delays in seconds after each failed attempt (about 20 hours in total). */
    public const BACKOFF = [60, 300, 1800, 7200, 21600, 43200];

    public const INLINE_LIMIT = 5;

    /** @param array<string, mixed> $extra event-specific fields */
    public function emit(string $workspaceId, string $type, ?Message $message, array $extra = []): int
    {
        $endpoints = WebhookEndpoint::where('workspace_id', $workspaceId)->where('enabled', true)->get()->filter(fn (WebhookEndpoint $e) => $e->wants($type));
        if ($endpoints->isEmpty()) {
            return 0;
        }
        $payload = ['type' => $type, 'created_at' => now()->toIso8601ZuluString(), 'data' => ($message ? self::messageData($message) : []) + $extra];
        // Inline (after the response) only for a person's own request. Events from SES or a provider arrive in bursts of
        // thousands: delivered inline they'd hold every web worker, and an endpoint on the same server would wait for a
        // worker that never frees up. Those always go through the queue.
        $inline = $endpoints->count() <= self::INLINE_LIMIT && ! app()->runningInConsole() && ! request()->is('*webhooks/ses/*', '*hooks/delivery/*');
        foreach ($endpoints as $e) {
            $d = WebhookDelivery::create(['endpoint_id' => $e->id, 'workspace_id' => $workspaceId, 'event_type' => $type, 'message_id' => $message?->id, 'payload' => $payload]);
            $inline ? DeliverWebhook::dispatchAfterResponse($d->id) : DeliverWebhook::dispatch($d->id);
        }

        return $endpoints->count();
    }

    /** @return array<string, mixed> */
    public static function messageData(Message $m): array
    {
        return [
            'id' => $m->id, 'tracking_code' => $m->tracking_code, 'to' => $m->to_email,
            'from' => $m->from_name ? "{$m->from_name} <{$m->from_email}>" : $m->from_email, 'subject' => $m->subject,
            'type' => $m->type, 'tags' => $m->tags ?? (object) [], 'provider' => $m->provider, 'ses_message_id' => $m->ses_message_id,
        ];
    }
}
