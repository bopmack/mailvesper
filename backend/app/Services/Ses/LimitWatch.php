<?php

namespace App\Services\Ses;

use App\Mail\LimitAlertMail;
use App\Models\SesConnection;
use App\Models\SmtpConnection;
use App\Models\Workspace;
use App\Services\Copilot\Diagnosis;
use App\Services\Platform\Brand;
use App\Services\SafeMail;
use App\Services\Sending\Senders;
use Illuminate\Support\Facades\DB;

/**
 * Warns a workspace's owners and admins before a limit stops their email: the SES daily quota, the bounce and
 * complaint rates AWS reviews (5% / 0.1%) and pauses at (10% / 0.5%), an account under review or paused, and the
 * daily cap of their SMTP plan. One email per new or worse problem, a reminder each day while it lasts, nothing
 * once it's over. Runs hourly, after ses:sync has fetched AWS's numbers.
 */
class LimitWatch
{
    /** Bounce rate: warn below AWS's 5% review line, urgent at it. */
    public const BOUNCE = [0.04, 0.05];

    /** Complaint rate: warn below AWS's 0.1% review line, urgent at it. */
    public const COMPLAINT = [0.0008, 0.001];

    /** Share of a daily cap used. */
    public const QUOTA = [0.8, 0.95];

    /** @return list<string> the kinds emailed about this run */
    public function run(Workspace $ws): array
    {
        $now = $this->conditions($ws);
        $rows = DB::table('workspace_alerts')->where('workspace_id', $ws->id)->get()->keyBy('kind');
        $send = [];
        foreach ($now as $kind => $a) {
            $row = $rows[$kind] ?? null;
            $active = $row && $row->cleared_at === null;
            $worse = $active && $row->level === 'warn' && $a['level'] === 'urgent';
            $due = ! $active || $worse || $row->last_sent_at === null || now()->subDay()->gte($row->last_sent_at);
            DB::table('workspace_alerts')->updateOrInsert(['workspace_id' => $ws->id, 'kind' => $kind], [
                'level' => $a['level'], 'title' => mb_substr($a['title'], 0, 200), 'detail' => $a['detail'],
                'raised_at' => $active ? $row->raised_at : now(), 'cleared_at' => null, 'last_sent_at' => $due ? now() : $row->last_sent_at,
            ]);
            if ($due) {
                $send[$kind] = ['title' => $a['title'], 'detail' => $a['detail'], 'urgent' => $a['level'] === 'urgent'];
            }
        }
        // Over: quietly closed.
        DB::table('workspace_alerts')->where('workspace_id', $ws->id)->whereNull('cleared_at')->whereNotIn('kind', array_keys($now) ?: [''])->update(['cleared_at' => now()]);
        if ($send) {
            uasort($send, fn ($a, $b) => $b['urgent'] <=> $a['urgent']);
            $to = $ws->members()->wherePivotIn('role', ['owner', 'admin'])->whereNotNull('email_verified_at')->pluck('email')->all();
            foreach ($to as $email) {
                SafeMail::send($email, new LimitAlertMail($ws->name, array_values($send)));
            }
        }

        return array_keys($send);
    }

    /** @return list<array{kind: string, level: string, title: string, detail: string, raised_at: string}> what is wrong now (for the Sending page) */
    public static function active(string $workspaceId): array
    {
        return DB::table('workspace_alerts')->where('workspace_id', $workspaceId)->whereNull('cleared_at')->orderByRaw("level = 'urgent' desc")->orderBy('raised_at')
            ->get(['kind', 'level', 'title', 'detail', 'raised_at'])->map(fn ($r) => (array) $r)->all();
    }

