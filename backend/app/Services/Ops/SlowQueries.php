<?php

namespace App\Services\Ops;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Queries slower than the threshold are kept for a week (at most 200 an hour), with the page or job that ran them. */
class SlowQueries
{
    private static bool $busy = false;

    public static function record(QueryExecuted $q): void
    {
        if (self::$busy || $q->time < (float) config('sendline.slow_query_ms') || str_contains($q->sql, 'ops_slow_queries')) {
            return;
        }
        self::$busy = true;
        try {
            $key = 'slowq:'.now()->format('YmdH');
            if (Cache::add($key, 0, 3700) || Cache::increment($key) <= 200) {
                DB::table('ops_slow_queries')->insert(['sql' => mb_substr($q->sql, 0, 2000), 'ms' => (int) round($q->time),
                    'url' => request()->route() ? mb_substr(request()->method().' '.request()->path(), 0, 500) : 'console', 'created_at' => now()]);
            }
        } catch (Throwable) {
            // never let monitoring break the query that triggered it
        } finally {
            self::$busy = false;
        }
    }
}
