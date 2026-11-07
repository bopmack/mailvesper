<?php

namespace App\Services\Activity;

use App\Models\Message;
use App\Services\Webhooks\Webhooks;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Records a step in a message's journey and tells the workspace's webhooks about it. */
class Timeline
{
    /** Timeline type → webhook event. Types without an entry are internal (accepted, retrying). */
    public const WEBHOOK = [
        'sent' => 'email.sent', 'delivered' => 'email.delivered', 'delayed' => 'email.delivery_delayed', 'bounced' => 'email.bounced',
        'complained' => 'email.complained', 'rejected' => 'email.rejected', 'opened' => 'email.opened', 'clicked' => 'email.clicked',
        'failed' => 'email.failed', 'suppressed' => 'email.suppressed', 'unsubscribed' => 'email.unsubscribed', 'replied' => 'email.replied',
    ];

    public function __construct(private Webhooks $webhooks) {}

    /**
     * @param  array<string, mixed>  $data  shown in the timeline and sent in the webhook payload
     */
    public function record(string $workspaceId, ?Message $message, string $type, array $data = [], bool $machine = false, ?Carbon $at = null, ?int $sesEventId = null): void
    {
        DB::table('message_events')->insert([
            'workspace_id' => $workspaceId, 'message_id' => $message?->id, 'ses_event_id' => $sesEventId, 'type' => $type, 'machine' => $machine,
            'data' => $data ? json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null, 'occurred_at' => ($at ?? now())->utc()->toDateTimeString(),
        ]);
        if (isset(self::WEBHOOK[$type])) {
            $this->webhooks->emit($workspaceId, self::WEBHOOK[$type], $message, $data + (in_array($type, ['opened', 'clicked'], true) ? ['machine' => $machine] : []));
        }
    }
}
