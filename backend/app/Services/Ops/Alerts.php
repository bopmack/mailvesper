<?php

namespace App\Services\Ops;

use App\Mail\OpsAlertMail;
use App\Models\User;
use App\Services\Platform\Brand;
use App\Services\Platform\Settings;
use App\Services\SafeMail;
use App\Support\Installation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Turns the health snapshot into emails a person will act on: one when something goes wrong, a reminder every
 * 6 hours while it stays wrong, and one when it's fixed. Recipients: ops.alert_emails, or the platform admins.
 */
class Alerts
{
    public const REMIND_HOURS = 6;

    /** @return list<string> rules that are firing now */
    public static function check(): array
    {
        $h = Health::snapshot();
        $since = Carbon::parse((string) (State::get('alerts:last_check') ?? now()->subMinutes(10)->toIso8601ZuluString()));
        State::put('alerts:last_check', now()->toIso8601ZuluString());
        $rules = [];
        if (! ($h['database']['ok'] ?? false)) {
            return []; // nothing else can be checked; the deep health check (external monitor) reports it
        }
        if (! $h['scheduler']['ok']) {
            $rules['scheduler'] = ['The scheduler isn’t running', ['It last ran '.($h['scheduler']['last_run'] ? Carbon::parse($h['scheduler']['last_run'])->diffForHumans() : 'never').'. Campaigns, automations and queued emails are waiting.', 'Check the server’s cron entry for “php artisan schedule:run”.']];
        }
        if (! $h['queue']['ok']) {
            $rules['queue'] = ['Emails and jobs are backing up', [$h['queue']['pending'].' jobs waiting; the oldest for '.intdiv((int) $h['queue']['oldest_seconds'], 60).' minutes.', 'The queue workers may have stopped, or SES is refusing sends.']];
        }
        $failedNew = DB::table('failed_jobs')->where('failed_at', '>=', $since)->count();
        if ($failedNew > 0) {
            $rules['failed_jobs'] = ['Background jobs failed', ["$failedNew job(s) failed since the last check (".$h['queue']['failed_24h'].' in the last 24 hours).', 'See Platform → System for the errors.']];
        }
        $newErrors = DB::table('ops_errors')->whereNull('resolved_at')->where('first_seen_at', '>=', $since)->orderByDesc('last_seen_at')->limit(5)->get(['class', 'message', 'file', 'line']);
        if ($newErrors->isNotEmpty()) {
            $rules['errors'] = ['New errors', $newErrors->map(fn ($e) => class_basename((string) $e->class).': '.mb_substr((string) $e->message, 0, 160).' ('.$e->file.':'.$e->line.')')->all()];
        }
        if (! $h['disk']['ok']) {
            $rules['disk'] = ['The server is running out of disk space', [round(((int) $h['disk']['free_bytes']) / 1024 ** 3, 1).' GB free.']];
        }
        if (! $h['backup']['ok']) {
            $rules['backup'] = ['No recent backup', ['The last successful backup was '.($h['backup']['last_at'] ? Carbon::parse((string) $h['backup']['last_at'])->diffForHumans() : 'never').'.']];
        }
        if (! $h['offsite']['ok']) {
            $rules['offsite'] = ['The off-site backup copy failed', ['Copying the latest backup to '.$h['offsite']['target'].' failed: '.$h['offsite']['error'], 'The backup itself is safe on this server. Check Platform → Settings → Off-site backups, then use “Test off-site” on the System page.']];
        }
        if (! $h['drill']['ok']) {
            $rules['drill'] = ['The backup restore drill failed', [(string) $h['drill']['detail']]];
        }

        $known = ['scheduler', 'queue', 'failed_jobs', 'errors', 'disk', 'backup', 'offsite', 'drill'];
        foreach ($known as $rule) {
            $state = (array) (State::get("alert:$rule") ?? []);
            $firing = isset($rules[$rule]);
            // One-off events (new failures, new errors) are sent each time they happen; conditions are reminded every 6 hours.
            $event = in_array($rule, ['failed_jobs', 'errors'], true);
            if ($firing && ($event || empty($state['firing']) || Carbon::parse((string) ($state['notified_at'] ?? '2000-01-01'))->lt(now()->subHours(self::REMIND_HOURS)))) {
                self::send('['.Brand::name().'] '.$rules[$rule][0], $rules[$rule][1]);
                State::put("alert:$rule", ['firing' => ! $event, 'since' => $state['since'] ?? now()->toIso8601ZuluString(), 'notified_at' => now()->toIso8601ZuluString()]);
            } elseif (! $firing && ! empty($state['firing'])) {
                self::send('['.Brand::name().'] Fixed: '.self::title($rule), ['This is back to normal (it started '.Carbon::parse((string) $state['since'])->diffForHumans().').']);
                State::forget("alert:$rule");
            }
        }

        return array_keys($rules);
    }

    /** @param list<string> $lines */
    public static function send(string $subject, array $lines): int
    {
        $sent = 0;
        foreach (self::recipients() as $to) {
            $sent += SafeMail::send($to, new OpsAlertMail($subject, $lines)) ? 1 : 0;
        }

        return $sent;
    }

    /** @return list<string> */
    public static function recipients(): array
    {
        $named = array_values(array_filter(array_map('trim', explode(',', (string) Settings::get('ops.alert_emails'))), fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));

        return $named ?: User::whereIn('id', Installation::adminIds())->pluck('email')->all();
    }

    private static function title(string $rule): string
    {
        return ['scheduler' => 'the scheduler is running again', 'queue' => 'the queue has caught up', 'disk' => 'disk space', 'backup' => 'backups are running', 'offsite' => 'off-site backup copies', 'drill' => 'the restore drill passes'][$rule] ?? $rule;
    }
}
