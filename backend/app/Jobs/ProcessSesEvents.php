<?php

namespace App\Jobs;

use App\Services\Ses\EventProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Applies the SES notices the webhook kept (deliveries, bounces, opens…), oldest first. The webhook only stores them,
 * so Amazon always gets a quick answer, however busy the server is; nothing is lost and each notice is applied once.
 * A notice that fails is tried again later (2, 4, 8, 16 minutes), then set aside with its error.
 */
class ProcessSesEvents implements ShouldQueue
{
    use Dispatchable, Queueable;

    public const MAX_ATTEMPTS = 5;

    public const BATCH = 200;

    /** How long one run keeps draining before it hands over to the next (queue workers stop at 55 s). */
    public const RUN_SECONDS = 40;

    public int $tries = 1;

    /** Queues a run unless one is already waiting: one job per burst of notices, not one per notice. */
    public static function kick(): void
    {
        if (Cache::add('ses-events:queued', 1, 60)) {
            self::dispatch();
        }
    }

    /** Notices waiting to be applied now (failed ones wait for their retry time). */
    public static function ready(): Builder
    {
        return DB::table('ses_events')->whereNull('processed_at')->where('attempts', '<', self::MAX_ATTEMPTS)
            ->where(fn (Builder $q) => $q->whereNull('retry_at')->orWhere('retry_at', '<=', now()));
    }

    public function handle(EventProcessor $processor): void
    {
        Cache::forget('ses-events:queued'); // notices arriving from now on queue the next run
        $lock = Cache::lock('ses-events:drain', self::RUN_SECONDS + 60);
        if (! $lock->get()) {
            return; // another worker is draining; it picks these up
        }
        try {
            $until = microtime(true) + self::RUN_SECONDS;
            do {
                $rows = self::ready()->orderBy('id')->limit(self::BATCH)->get(['id', 'workspace_id', 'payload', 'attempts']);
                foreach ($rows as $r) {
                    $this->applyOne($processor, $r);
                }
            } while ($rows->count() === self::BATCH && microtime(true) < $until);
        } finally {
            $lock->release();
        }
        if (self::ready()->exists()) {
            self::kick();
        }
    }

    private function applyOne(EventProcessor $processor, object $r): void
    {
        try {
            $event = json_decode((string) $r->payload, true);
            if (is_array($event)) {
                $processor->apply((string) $r->workspace_id, $event, (int) $r->id);
            }
            DB::table('ses_events')->where('id', $r->id)->update(['processed_at' => now(), 'error' => null, 'retry_at' => null]);
        } catch (Throwable $e) {
            report($e);
            $n = (int) $r->attempts + 1;
            DB::table('ses_events')->where('id', $r->id)->update([
                'attempts' => $n, 'error' => mb_substr($e->getMessage(), 0, 255),
                'retry_at' => $n < self::MAX_ATTEMPTS ? now()->addMinutes(2 ** $n) : null,
                'processed_at' => $n >= self::MAX_ATTEMPTS ? now() : null, // set aside, with its error kept
            ]);
        }
    }
}
