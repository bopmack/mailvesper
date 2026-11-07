<?php

namespace App\Support;

use App\Services\Ops\State;
use Cron\CronExpression;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Background work without a cron job, for hosts where nobody sets one up. After a response has gone to the browser,
 * at most once a minute, this runs the scheduled tasks that came due since it last ran (a daily task missed at 03:00
 * runs on the next visit) and works through the queue for a while, inside the PHP that served the request.
 *
 * It stands down as soon as a real cron job runs `schedule:run` (that's more punctual, and the docs say so).
 */
final class WebCron
{
    /** True while tasks run here, so the heartbeat can tell a real cron job from this. */
    public static bool $running = false;

    private const EVERY_SECONDS = 60;

    private const CRON_FRESH_SECONDS = 180;

    private const QUEUE_SECONDS = 25;

    public static function maybeRun(): void
    {
        if (! config('sendline.web_cron', true) || self::$running) {
            return;
        }
        try {
            if (! WebInstaller::installed() || self::cronIsRunning() || self::ranRecently()) {
                return;
            }
            $lock = Cache::lock('mailvesper:web-cron', 300);
            if (! $lock->get()) {
                return;
            }
            try {
                self::run();
            } finally {
                $lock->release();
            }
        } catch (Throwable $e) {
            Log::warning('Background tasks (no cron) failed', ['error' => mb_substr($e->getMessage(), 0, 300)]);
        }
    }

    /**
     * Runs the due tasks and a queue worker now.
     *
     * @return list<string> what ran (for tests)
     */
    public static function run(?Carbon $now = null): array
    {
        $now ??= now();
        @ignore_user_abort(true);
        @set_time_limit(180);
        self::$running = true;
        $ran = [];
        try {
            $since = ($at = State::get('web_cron_at')) ? Carbon::parse((string) $at) : $now->copy()->subMinute();
            State::put('web_cron_at', $now->toIso8601ZuluString());
            app(Kernel::class)->bootstrap(); // loads routes/console.php, where the schedule lives
            foreach (app(Schedule::class)->events() as $event) {
                $cmd = self::artisanCommand($event);
                if ($cmd !== null && str_starts_with($cmd, 'queue:work')) {
                    continue; // one worker below instead of several background ones
                }
                if (! self::dueSince($event, $since, $now) || ! $event->filtersPass(app())) {
                    continue;
                }
                try {
                    if ($event instanceof CallbackEvent) {
                        $event->run(app()); // takes and releases its own overlap lock
                        $ran[] = $event->getSummaryForDisplay();
                    } elseif ($cmd !== null && (! $event->withoutOverlapping || $event->mutex->create($event))) {
                        try {
                            Artisan::call($cmd);
                            $ran[] = $cmd;
                        } finally {
                            if ($event->withoutOverlapping) {
                                $event->mutex->forget($event);
                            }
                        }
                    }
                } catch (Throwable $e) {
                    report($e);
                }
            }
            Artisan::call('queue:work', ['connection' => 'database', '--name' => 'web', '--stop-when-empty' => true, '--max-time' => self::QUEUE_SECONDS, '--sleep' => 1, '--tries' => 1]);
            $ran[] = 'queue:work';
        } finally {
            self::$running = false;
        }

        return $ran;
    }

    /** A real cron job ran the scheduler in the last few minutes. */
    public static function cronIsRunning(): bool
    {
        $beat = State::get('heartbeat');

        return is_string($beat) && Carbon::parse($beat)->gt(now()->subSeconds(self::CRON_FRESH_SECONDS));
    }

    private static function ranRecently(): bool
    {
        $at = State::get('web_cron_at');

        return is_string($at) && Carbon::parse($at)->gt(now()->subSeconds(self::EVERY_SECONDS));
    }

    /** Was the task due at any minute after $since, up to $now? */
    private static function dueSince(Event $event, Carbon $since, Carbon $now): bool
    {
        $tz = $event->timezone ?: config('app.timezone');
        $prev = (new CronExpression($event->expression))->getPreviousRunDate($now->copy()->setTimezone($tz)->toDateTimeString(), 0, true, (string) $tz);

        return $prev->getTimestamp() > $since->getTimestamp() && $prev->getTimestamp() <= $now->getTimestamp();
    }

    /** "queue:work database …" from the "'php' 'artisan' queue:work database …" a scheduled command runs. */
    private static function artisanCommand(Event $event): ?string
    {
        return is_string($event->command) && preg_match("/['\"]?artisan['\"]?\s+(.+)$/s", $event->command, $m) ? trim($m[1]) : null;
    }
}
