<?php

use App\Jobs\AdvanceJourneyRun;
use App\Jobs\PurgeWorkspace;
use App\Jobs\SendCampaignBatch;
use App\Jobs\StartCampaign;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\DeliverabilityCheck;
use App\Models\Domain;
use App\Models\Journey;
use App\Models\JourneyRun;
use App\Models\JourneyVersion;
use App\Models\Mailbox;
use App\Models\SesConnection;
use App\Models\TrackingDomain;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Ai\AiClient;
use App\Services\Campaigns\CampaignService;
use App\Services\Copilot\Copilot;
use App\Services\Deliverability\FeedbackLoop;
use App\Services\Deliverability\SetupCheck;
use App\Services\Journeys\JourneyEngine;
use App\Services\Journeys\JourneyEvents;
use App\Services\Journeys\TriggerScan;
use App\Services\Mailbox\MailboxError;
use App\Services\Mailbox\MailboxReader;
use App\Services\Mailbox\PlatformMailbox;
use App\Services\Ops\Alerts;
use App\Services\Ops\Backups;
use App\Services\Ops\Retention;
use App\Services\Ops\State;
use App\Services\Platform\Settings as PlatformSettings;
use App\Services\Ses\DomainService;
use App\Services\Ses\LimitWatch;
use App\Services\Ses\SesSetup;
use App\Services\Tracking\TrackingDomains;
use App\Support\Installation;
use App\Support\WebCron;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

// Sending limits, sandbox state and reputation for every connected SES account.
Artisan::command('ses:sync', function (SesSetup $setup) {
    $n = 0;
    SesConnection::query()->orderBy('last_synced_at')->each(function (SesConnection $c) use ($setup, &$n) {
        try {
            $setup->sync($c);
            $n++;
        } catch (Throwable $e) {
            Log::info('ses:sync failed', ['connection' => $c->id, 'error' => $e->getMessage()]);
        }
    });
    $this->info("Synced $n connection(s).");
})->purpose('Refresh SES account status for every connection');

// Domains waiting for DNS: checked every few minutes for 3 days, then once a day. Verified domains: daily.
Artisan::command('domains:check', function (DomainService $domains) {
    $due = Domain::query()
        ->where(fn ($q) => $q->where('status', '!=', 'verified')->where('created_at', '>', now()->subDays(3)))
        ->orWhere(fn ($q) => $q->where(fn ($q2) => $q2->whereNull('last_checked_at')->orWhere('last_checked_at', '<', now()->subDay())))
        ->limit(200)->get();
    $n = 0;
    foreach ($due as $d) {
        $conn = SesConnection::where('workspace_id', $d->workspace_id)->first();
        if (! $conn) {
            continue;
        }
        try {
            $domains->check($conn, $d);
            $n++;
        } catch (Throwable $e) {
            Log::info('domains:check failed', ['domain' => $d->name, 'error' => $e->getMessage()]);
        }
    }
    $this->info("Checked $n domain(s).");
})->purpose('Re-check DNS and SES verification for sending domains');

Schedule::command('ses:sync')->hourly()->withoutOverlapping();

Artisan::command('limits:check', function (LimitWatch $watch) {
    // Warnings before a sending limit stops a workspace's email (after ses:sync has fetched AWS's numbers).
    $n = 0;
    foreach (Workspace::whereNull('deleting_at')->whereNull('suspended_at')->get() as $ws) {
        $n += count($watch->run($ws));
    }
    $this->info("$n warnings sent");
})->purpose('Warn workspaces before AWS or SMTP limits stop their sending');
Schedule::command('limits:check')->hourlyAt(10)->withoutOverlapping();

