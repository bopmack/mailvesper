<?php

namespace App\Http\Controllers\Api\V1\Activity;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Services\Activity\Signals;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Overview numbers, computed straight from the event timeline so they always match Activity.
 * Days are UTC. Opens and clicks are unique per email and exclude machine opens and scanner clicks.
 */
class StatsController extends Controller
{
    public const METRICS = ['sent', 'delivered', 'bounced', 'complained', 'opened', 'machine', 'clicked'];

    public function __invoke(Request $request, Workspace $workspace): JsonResponse
    {
        $data = $request->validate(['days' => ['nullable', Rule::in([7, 14, 30])], 'type' => ['nullable', Rule::in(['transactional', 'marketing'])]]);
        $days = (int) ($data['days'] ?? 14);
        $type = $data['type'] ?? null;
        $end = now()->utc()->endOfDay();
        $start = $end->copy()->subDays($days - 1)->startOfDay();

        $series = [];
        for ($d = $start->copy(); $d <= $end; $d->addDay()) {
            $series[$d->toDateString()] = ['date' => $d->toDateString()] + array_fill_keys(self::METRICS, 0);
        }
        foreach ($this->daily($workspace->id, $start, $end, $type) as $row) {
            if (isset($series[$row->day])) {
                $series[$row->day][$row->metric] = (int) $row->n;
            }
        }

        return response()->json([
            'days' => $days, 'from' => $start->toDateString(), 'to' => $end->toDateString(),
            'totals' => $this->totals($workspace->id, $start, $end, $type),
            'previous' => $this->totals($workspace->id, $start->copy()->subDays($days), $start->copy()->subSecond(), $type),
            'series' => array_values($series),
            'providers' => $this->providers($workspace->id, $start, $end, $type),
        ]);
    }

    /** @return Collection<int, \stdClass> rows of day, metric, n */
    private function daily(string $ws, Carbon $start, Carbon $end, ?string $type)
    {
        $q = fn () => DB::table('message_events as e')->where('e.workspace_id', $ws)->whereBetween('e.occurred_at', [$start->toDateTimeString(), $end->toDateTimeString()])
            ->when($type, fn ($q, $t) => $q->join('messages as m', 'm.id', '=', 'e.message_id')->where('m.type', $t));
        $plain = $q()->whereIn('e.type', ['sent', 'delivered', 'bounced', 'complained'])->selectRaw('DATE(e.occurred_at) as day, e.type as metric, count(*) as n')->groupBy('day', 'metric')->get();
        $unique = $q()->whereIn('e.type', ['opened', 'clicked'])
            ->selectRaw("DATE(e.occurred_at) as day, CASE WHEN e.type = 'clicked' THEN 'clicked' WHEN e.machine = 1 THEN 'machine' ELSE 'opened' END as metric, count(DISTINCT e.message_id) as n")
            ->where(fn ($w) => $w->where('e.type', 'opened')->orWhere('e.machine', 0))->groupBy('day', 'metric')->get();

        return $plain->concat($unique);
    }

    /** @return array<string, int> */
    private function totals(string $ws, Carbon $start, Carbon $end, ?string $type): array
    {
        $base = fn () => DB::table('message_events as e')->where('e.workspace_id', $ws)->whereBetween('e.occurred_at', [$start->toDateTimeString(), $end->toDateTimeString()])
            ->when($type, fn ($q, $t) => $q->join('messages as m', 'm.id', '=', 'e.message_id')->where('m.type', $t));
        $counts = $base()->whereIn('e.type', ['sent', 'delivered', 'bounced', 'complained'])->selectRaw('e.type as t, count(*) as n')->groupBy('t')->pluck('n', 't');

        return [
            'sent' => (int) ($counts['sent'] ?? 0), 'delivered' => (int) ($counts['delivered'] ?? 0),
            'bounced' => (int) ($counts['bounced'] ?? 0), 'complained' => (int) ($counts['complained'] ?? 0),
            'opened' => (int) $base()->where('e.type', 'opened')->where('e.machine', 0)->distinct()->count('e.message_id'),
            'machine' => (int) $base()->where('e.type', 'opened')->where('e.machine', 1)->distinct()->count('e.message_id'),
            'clicked' => (int) $base()->where('e.type', 'clicked')->where('e.machine', 0)->distinct()->count('e.message_id'),
        ];
    }

    /**
     * Per provider, from the same event timeline as the totals (so the table always adds up to the KPIs).
     *
     * @return list<array<string, mixed>>
     */
    private function providers(string $ws, Carbon $start, Carbon $end, ?string $type): array
    {
        $rows = DB::table('message_events as e')->join('messages as m', 'm.id', '=', 'e.message_id')
            ->where('e.workspace_id', $ws)->whereBetween('e.occurred_at', [$start->toDateTimeString(), $end->toDateTimeString()])
            ->when($type, fn ($q, $t) => $q->where('m.type', $t))
            ->selectRaw("COALESCE(m.provider, 'other') as p,
                sum(case when e.type = 'sent' then 1 else 0 end) as sent, sum(case when e.type = 'delivered' then 1 else 0 end) as delivered,
                count(DISTINCT case when e.type = 'opened' and e.machine = 0 then e.message_id end) as opened,
                sum(case when e.type = 'bounced' then 1 else 0 end) as bounced, sum(case when e.type = 'complained' then 1 else 0 end) as complained")
            ->groupBy('p')->get()->filter(fn ($r) => (int) $r->sent > 0)->sortByDesc('sent');

        return $rows->map(fn ($r) => ['provider' => $r->p, 'name' => Signals::PROVIDERS[$r->p] ?? 'Other', 'sent' => (int) $r->sent, 'delivered' => (int) $r->delivered,
            'opened' => (int) $r->opened, 'bounced' => (int) $r->bounced, 'complained' => (int) $r->complained])->values()->all();
    }
}
