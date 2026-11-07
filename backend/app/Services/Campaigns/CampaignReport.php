<?php

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Services\Activity\Signals;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** A campaign's results, built from the same messages and events as Activity, so the numbers match. */
class CampaignReport
{
    /** @return array<string, mixed> */
    public function build(Campaign $c): array
    {
        $totals = $this->totals($c->id, null);
        $out = [
            'totals' => $totals,
            'variants' => $c->ab_enabled ? ['a' => $this->totals($c->id, 'a'), 'b' => $this->totals($c->id, 'b')] : null,
            'links' => $this->links($c->id),
            'timeline' => $this->timeline($c),
            'providers' => $this->providers($c->id),
            'average' => $this->average($c),
        ];

        return $out;
    }

    /** @return array<string, int> */
    public function totals(string $campaignId, ?string $variant): array
    {
        $m = DB::table('messages')->where('campaign_id', $campaignId)->when($variant, fn ($q, $v) => $q->where('variant', $v))
            ->selectRaw("count(*) as created, sum(case when ses_message_id is not null then 1 else 0 end) as sent, sum(case when delivered_at is not null then 1 else 0 end) as delivered,
                sum(case when opened_at is not null then 1 else 0 end) as opened, sum(case when opened_at is null and machine_open_count > 0 then 1 else 0 end) as machine_only,
                sum(case when clicked_at is not null then 1 else 0 end) as clicked, sum(case when status = 'bounced' then 1 else 0 end) as bounced,
                sum(case when status = 'complained' then 1 else 0 end) as complained, sum(case when status in ('failed','rejected') then 1 else 0 end) as failed,
                sum(case when status = 'suppressed' then 1 else 0 end) as suppressed, sum(case when status in ('queued','sending') then 1 else 0 end) as queued")->first();
        $unsub = DB::table('message_events')->join('messages', 'messages.id', '=', 'message_events.message_id')->where('messages.campaign_id', $campaignId)
            ->when($variant, fn ($q, $v) => $q->where('messages.variant', $v))->where('message_events.type', 'unsubscribed')->distinct()->count('message_events.message_id');
        $r = DB::table('campaign_recipients')->where('campaign_id', $campaignId)->when($variant, fn ($q, $v) => $q->where('variant', $v))
            ->selectRaw("count(*) as recipients, sum(case when status = 'pending' then 1 else 0 end) as pending, sum(case when status = 'skipped' then 1 else 0 end) as skipped")->first();

        return array_map('intval', [
            'recipients' => $r->recipients ?? 0, 'pending' => $r->pending ?? 0, 'skipped' => $r->skipped ?? 0, 'queued' => $m->queued ?? 0,
            'sent' => $m->sent ?? 0, 'delivered' => $m->delivered ?? 0, 'opened' => $m->opened ?? 0, 'machine_only' => $m->machine_only ?? 0, 'clicked' => $m->clicked ?? 0,
            'bounced' => $m->bounced ?? 0, 'complained' => $m->complained ?? 0, 'unsubscribed' => $unsub, 'failed' => $m->failed ?? 0,
        ]);
    }

    /** @return list<array{url: string, clicks: int, people: int}> human clicks per link */
    private function links(string $campaignId): array
    {
        $rows = DB::table('message_events')->join('messages', 'messages.id', '=', 'message_events.message_id')
            ->where('messages.campaign_id', $campaignId)->where('message_events.type', 'clicked')->where('message_events.machine', false)
            ->limit(100000)->get(['message_events.message_id', 'message_events.data']);
        $links = [];
        foreach ($rows as $row) {
            $url = (string) (json_decode((string) $row->data, true)['url'] ?? '');
            if ($url === '') {
                continue;
            }
            $links[$url]['clicks'] = ($links[$url]['clicks'] ?? 0) + 1;
            $links[$url]['people'][$row->message_id] = true;
        }
        $out = [];
        foreach ($links as $url => $l) {
            $out[] = ['url' => $url, 'clicks' => $l['clicks'], 'people' => count($l['people'])];
        }
        usort($out, fn ($a, $b) => $b['people'] <=> $a['people']);

        return array_slice($out, 0, 50);
    }

    /** @return list<array{hour: string, opens: int, clicks: int}> first 72 hours, unique people per hour */
    private function timeline(Campaign $c): array
    {
        if (! $c->started_at) {
            return [];
        }
        $start = $c->started_at->copy()->utc()->startOfHour();
        $end = $start->copy()->addHours(72);
        $rows = DB::table('message_events')->join('messages', 'messages.id', '=', 'message_events.message_id')
            ->where('messages.campaign_id', $c->id)->whereIn('message_events.type', ['opened', 'clicked'])->where('message_events.machine', false)
            ->whereBetween('message_events.occurred_at', [$start->toDateTimeString(), $end->toDateTimeString()])
            ->get(['message_events.type', 'message_events.occurred_at', 'message_events.message_id']);
        $buckets = [];
        foreach ($rows as $r) {
            $h = Carbon::parse((string) $r->occurred_at, 'UTC')->startOfHour()->toIso8601ZuluString();
            $buckets[$h][$r->type][$r->message_id] = true;
        }
        $out = [];
        $last = min(now()->utc()->startOfHour()->getTimestamp(), $end->getTimestamp());
        for ($t = $start->copy(); $t->getTimestamp() <= $last; $t->addHour()) {
            $k = $t->toIso8601ZuluString();
            $out[] = ['hour' => $k, 'opens' => count($buckets[$k]['opened'] ?? []), 'clicks' => count($buckets[$k]['clicked'] ?? [])];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function providers(string $campaignId): array
    {
        return DB::table('messages')->where('campaign_id', $campaignId)->whereNotNull('ses_message_id')
            ->selectRaw("COALESCE(provider, 'other') as p, count(*) as sent, sum(case when delivered_at is not null then 1 else 0 end) as delivered,
                sum(case when opened_at is not null then 1 else 0 end) as opened, sum(case when clicked_at is not null then 1 else 0 end) as clicked, sum(case when status = 'bounced' then 1 else 0 end) as bounced")
            ->groupBy('p')->orderByDesc('sent')->get()
            ->map(fn ($r) => ['provider' => $r->p, 'name' => Signals::PROVIDERS[$r->p] ?? 'Other', 'sent' => (int) $r->sent, 'delivered' => (int) $r->delivered, 'opened' => (int) $r->opened, 'clicked' => (int) $r->clicked, 'bounced' => (int) $r->bounced])
            ->values()->all();
    }

    /** @return array<string, float>|null rates across the workspace's last 10 other finished campaigns */
    private function average(Campaign $c): ?array
    {
        $ids = Campaign::where('workspace_id', $c->workspace_id)->where('status', 'sent')->where('id', '!=', $c->id)->orderByDesc('finished_at')->limit(10)->pluck('id');
        if ($ids->isEmpty()) {
            return null;
        }
        $t = DB::table('messages')->whereIn('campaign_id', $ids)->whereNotNull('ses_message_id')
            ->selectRaw('count(*) as sent, sum(case when delivered_at is not null then 1 else 0 end) as delivered, sum(case when opened_at is not null then 1 else 0 end) as opened, sum(case when clicked_at is not null then 1 else 0 end) as clicked')->first();
        $sent = max(1, (int) $t->sent);
        $del = max(1, (int) $t->delivered);

        return ['campaigns' => $ids->count(), 'delivered_rate' => round((int) $t->delivered / $sent, 4), 'open_rate' => round((int) $t->opened / $del, 4), 'click_rate' => round((int) $t->clicked / $del, 4)];
    }
}