    /** @return array<string, array{level: string, title: string, detail: string}> */
    public function conditions(Workspace $ws): array
    {
        $out = [];
        $level = fn (float $v, array $t) => $v >= $t[1] ? 'urgent' : ($v >= $t[0] ? 'warn' : null);
        // 82%, 300%, 4.2%, 0.12%: decimals only when they say something (never "300" → "3").
        $pct = fn (float $v, int $d = 1) => ($d > 0 ? rtrim(rtrim(number_format($v * 100, $d, '.', ''), '0'), '.') : number_format($v * 100, 0, '.', '')).'%';
        $method = Senders::method($ws);

        if ($method === Senders::SES && ($c = SesConnection::where('workspace_id', $ws->id)->first())) {
            if (! $c->sending_enabled) {
                $out['sending_paused'] = ['level' => 'urgent', 'title' => 'Amazon has paused sending on your SES account',
                    'detail' => 'No email goes out until AWS lets the account send again. Open the AWS console (SES → Account dashboard) to see why, fix the cause and ask AWS to review it.'];
            }
            if ($c->enforcement_status && strtoupper($c->enforcement_status) !== 'HEALTHY') {
                $out['enforcement'] = ['level' => 'urgent', 'title' => 'Your SES account is under review by AWS ('.strtolower($c->enforcement_status).')',
                    'detail' => 'AWS puts accounts under review when bounces or spam reports get too high. Sending can be paused if it isn’t fixed: clean your lists (Copilot shows what to fix) and reply to AWS’s email.'];
            }
            if ($c->max_24h_send > 0 && ($l = $level($c->sent_last_24h / $c->max_24h_send, self::QUOTA))) {
                $out['quota'] = ['level' => $l, 'title' => 'You’ve used '.$pct($c->sent_last_24h / $c->max_24h_send, 0).' of your SES daily quota',
                    'detail' => number_format($c->sent_last_24h).' of '.number_format($c->max_24h_send).' emails in the last 24 hours. At the limit, '.Brand::name().' holds emails and retries them as the quota frees up.'
                        .($c->production_access ? ' To send more, ask AWS for a higher quota (SES → Account dashboard → Request a sending quota increase).' : ' Your account is still in the SES sandbox: ask AWS for production access to lift the 200-a-day limit.')];
            }
            $bounce = $c->bounce_rate;
            $complaint = $c->complaint_rate;
            $source = 'AWS';
        } else {
            $bounce = $complaint = null;
            $source = Brand::name();
        }
        // Without AWS's numbers (SMTP, or no reputation data yet), our own over the last week, once there is enough.
        if ($bounce === null || $complaint === null) {
            $t = DB::table('messages')->where('workspace_id', $ws->id)->where('sent_at', '>=', now()->subDays(7))->whereNotIn('source', ['test', 'inbox_test'])
                ->where('to_email', 'not like', Diagnosis::SIMULATOR)->selectRaw("count(*) sent, sum(case when status = 'bounced' then 1 else 0 end) bounced, sum(case when status = 'complained' then 1 else 0 end) complained")->first();
            if ((int) ($t->sent ?? 0) >= Diagnosis::MIN_SAMPLE) {
                $bounce ??= (int) $t->bounced / (int) $t->sent;
                $complaint ??= (int) $t->complained / (int) $t->sent;
                $source = $method === Senders::SES ? Brand::name().' (AWS has no numbers yet)' : Brand::name();
            }
        }
        if ($bounce !== null && ($l = $level((float) $bounce, self::BOUNCE))) {
            $out['bounce'] = ['level' => $l, 'title' => 'Your bounce rate is '.$pct((float) $bounce),
                'detail' => "Measured by $source. AWS reviews accounts at 5% and can pause them at 10%. Stop sending to old or bought lists, and check the imports Copilot points to: hard bounces are already suppressed."];
        }
        if ($complaint !== null && ($l = $level((float) $complaint, self::COMPLAINT))) {
            $out['complaint'] = ['level' => $l, 'title' => 'Spam reports are at '.$pct((float) $complaint, 2),
                'detail' => "Measured by $source. AWS reviews accounts at 0.1% and can pause them at 0.5%; Gmail starts filtering at 0.3%. Send only to people who asked, make unsubscribing easy, and slow down to dormant contacts."];
        }

        if ($method === Senders::SMTP && ($s = SmtpConnection::where('workspace_id', $ws->id)->first()) && $s->max_per_day) {
            $today = DB::table('messages')->where('workspace_id', $ws->id)->where('sent_at', '>=', now()->startOfDay())->count();
            if ($l = $level($today / $s->max_per_day, self::QUOTA)) {
                $out['smtp_daily'] = ['level' => $l, 'title' => 'You’ve used '.$pct($today / $s->max_per_day, 0).' of today’s SMTP limit',
                    'detail' => number_format($today).' of '.number_format($s->max_per_day).' emails today (your limit under Sending → SMTP server). The rest wait until tomorrow; raise the limit if your provider allows more.'];
            }
        }

        return $out;
    }
}