Artisan::command('tracking:check', function (TrackingDomains $domains) {
    // Waiting tracking domains start working as soon as they can; active ones fall back if they stop working.
    $n = 0;
    foreach (TrackingDomain::all() as $d) {
        $n += $domains->check($d)->status === 'active' ? 1 : 0;
    }
    $this->info("$n active");
})->purpose('Check workspaces’ own link-tracking domains');
Schedule::command('tracking:check')->everyFifteenMinutes()->withoutOverlapping()->runInBackground();
// The shared host has no supervisor: workers start every minute and exit when the queue is empty. Several run side
// by side because one worker makes one SES call at a time (~3/s); the Pacer's atomic counter keeps them all under the
// account's send rate, SendMessage claims each row, and the database queue reserves jobs with row locks.
foreach (range(1, max(1, min(16, (int) config('queue.workers', 4)))) as $w) {
    Schedule::command("queue:work database --name=w$w --stop-when-empty --max-time=55 --sleep=1 --tries=1")->everyMinute()->withoutOverlapping()->runInBackground();
}
Schedule::command('domains:check')->everyFiveMinutes()->withoutOverlapping();

// Engagement score (0-100) from the last 90 days, and dormant contacts: sent 3+ emails in 120 days with no real open or click.
Artisan::command('contacts:engagement', function () {
    $since90 = now()->subDays(90)->toDateTimeString();
    $since120 = now()->subDays(120)->toDateTimeString();
    $n = 0;
    DB::table('workspaces')->orderBy('id')->pluck('id')->each(function (string $ws) use ($since90, $since120, &$n) {
        $stats = DB::table('messages')->where('workspace_id', $ws)->where('created_at', '>=', $since120)->whereNotIn('status', ['queued', 'sending', 'suppressed', 'failed', 'rejected'])
            ->selectRaw('to_email, sum(case when created_at >= ? then 1 else 0 end) as sent90, count(*) as sent120,
                sum(case when created_at >= ? and opened_at is not null then 1 else 0 end) as opened90, sum(case when created_at >= ? and clicked_at is not null then 1 else 0 end) as clicked90,
                sum(case when opened_at is not null or clicked_at is not null then 1 else 0 end) as engaged120', [$since90, $since90, $since90])
            ->groupBy('to_email')->get()->keyBy('to_email');
        Contact::where('workspace_id', $ws)->select(['id', 'email', 'last_opened_at', 'last_clicked_at', 'engagement', 'dormant'])->chunkById(1000, function ($contacts) use ($stats, &$n) {
            foreach ($contacts as $c) {
                $s = $stats[$c->email] ?? null;
                $last = max($c->last_opened_at?->getTimestamp() ?? 0, $c->last_clicked_at?->getTimestamp() ?? 0);
                $days = $last ? (time() - $last) / 86400 : null;
                $score = null;
                if ($s && (int) $s->sent90 > 0) {
                    $recency = $days === null ? 0 : ($days <= 7 ? 40 : ($days <= 30 ? 30 : ($days <= 90 ? 15 : 0)));
                    $score = (int) min(100, round($recency + 40 * ((int) $s->opened90 / (int) $s->sent90) + 20 * min(1, 3 * (int) $s->clicked90 / (int) $s->sent90)));
                }
                $dormant = $s && (int) $s->sent120 >= 3 && (int) $s->engaged120 === 0 && ($days === null || $days > 120);
                if ($score !== $c->engagement || $dormant !== $c->dormant) {
                    DB::table('contacts')->where('id', $c->id)->update(['engagement' => $score, 'dormant' => $dormant]);
                    $n++;
                }
            }
        });
    });
    $this->info("Updated $n contact(s).");
})->purpose('Recompute engagement scores and dormant flags');

Schedule::command('contacts:engagement')->dailyAt('03:15')->withoutOverlapping();

// Campaigns: start scheduled ones, pick A/B winners, and keep sends moving (local-time slots, safety net).
Artisan::command('campaigns:tick', function (CampaignService $service) {
    Campaign::where('status', 'scheduled')->where('scheduled_at', '<=', now())->each(function (Campaign $c) {
        $c->forceFill(['status' => 'sending'])->save();
        StartCampaign::dispatch($c->id);
    });
    Campaign::where('status', 'sending')->where('ab_enabled', true)->whereNull('ab_winner')->whereNotNull('started_at')->get()
        ->filter(fn ($c) => $c->started_at->copy()->addHours($c->ab_wait_hours)->lte(now()))
        ->each(function (Campaign $c) use ($service) {
            $service->decideWinner($c);
            SendCampaignBatch::dispatch($c->id);
        });
    Campaign::where('status', 'sending')->where('updated_at', '<', now()->subSeconds(30))->each(function (Campaign $c) {
        $due = DB::table('campaign_recipients')->where('campaign_id', $c->id)->where('status', 'pending')->whereNotNull('send_at')->where('send_at', '<=', now())->exists();
        $any = DB::table('campaign_recipients')->where('campaign_id', $c->id)->exists();
        $pending = DB::table('campaign_recipients')->where('campaign_id', $c->id)->where('status', 'pending')->exists();
        if ($due || ! $any || ! $pending) { // no one left to hand over: the batch job marks it sent once the queue drains
            $any ? SendCampaignBatch::dispatch($c->id) : StartCampaign::dispatch($c->id);
            $c->touch();
        }
    });
})->purpose('Start scheduled campaigns, pick A/B winners, keep sends moving');

Schedule::command('campaigns:tick')->everyMinute()->withoutOverlapping();

Artisan::command('journeys:tick', function () {
    // Waits that ended, send windows that opened, wait-for timeouts: continue those runs.
    $due = JourneyRun::query()->join('journeys', 'journeys.id', '=', 'journey_runs.journey_id')->where('journeys.status', 'active')
        ->where('journey_runs.status', 'waiting')->where('journey_runs.wake_at', '<=', now())->orderBy('journey_runs.wake_at')->limit(2000)->pluck('journey_runs.id');
    foreach ($due as $id) {
        AdvanceJourneyRun::dispatch((string) $id);
    }
    // A worker that died mid-step leaves a run "running": give it back after 10 minutes.
    $stale = JourneyRun::where('status', 'running')->where('updated_at', '<', now()->subMinutes(10))->update(['status' => 'waiting', 'wake_at' => now()]);
    $this->info(count($due).' due, '.$stale.' recovered');
})->purpose('Continue journey runs whose wait is over');

Artisan::command('journeys:dates', function (JourneyEvents $events) {
    // Date triggers (birthdays, renewals, trial ends): checked hourly, fired at the chosen hour in each contact's time zone.
    $n = 0;
    foreach (Journey::where('status', 'active')->whereNotNull('live_version_id')->get() as $j) {
        $v = JourneyVersion::find($j->live_version_id);
        foreach (collect($v->triggers ?? [])->where('trigger', 'date') as $t) {
            $field = (string) $t['field'];
            $q = Contact::where('workspace_id', $j->workspace_id)->where('status', 'subscribed');
            $field === 'created_at' ? $q->whereNotNull('created_at') : $q->whereNotNull('fields->'.$field);
            $q->chunkById(1000, function ($contacts) use ($j, $v, $t, $field, $events, &$n) {
                foreach ($contacts as $c) {
                    $tz = app(JourneyEngine::class)->timezone($c, $j);
                    $local = now()->setTimezone($tz);
                    if ($local->hour !== (int) $t['hour']) {
                        continue;
                    }
                    $raw = $field === 'created_at' ? $c->created_at?->toDateString() : (is_scalar($c->fields[$field] ?? null) ? (string) $c->fields[$field] : null);
                    try {
                        // "05-12" (no year) works for birthdays; anything else must be a real date.
                        $date = $raw ? (preg_match('/^\d{2}-\d{2}$/', $raw) ? Carbon::createFromFormat('Y-m-d', $local->year.'-'.$raw, $tz) : Carbon::parse($raw, $tz)) : null;
                    } catch (Throwable) {
                        $date = null;
                    }
                    if (! $date) {
                        continue;
                    }
                    $target = $local->copy()->startOfDay()->subDays((int) $t['offset_days']); // the date value that fires today
                    $hit = $t['yearly'] ? $date->format('m-d') === $target->format('m-d') : $date->toDateString() === $target->toDateString();
                    if ($hit && $events->enroll($j, $v, $c, (string) $t['node'], ['event' => 'sendline.date', 'properties' => [$field => $raw]], $t['node'].':'.$c->id.':'.$local->toDateString(), $t['filter'] ?? null)) {
                        $n++;
                    }
                }
            });
        }
    }
    $this->info("$n started");
})->purpose('Start journeys with date triggers');

Artisan::command('journeys:segments', function (TriggerScan $scan) {
    $this->info($scan->segments().' joins and leaves');
})->purpose('Start journeys with "joined / left a segment" triggers');

Artisan::command('journeys:scan', function (TriggerScan $scan) {
    // Hourly, like date triggers: schedules fire at the chosen hour in each contact's time zone.
    $this->info($scan->schedule().' on a schedule, '.$scan->quiet().' went quiet, '.$scan->inactive().' inactive');
})->purpose('Start journeys with schedule, went-quiet and inactive-in-app triggers');

Artisan::command('mailbox:check', function (MailboxReader $reader) {
    // Replies, late bounces and DMARC reports from each workspace's mailbox.
    $n = 0;
    foreach (Mailbox::where('enabled', true)->get() as $box) {
        try {
            $n += $reader->check($box, 40)['read'];
        } catch (MailboxError) {
            // shown on the Mailbox page; the next check tries again
        }
    }
    $this->info("$n messages read");
})->purpose('Read new mail in workspaces’ mailboxes');

Schedule::command('mailbox:check')->everyFiveMinutes()->withoutOverlapping()->runInBackground();

Artisan::command('fbl:check', fn (FeedbackLoop $loops) => $this->info($loops->collect(40).' complaints applied'))->purpose('Read the installation’s complaint feedback-loop mailbox (Yahoo, Microsoft, …)');
Schedule::command('fbl:check')->everyFiveMinutes()->withoutOverlapping()->runInBackground()->when(fn () => PlatformMailbox::configured('fbl'));

Artisan::command('checks:collect', fn (SetupCheck $checks) => $this->info($checks->collect().' setup checks finished'))->purpose('Read “Check my setup” test emails back and analyse them');
Schedule::command('checks:collect')->everyMinute()->withoutOverlapping()->runInBackground()->when(fn () => DeliverabilityCheck::where('status', 'waiting')->exists());
Schedule::command('journeys:tick')->everyMinute()->withoutOverlapping();
Schedule::command('journeys:dates')->hourly()->withoutOverlapping();
Schedule::command('journeys:segments')->everyFiveMinutes()->withoutOverlapping()->runInBackground();
Schedule::command('journeys:scan')->hourly()->withoutOverlapping()->runInBackground();

Artisan::command('copilot:scan', function (Copilot $copilot) {
    // Daily diagnosis for every workspace with an SES connection, so the score history fills in.
    $n = 0;
    foreach (SesConnection::pluck('workspace_id') as $wsId) {
        if ($ws = Workspace::find($wsId)) {
            $copilot->latest($ws, true);
            $n++;
        }
    }
    $this->info("$n workspaces checked");
})->purpose('Run Copilot\'s diagnosis for every connected workspace');

Artisan::command('platform:admin {email} {--remove}', function (string $email) {
    $u = User::where('email', User::normalizeEmail($email))->first();
    if (! $u) {
        $this->error("No account with the email $email.");

        return 1;
    }
    $ids = array_values(array_filter(explode(',', (string) PlatformSettings::get('platform.admins'))));
    $ids = $this->option('remove') ? array_values(array_diff($ids, [$u->id])) : array_values(array_unique([...$ids, $u->id]));
    PlatformSettings::set('platform.admins', $ids ? implode(',', $ids) : null);
    $this->info(($this->option('remove') ? 'Removed. ' : 'Added. ').'Platform admins: '.implode(', ', User::whereIn('id', Installation::adminIds())->pluck('email')->all()));

    return 0;
})->purpose('Make an account a platform admin (or --remove it)');

// Platform settings until the admin panel exists (it edits the same store): php artisan platform:settings [key] [value]
Artisan::command('platform:settings {key?} {value?} {--clear}', function (?string $key = null, ?string $value = null) {
    if ($key && ($value !== null || $this->option('clear'))) {
        PlatformSettings::set($key, $this->option('clear') ? null : $value);
        $this->info("$key updated");
    }
    foreach (PlatformSettings::all() as $k => $v) {
        $this->line(str_pad($k, 24).' '.(is_bool($v) ? ($v ? 'true' : 'false') : ($v ?? '(not set)')));
    }
})->purpose('Show or change platform settings (AI provider, model, keys…)');

Artisan::command('ai:ping', function (AiClient $ai) {
    $d = $ai->describe();
    $this->line("Provider {$d['provider']} · model {$d['model']} · ".($d['has_key'] ? 'key set' : 'NO KEY'));
    $r = $ai->chat('ping', [['role' => 'user', 'content' => 'Reply with the single word: ready']], ['max_tokens' => 10]);
    $this->info('Answer: '.trim($r['text']).' · tokens '.$r['usage']['prompt'].'+'.$r['usage']['completion'].($r['usage']['cost'] !== null ? ' · $'.$r['usage']['cost'] : ''));
})->purpose('Check the AI provider settings with one tiny request');

Schedule::command('copilot:scan')->dailyAt('05:00')->withoutOverlapping();

// ═══════ Operations ═══════
// A real cron job and the no-cron runner (WebCron, after page visits) keep separate heartbeats, so WebCron stands down for cron.
Artisan::command('ops:heartbeat', fn () => State::put(WebCron::$running ? 'web_heartbeat' : 'heartbeat', now()->toIso8601ZuluString()))->purpose('Record that the scheduler ran');
Artisan::command('ops:check', function () {
    $firing = Alerts::check();
    $this->line($firing ? 'Firing: '.implode(', ', $firing) : 'All good');
})->purpose('Check health and email the platform admins about problems');
Artisan::command('backup:run', function (Backups $b) {
    $r = $b->run();
    $r['status'] === 'ok' ? $this->info("Backup {$r['file']} (".number_format((int) $r['size']).' bytes)') : $this->error('Backup failed: '.$r['error']);

    return $r['status'] === 'ok' ? 0 : 1;
})->purpose('Back up the database now');
Artisan::command('backup:drill', function (Backups $b) {
    $r = $b->drill();
    ($r['drill_status'] ?? '') === 'ok' ? $this->info((string) $r['drill_detail']) : $this->error('Drill failed: '.($r['drill_detail'] ?? ''));

    return ($r['drill_status'] ?? '') === 'ok' ? 0 : 1;
})->purpose('Restore the newest backup into the drill database and check it');
Artisan::command('retention:prune', function () {
    $r = Retention::prune();
    $this->info("Cleared {$r['bodies']} email bodies, deleted {$r['raw_events']} raw events");
})->purpose('Apply the data-retention settings');

Schedule::command('ops:heartbeat')->everyMinute();
Schedule::command('ops:check')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('backup:run')->dailyAt('02:30')->withoutOverlapping()->when(fn () => in_array(config('database.connections.'.config('database.default').'.driver'), ['mysql', 'mariadb'], true));
Schedule::command('backup:drill')->weeklyOn(0, '03:30')->withoutOverlapping()->when(fn () => ! empty(config('sendline.backup_drill.database')));
Schedule::command('retention:prune')->dailyAt('04:10')->withoutOverlapping();

// Deleted workspaces: a purge that a dead worker left half done is picked up again.
Artisan::command('workspaces:purge', function () {
    $ids = DB::table('workspaces')->whereNotNull('deleting_at')->where('deleting_at', '<', now()->subMinutes(10))->pluck('id');
    foreach ($ids as $id) {
        PurgeWorkspace::dispatch((string) $id);
    }
    $this->info(count($ids).' purge(s) queued');
})->purpose('Finish removing deleted workspaces');
Schedule::command('workspaces:purge')->everyTenMinutes()->withoutOverlapping();
