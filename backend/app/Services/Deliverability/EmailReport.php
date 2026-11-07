<?php

namespace App\Services\Deliverability;

use App\Http\Controllers\Api\V1\Activity\ActivityController;
use App\Models\DeliverabilityCheck;
use App\Models\Message;
use App\Services\Copilot\DmarcReports;
use App\Services\Smtp\Tracking;
use App\Support\Edition;
use Illuminate\Support\Facades\DB;

/**
 * Everything MailVesper can truthfully say about one email, from real data: who took it and what they answered,
 * how its domain authenticates on this exact route (the latest setup check) and what Gmail / Yahoo reported back
 * (DMARC reports), what in the content spam filters weigh, the sending IP's reputation, and what people did.
 * Inbox vs spam folder isn't reported by any mailbox provider; the report says so and offers a placement test.
 */
class EmailReport
{
    public function __construct(private DmarcReports $dmarc) {}

    /** @return array<string, mixed> */
    public function for(Message $m): array
    {
        $events = DB::table('message_events')->where('message_id', $m->id)->orderBy('occurred_at')->orderBy('id')->get(['type', 'machine', 'data', 'occurred_at']);
        $data = fn ($e) => $e->data ? (array) json_decode((string) $e->data, true) : [];
        $first = fn (string $type) => $events->first(fn ($e) => $e->type === $type);
        $via = ActivityController::via($m);
        $sent = $first('sent');
        $end = $events->first(fn ($e) => in_array($e->type, ['delivered', 'bounced', 'complained', 'rejected', 'failed', 'suppressed'], true));

        $domain = substr((string) strrchr(mb_strtolower($m->from_email), '@'), 1);
        $check = DeliverabilityCheck::where('workspace_id', $m->workspace_id)->where('domain', $domain)->where('status', 'done')->orderByDesc('created_at')->first();
        $dmarc = $this->dmarc->summary($m->workspace_id)[$domain] ?? null;

        $opens = $events->where('type', 'opened');
        $clicks = $events->where('type', 'clicked');

        return [
            'delivery' => [
                'via' => $via, 'host' => $sent ? ($data($sent)['host'] ?? null) : null,
                'sent_at' => $sent?->occurred_at, 'server_reply' => $sent ? ($data($sent)['smtp_response'] ?? null) : null,
                'outcome' => $end ? $end->type : ($m->sent_at ? 'sent' : $m->status), 'outcome_at' => $end?->occurred_at,
                'outcome_detail' => $end ? ($data($end)['smtp_response'] ?? $data($end)['diagnostic'] ?? $data($end)['reason'] ?? null) : $m->error,
                'delivery_reported' => $via !== 'smtp', // SMTP servers never report delivery
            ],
            'authentication' => [
                'check' => $check ? ['id' => $check->id, 'at' => $check->finished_at?->toIso8601ZuluString(), 'result' => $check->result] : null,
                'dmarc_reports' => $dmarc, // what receivers (Gmail, Yahoo…) reported for this domain over 30 days
            ],
            'content' => Analyzer::content((string) $m->subject, $m->html, $m->text, $m->from_email, $m->from_name, (string) $m->type, Tracking::host($m->workspace_id)),
            'engagement' => [
                'opens' => $opens->where('machine', false)->count(), 'machine_opens' => $opens->where('machine', true)->count(),
                'clicks' => $clicks->where('machine', false)->count(), 'scanner_clicks' => $clicks->where('machine', true)->count(),
                'first_open_at' => $opens->firstWhere('machine', false)?->occurred_at,
            ],
            'placement' => ['known' => false, 'inbox_tests' => app(Edition::class)->has('inbox_tests')],
        ];
    }
}
