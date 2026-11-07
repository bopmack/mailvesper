<?php

namespace App\Services\Ops;

use App\Services\Platform\Settings;
use Illuminate\Support\Facades\DB;

/**
 * How long email history is kept, set by the platform admin. Off (keep everything) unless set:
 * - retention.body_days: after this, an email's HTML and text are cleared; who, when, status and events stay, so
 *   reports and suppressions are unchanged.
 * - retention.raw_event_days: after this, the raw notifications from Amazon SNS are deleted (the parsed activity stays).
 */
class Retention
{
    /** @return array{bodies: int, raw_events: int} */
    public static function prune(): array
    {
        $out = ['bodies' => 0, 'raw_events' => 0];
        $bodyDays = (int) Settings::get('retention.body_days');
        if ($bodyDays > 0) {
            $cut = now()->subDays($bodyDays)->toDateTimeString();
            do {
                $ids = DB::table('messages')->where('created_at', '<', $cut)->where(fn ($w) => $w->whereNotNull('html')->orWhereNotNull('text'))->limit(1000)->pluck('id');
                $out['bodies'] += $ids->isEmpty() ? 0 : DB::table('messages')->whereIn('id', $ids)->update(['html' => null, 'text' => null]);
            } while ($ids->count() === 1000);
        }
        $rawDays = (int) Settings::get('retention.raw_event_days');
        if ($rawDays > 0) {
            do {
                $n = DB::table('ses_events')->where('created_at', '<', now()->subDays($rawDays)->toDateTimeString())->limit(2000)->delete();
                $out['raw_events'] += $n;
            } while ($n === 2000);
        }
        // Sign-ups never confirmed: their link expired after a week; the address and answers go after a month.
        DB::table('form_submissions')->where('status', 'pending')->where('created_at', '<', now()->subDays(30))->delete();
        // Setup checks: the latest per domain is what reports use; old ones go after six months.
        DB::table('deliverability_checks')->where('created_at', '<', now()->subDays(180))->delete();
        // Stripe / Shopify event ids are kept long enough to catch their retries (Stripe retries for 3 days).
        DB::table('integration_events')->where('created_at', '<', now()->subDays(30))->delete();
        // Monitoring data: slow queries for a week, fixed errors for a month.
        DB::table('ops_slow_queries')->where('created_at', '<', now()->subDays(7))->delete();
        DB::table('ops_errors')->whereNotNull('resolved_at')->where('resolved_at', '<', now()->subDays(30))->delete();

        return $out;
    }
}
