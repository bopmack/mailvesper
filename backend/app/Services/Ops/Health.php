<?php

namespace App\Services\Ops;

use App\Services\Ai\AiClient;
use App\Support\Edition;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/** One picture of the installation's health, for the System page, alerts and the deep health check. */
class Health
{
    public const SCHEDULER_STALE_SECONDS = 300;

    public const QUEUE_STALE_SECONDS = 900;

    /** @var (callable(): array{0: float|null, 1: float|null})|null free and total bytes (tests replace the real disk) */
    public static $disk = null;

    /** @return array<string, mixed> */
    public static function snapshot(): array
    {
        try {
            DB::select('select 1');
            $db = true;
        } catch (Throwable) {
            return ['database' => ['ok' => false], 'ok' => false];
        }
        $beat = State::get('heartbeat');
        $beatAge = is_string($beat) ? (int) Carbon::parse($beat)->diffInSeconds(now(), true) : null;
        $web = State::get('web_heartbeat'); // no cron job: tasks run after page visits (WebCron)
        $webAge = is_string($web) ? (int) Carbon::parse($web)->diffInSeconds(now(), true) : null;
        $cronOk = $beatAge !== null && $beatAge < self::SCHEDULER_STALE_SECONDS;
        $oldest = DB::table('jobs')->whereNull('reserved_at')->min('available_at');
        $oldestAge = $oldest ? max(0, time() - (int) $oldest) : 0;
        $failed24 = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();
        [$free, $total] = self::$disk ? (self::$disk)() : [@disk_free_space(base_path()) ?: null, @disk_total_space(base_path()) ?: null];
        $lastBackup = DB::table('ops_backups')->where('status', 'ok')->orderByDesc('id')->first();
        $lastDrill = DB::table('ops_backups')->whereNotNull('drill_at')->orderByDesc('drill_at')->first();
        $backupsOn = DB::table('ops_backups')->exists();

        $out = [
            'release' => basename(base_path()), 'php' => PHP_VERSION, 'edition' => app(Edition::class)->isPro() ? 'pro' : 'community', 'time' => now()->toIso8601ZuluString(),
            'database' => ['ok' => $db, 'driver' => DB::connection()->getDriverName()],
            'scheduler' => ['last_run' => $cronOk ? $beat : ($web ?? $beat), 'age_seconds' => $cronOk ? $beatAge : ($webAge ?? $beatAge), 'mode' => $cronOk ? 'cron' : ($webAge !== null && $webAge < 3600 ? 'visits' : null),
                'ok' => $cronOk], // after page visits is only a safety net: the cron job is needed
            'queue' => ['pending' => DB::table('jobs')->count(), 'oldest_seconds' => $oldestAge, 'failed_24h' => $failed24, 'failed_total' => DB::table('failed_jobs')->count(), 'ok' => $oldestAge < self::QUEUE_STALE_SECONDS],
            // Low: under 2 GB, or under 5% while that's also under 20 GB (5% of a big disk is still plenty).
            'disk' => ['free_bytes' => $free, 'total_bytes' => $total, 'ok' => $free === null || ! ($free < 2 * 1024 ** 3 || ($total && $free / $total < 0.05 && $free < 20 * 1024 ** 3))],
            'backup' => ['enabled' => $backupsOn, 'last_at' => $lastBackup?->finished_at, 'last_size' => $lastBackup?->size,
                'ok' => ! $backupsOn || ($lastBackup && Carbon::parse((string) $lastBackup->finished_at)->gt(now()->subHours(30)))],
            // The newest backup decides: an old failure that a later copy fixed doesn't count.
            'offsite' => ['configured' => Offsite::configured(), 'target' => Offsite::target(), 'status' => $lastBackup?->offsite_status, 'at' => $lastBackup?->offsite_at,
                'error' => $lastBackup?->offsite_error, 'ok' => ! Offsite::configured() || ($lastBackup->offsite_status ?? 'ok') === 'ok'],
            'drill' => ['last_at' => $lastDrill?->drill_at, 'status' => $lastDrill?->drill_status, 'detail' => $lastDrill?->drill_detail, 'ok' => ! $lastDrill || $lastDrill->drill_status === 'ok'],
            'errors' => ['open' => DB::table('ops_errors')->whereNull('resolved_at')->count(), 'last_24h' => DB::table('ops_errors')->where('last_seen_at', '>=', now()->subDay())->whereNull('resolved_at')->count()],
            'ai' => ['configured' => app(AiClient::class)->configured()],
        ];
        $out['ok'] = $out['scheduler']['ok'] && $out['queue']['ok'] && $out['disk']['ok'];

        return $out;
    }
}
