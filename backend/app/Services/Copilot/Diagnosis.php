<?php

namespace App\Services\Copilot;

use App\Models\ContactImport;
use App\Models\Domain;
use App\Models\SenderIdentity;
use App\Models\SesConnection;
use App\Models\SmtpConnection;
use App\Models\Workspace;
use App\Services\Platform\Brand;
use App\Services\Sending\Senders;
use App\Services\Ses\DnsLookup;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

/**
 * Copilot's findings, computed from the workspace's real signals only: its messages and events, contacts and
 * imports, domains, live DNS and the SES account. Thresholds are the published ones (Gmail, Yahoo, Microsoft,
 * AWS SES). No verdict without enough data. The AI never decides a finding; it only explains these.
 *
 * Score = 100 minus each finding's weight; a finding's weight is also its "estimated gain" when fixed.
 */
class Diagnosis
{
    public const DAYS = 30;

    public const MIN_SAMPLE = 100;

    public const SOURCES = [
        'google' => ['Gmail sender guidelines', 'https://support.google.com/a/answer/81126'],
        'yahoo' => ['Yahoo sender best practices', 'https://senders.yahooinc.com/best-practices/'],
        'microsoft' => ['Outlook.com requirements for high-volume senders', 'https://techcommunity.microsoft.com/blog/microsoftdefenderforoffice365blog/strengthening-email-ecosystem-outlook%E2%80%99s-new-requirements-for-high%E2%80%90volume-senders/4399730'],
        'ses' => ['Amazon SES enforcement FAQ', 'https://docs.aws.amazon.com/ses/latest/dg/faqs-enforcement.html'],
        'dmarc' => ['DMARC overview (dmarc.org)', 'https://dmarc.org/overview/'],
        'spamhaus' => ['Spamhaus Domain Blocklist', 'https://www.spamhaus.org/blocklists/domain-blocklist/'],
    ];

    /** Domain blocklists, queried as <domain>.<zone>. Each answers in its own codes, and refuses some resolvers. */
    public const BLOCKLISTS = ['dbl.spamhaus.org' => 'Spamhaus DBL', 'multi.surbl.org' => 'SURBL', 'multi.uribl.com' => 'URIBL'];

    /** The list's own always-listed test entry: if it doesn't come back listed, the list refuses us and isn't checked. */
    public const BLOCKLIST_TESTS = ['dbl.spamhaus.org' => 'dbltest.com', 'multi.surbl.org' => 'test.surbl.org', 'multi.uribl.com' => 'test.uribl.com'];

    /** Simulator sends (SES mailbox simulator) are tests: AWS leaves them out of reputation, and so do we. */
    public const SIMULATOR = '%@simulator.amazonses.com';

    public function __construct(private DnsLookup $dns) {}

    /**
     * @return array{score: int|null, findings: list<array<string, mixed>>, passed: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public function run(Workspace $ws): array
    {
        $since = now()->subDays(self::DAYS)->toDateTimeString();
        $findings = [];
        $passed = [];
        $add = function (array $f) use (&$findings) {
            $findings[] = $f + ['gain' => 0, 'fix' => null, 'sources' => [], 'evidence' => []];
        };
        $src = fn (string ...$k) => array_map(fn ($x) => ['label' => self::SOURCES[$x][0], 'url' => self::SOURCES[$x][1]], $k);

        // ── Sending numbers (tests excluded) ──
        $m = DB::table('messages')->where('workspace_id', $ws->id)->where('created_at', '>=', $since)->whereNotIn('source', ['test', 'inbox_test'])->where('to_email', 'not like', self::SIMULATOR);
        $t = (clone $m)->selectRaw("sum(case when sent_at is not null then 1 else 0 end) sent, sum(case when delivered_at is not null then 1 else 0 end) delivered,
            sum(case when status = 'bounced' then 1 else 0 end) bounced, sum(case when status = 'complained' then 1 else 0 end) complained,
            sum(case when opened_at is not null then 1 else 0 end) opened, sum(case when clicked_at is not null then 1 else 0 end) clicked,
            sum(machine_open_count) machine_opens, sum(case when machine_open_count > 0 and opened_at is null then 1 else 0 end) machine_only")->first();
        $sent = (int) ($t->sent ?? 0);
        $delivered = (int) ($t->delivered ?? 0);
        $bounced = (int) ($t->bounced ?? 0);
        $complained = (int) ($t->complained ?? 0);
        $enough = $sent >= self::MIN_SAMPLE;
        $rate = fn (int $a, int $b) => $b ? $a / $b : 0.0;
        $summary = ['days' => self::DAYS, 'sent' => $sent, 'delivered' => $delivered, 'bounced' => $bounced, 'complained' => $complained,
            'opened' => (int) ($t->opened ?? 0), 'clicked' => (int) ($t->clicked ?? 0), 'machine_only' => (int) ($t->machine_only ?? 0),
            'bounce_rate' => $rate($bounced, $sent), 'complaint_rate' => $rate($complained, $delivered), 'open_rate' => $rate((int) ($t->opened ?? 0), $delivered), 'enough_data' => $enough];

        $smtp = Senders::method($ws) === Senders::SMTP;
        if ($smtp) {
            $conn = SmtpConnection::where('workspace_id', $ws->id)->first(); // a score needs something to send with
            $this->smtpChecks($ws, $add, $passed);
        } else {
            // ── Amazon SES account ──
            $conn = SesConnection::where('workspace_id', $ws->id)->first();
            if (! $conn) {
                $add(['id' => 'ses_missing', 'severity' => 'bad', 'area' => 'Amazon SES', 'title' => 'Amazon SES isn’t connected',
                    'why' => 'Nothing can be sent until you connect your Amazon SES account.', 'fix' => ['text' => 'Connect SES (one command in AWS CloudShell).', 'actions' => [['type' => 'link', 'label' => 'Connect Amazon SES', 'href' => '#/ses/connect']]]]);
            } else {
                if (! $conn->production_access) {
                    $add(['id' => 'ses_sandbox', 'severity' => 'bad', 'area' => 'Amazon SES · '.$conn->region, 'title' => 'Your SES account is still in the sandbox',
                        'why' => 'In the sandbox, SES only delivers to addresses you have verified, and caps you at 200 emails a day. Real customers don’t get your email.',
                        'fix' => ['text' => 'Request production access in the SES console. '.Brand::name().' drafts the request for you.', 'actions' => [['type' => 'link', 'label' => 'Open SES page', 'href' => '#/ses']]], 'sources' => $src('ses')]);
                }
                if ($conn->enforcement_status && strtoupper($conn->enforcement_status) !== 'HEALTHY') {
                    $add(['id' => 'ses_enforcement', 'severity' => 'bad', 'area' => 'Amazon SES · '.$conn->region, 'title' => 'AWS has put your account under review ('.strtolower($conn->enforcement_status).')',
                        'why' => 'AWS reviews accounts whose bounce or complaint rates get too high. If it isn’t fixed, AWS can pause sending for the whole account.', 'gain' => 15,
                        'evidence' => ['type' => 'stats', 'rows' => [['AWS bounce rate', $conn->bounce_rate], ['AWS complaint rate', $conn->complaint_rate]]], 'sources' => $src('ses')]);
                }
                if ($conn->max_24h_send > 0 && $conn->sent_last_24h >= 0.8 * $conn->max_24h_send) {
                    $add(['id' => 'ses_quota', 'severity' => 'warn', 'area' => 'Amazon SES · quota', 'title' => 'You used '.round($conn->sent_last_24h / $conn->max_24h_send * 100).'% of your daily SES quota',
                        'why' => 'Emails over the quota wait until the next day ('.Brand::name().' retries them). Ask AWS for a higher quota before a big send.',
                        'evidence' => ['type' => 'stats', 'rows' => [['Sent in the last 24 h', (int) $conn->sent_last_24h], ['Daily quota', (int) $conn->max_24h_send]]], 'sources' => $src('ses')]);
                } elseif ($conn->max_24h_send > 0) {
                    $passed[] = ['id' => 'ses_quota', 'title' => 'SES quota', 'detail' => number_format((int) $conn->sent_last_24h).' of '.number_format((int) $conn->max_24h_send).' used today'];
                }
            }

        }

        // ── Authentication, per domain that sends (or could) ──
        $used = (clone $m)->selectRaw('from_email, count(*) n')->groupBy('from_email')->pluck('n', 'from_email')->reduce(function ($acc, $n, $email) {
            $d = mb_strtolower(substr((string) strrchr((string) $email, '@'), 1));
            $acc[$d] = ($acc[$d] ?? 0) + $n;

            return $acc;
        }, []);
        $domains = $smtp ? new EloquentCollection : Domain::where('workspace_id', $ws->id)->get(); // SMTP senders are checked in smtpChecks()
        $lists = [];
        $refused = [];
        if ($domains->where('status', 'verified')->isNotEmpty()) {
            foreach (self::BLOCKLISTS as $zone => $name) {
                $probe = $this->dns->lookup('A', self::BLOCKLIST_TESTS[$zone].'.'.$zone)[0] ?? null;
                $probe && self::listed($zone, $probe) ? $lists[$zone] = $name : $refused[] = $name;
            }
        }
        $authOk = true;
        foreach ($domains as $d) {
            $volume = (int) ($used[$d->name] ?? 0);
            if ($d->status !== 'verified') {
                if ($volume) {
                    $authOk = false;
                }

                continue; // pending domains are covered on the Domains page
            }
            if (strtoupper((string) $d->dkim_status) !== 'SUCCESS') {
                $authOk = false;
                $add(['id' => 'dkim:'.$d->name, 'severity' => 'bad', 'area' => 'Authentication · '.$d->name, 'title' => 'DKIM isn’t working for '.$d->name,
                    'why' => 'Without a valid DKIM signature, Gmail, Yahoo and Outlook can’t confirm the email is really from you, and DMARC fails.', 'gain' => 8,
                    'evidence' => ['type' => 'stats', 'rows' => [['DKIM status at AWS', strtolower((string) $d->dkim_status) ?: 'unknown'], ['Emails from this domain (30 days)', $volume]]],
                    'fix' => ['text' => 'Publish the three DKIM CNAME records.', 'actions' => [['type' => 'link', 'label' => 'DNS records', 'href' => '#/domains/view?id='.$d->id]]], 'sources' => $src('google', 'yahoo')]);
            }
            if ($d->mail_from_domain && strtoupper((string) $d->mail_from_status) !== 'SUCCESS') {
                $add(['id' => 'mailfrom:'.$d->name, 'severity' => 'warn', 'area' => 'Authentication · '.$d->name, 'title' => 'Custom MAIL FROM isn’t set up for '.$d->name,
                    'why' => 'SPF then passes for amazonses.com instead of your domain, so only DKIM aligns for DMARC. It works, but one broken record and DMARC fails.', 'gain' => 1,
                    'fix' => ['text' => 'Publish the MX and SPF records for '.$d->mail_from_domain.'.', 'actions' => [['type' => 'link', 'label' => 'DNS records', 'href' => '#/domains/view?id='.$d->id]]], 'sources' => $src('dmarc')]);
            }
            $dmarc = $this->dmarc($d->name);
            if (! $dmarc) {
                $authOk = false;
                $record = 'v=DMARC1; p=none; rua=mailto:dmarc@'.$d->name;
                $add(['id' => 'dmarc_missing:'.$d->name, 'severity' => 'bad', 'area' => 'Authentication · '.$d->name, 'title' => 'No DMARC record on '.$d->name,
                    'why' => 'Gmail and Yahoo require DMARC from bulk senders (5,000+ a day to them), and Outlook.com since May 2025. Without it, email is more likely to be rejected or filtered.', 'gain' => 6,
                    'evidence' => ['type' => 'dns', 'host' => '_dmarc.'.$d->name, 'found' => null, 'proposed' => $record],
                    'fix' => ['text' => 'Publish this TXT record at _dmarc.'.$d->name.'. It starts in monitoring mode (p=none), so it can’t block your own mail.', 'actions' => $this->dmarcActions($d, $record)],
                    'sources' => $src('google', 'yahoo', 'microsoft')]);
            } else {
                $policy = $this->tag($dmarc, 'p') ?? 'none';
                if ($policy === 'none' && strtoupper((string) $d->dkim_status) === 'SUCCESS') {
                    $record = $this->withPolicy($dmarc, 'quarantine');
                    $reports = $this->dmarcReports($ws->id, $d->name);
                    $add(['id' => 'dmarc_none:'.$d->name, 'severity' => 'warn', 'area' => 'Authentication · '.$d->name, 'title' => 'DMARC on '.$d->name.' only monitors (p=none)',
                        'why' => 'With p=none, mailbox providers take no action when someone forges your domain, and some filters trust p=none senders less. DKIM already passes for your '.Brand::name().' email, so moving to quarantine is safe for it'.($reports ? '' : '; check that any other service sending as '.$d->name.' (your helpdesk, billing…) signs with DKIM first').'.',
                        'gain' => 3, 'evidence' => ['type' => 'dns', 'host' => '_dmarc.'.$d->name, 'found' => $dmarc, 'proposed' => $record, 'reports' => $reports],
                        'fix' => ['text' => 'Replace the record at _dmarc.'.$d->name.' with this one.', 'actions' => $this->dmarcActions($d, $record)],
                        'sources' => $src('dmarc', 'google')]);
                } else {
                    $passed[] = ['id' => 'dmarc:'.$d->name, 'title' => 'DMARC on '.$d->name, 'detail' => 'p='.$policy];
                }
            }
            // Blocklists for the sending domain (only the lists that answer us).
            foreach ($lists as $zone => $name) {
                $answer = $this->dns->lookup('A', $d->name.'.'.$zone)[0] ?? null;
                if ($answer && self::listed($zone, $answer)) {
                    $add(['id' => 'blocklist:'.$d->name.':'.$zone, 'severity' => 'bad', 'area' => 'Reputation · '.$d->name, 'title' => $d->name.' is listed on '.$name,
                        'why' => 'Filters that use '.$name.' reject or junk email that contains or comes from a listed domain.', 'gain' => 10,
                        'evidence' => ['type' => 'stats', 'rows' => [['Lookup', $d->name.'.'.$zone], ['Answer', $answer]]],
                        'fix' => ['text' => 'Find the cause (often a compromised form or a bought list), fix it, then request removal from '.$name.'.', 'actions' => []], 'sources' => $zone === 'dbl.spamhaus.org' ? $src('spamhaus') : []]);
                }
            }
        }
        if ($domains->where('status', 'verified')->isNotEmpty() && $authOk) {
            $passed[] = ['id' => 'auth', 'title' => 'SPF, DKIM and DMARC', 'detail' => 'Every verified domain signs with DKIM and publishes DMARC'];
        }
        $passed[] = ['id' => 'unsubscribe', 'title' => 'One-click unsubscribe', 'detail' => 'Every marketing email has List-Unsubscribe and RFC 8058 one-click'];
        if ($lists && ! collect($findings)->contains(fn ($f) => str_starts_with($f['id'], 'blocklist:'))) {
            $passed[] = ['id' => 'blocklists', 'title' => 'Domain blocklists', 'detail' => 'Not listed on '.implode(', ', $lists).($refused ? '. '.implode(' and ', $refused).' refused our lookup, so '.(count($refused) === 1 ? 'it wasn’t' : 'they weren’t').' checked' : '')];
        } elseif ($refused && ! $lists) {
            $passed[] = ['id' => 'blocklists', 'title' => 'Domain blocklists not checked', 'detail' => implode(', ', $refused).' refused lookups from our DNS resolver'];
        }

        // ── Reputation: bounces and complaints against the published limits ──
        if ($enough) {
            $br = $summary['bounce_rate'];
            if ($br >= 0.02) {
                $bad = $br >= 0.05;
                $add(['id' => 'bounce_rate', 'severity' => $bad ? 'bad' : 'warn', 'area' => 'Reputation', 'title' => 'Bounce rate is '.round($br * 100, 1).'%',
                    'why' => 'AWS reviews accounts at 5% and can pause sending at 10%. Keep it under 2%: bounces mean old, mistyped or bought addresses.', 'gain' => $bad ? 8 : 3,
                    'evidence' => ['type' => 'gauge', 'metric' => 'bounce', 'value' => $br, 'max' => 0.12, 'zones' => [[0, 0.02, 'ok'], [0.02, 0.05, 'warn'], [0.05, 0.1, 'bad'], [0.1, 0.12, 'bad']], 'marks' => [[0.05, 'AWS review'], [0.1, 'AWS pause']], 'n' => [$bounced, $sent]],
                    'fix' => ['text' => 'Bounced addresses are already suppressed. Find where the rest came from (see the list finding) and stop importing old lists.', 'actions' => [['type' => 'link', 'label' => 'Suppressions', 'href' => '#/suppressions']]], 'sources' => $src('ses')]);
            }
            $cr = $summary['complaint_rate'];
            if ($cr >= 0.001) {
                $bad = $cr >= 0.003;
                $add(['id' => 'complaint_rate', 'severity' => $bad ? 'bad' : 'warn', 'area' => 'Reputation', 'title' => 'Spam reports are at '.round($cr * 100, 2).'%',
                    'why' => 'Gmail and Yahoo want user-reported spam under 0.1% and treat 0.3% as the hard limit; AWS reviews accounts at 0.1% and can pause at 0.5%.', 'gain' => $bad ? 10 : 3,
                    'evidence' => ['type' => 'gauge', 'metric' => 'complaint', 'value' => $cr, 'max' => 0.006, 'zones' => [[0, 0.001, 'ok'], [0.001, 0.003, 'warn'], [0.003, 0.006, 'bad']], 'marks' => [[0.003, 'Gmail limit'], [0.005, 'AWS pause']], 'n' => [$complained, $delivered]],
                    'fix' => ['text' => 'Send only to people who asked for it, make the unsubscribe link easy to find, and leave out dormant contacts.', 'actions' => []], 'sources' => $src('google', 'yahoo', 'ses')]);
            }
            if ($br < 0.02 && $cr < 0.001) {
                $passed[] = ['id' => 'reputation', 'title' => 'Bounces and spam reports', 'detail' => 'Bounces '.round($br * 100, 1).'% · spam '.round($cr * 100, 2).'% (limits 5% / 0.3%)'];
            }
        }

        // ── Deferrals per mailbox provider ──
        $prov = (clone $m)->whereNotNull('sent_at')->selectRaw('provider, count(*) n, sum(case when status = \'bounced\' then 1 else 0 end) b, sum(case when delivered_at is not null then 1 else 0 end) d, sum(case when opened_at is not null then 1 else 0 end) o')
            ->groupBy('provider')->get()->keyBy(fn (\stdClass $r) => (string) ($r->provider ?: 'other'));
        $delayed = DB::table('message_events')->join('messages', 'messages.id', '=', 'message_events.message_id')->where('messages.workspace_id', $ws->id)
            ->where('messages.created_at', '>=', $since)->where('messages.to_email', 'not like', self::SIMULATOR)->where('message_events.type', 'delayed')->selectRaw('messages.provider, count(distinct messages.id) n')->groupBy('messages.provider')->pluck('n', 'provider');
        foreach ($prov as $p => $r) {
            $dn = (int) ($delayed[$p] ?? 0);
            if ($r->n >= 50 && $dn / $r->n >= 0.01) {
                $codes = DB::table('message_events')->join('messages', 'messages.id', '=', 'message_events.message_id')->where('messages.workspace_id', $ws->id)->where('messages.provider', $p)
                    ->where('messages.created_at', '>=', $since)->where('messages.to_email', 'not like', self::SIMULATOR)->where('message_events.type', 'delayed')->orderByDesc('message_events.id')->limit(50)->pluck('message_events.data')
                    ->map(fn ($d) => mb_substr((string) (json_decode((string) $d, true)['diagnostic'] ?? ''), 0, 90))->filter()->countBy()->sortDesc()->take(3)->all();
                $isMs = $p === 'microsoft';
                $gentle = (bool) (($ws->settings ?? [])['gentle_microsoft'] ?? false);
                $add(['id' => 'deferrals:'.$p, 'severity' => 'warn', 'area' => 'Mailbox providers · '.$this->providerName($p), 'title' => $this->providerName($p).' slowed down '.round($dn / $r->n * 100, 1).'% of your email',
                    'why' => $this->providerName($p).' answered with temporary “try later” replies. SES retries, so it usually arrives, but late. Rate limits like this mean the provider is unsure about your sending.', 'gain' => 2,
                    'evidence' => ['type' => 'codes', 'rows' => $codes, 'n' => [$dn, (int) $r->n]],
                    'fix' => $isMs ? ['text' => $gentle ? 'Gentle Outlook pacing is on: campaigns send to Microsoft addresses three times more slowly.' : 'Send to Outlook, Hotmail and Microsoft 365 addresses more gradually in campaigns.', 'actions' => [['type' => 'toggle', 'label' => 'Gentle Outlook pacing', 'setting' => 'gentle_microsoft', 'on' => $gentle]]]
                        : ['text' => 'Keep volume steady and avoid sudden big sends to '.$this->providerName($p).'.', 'actions' => []],
                    'sources' => $isMs ? $src('microsoft') : []]);
            }
        }

        // ── List quality: which import the hard bounces came from ──
        $bySource = DB::table('messages')->join('contacts', function ($j) {
            $j->on('contacts.workspace_id', '=', 'messages.workspace_id')->on('contacts.email', '=', 'messages.to_email');
        })->where('messages.workspace_id', $ws->id)->where('messages.created_at', '>=', $since)->where('messages.to_email', 'not like', self::SIMULATOR)->where('messages.status', 'bounced')
            ->selectRaw('coalesce(contacts.import_id, contacts.source) k, count(*) n')->groupBy('k')->pluck('n', 'k');
        $total = (int) $bySource->sum();
        if ($total >= 5) {
            $top = $bySource->sortDesc()->keys()->first();
            $topN = (int) $bySource[$top];
            $import = strlen((string) $top) === 26 ? ContactImport::where('workspace_id', $ws->id)->find($top) : null;
            if ($import && $topN / $total >= 0.5) {
                $fromFile = DB::table('contacts')->where('workspace_id', $ws->id)->where('import_id', $import->id)->count();
                $add(['id' => 'import_bounces:'.$import->id, 'severity' => 'warn', 'area' => 'List quality · '.$import->filename, 'title' => $topN.' of '.$total.' hard bounces came from one import',
                    'why' => 'Most of your bounces trace back to “'.$import->filename.'”. Files like this often hold old, mistyped or bought addresses, and the rest of the file is likely to bounce too.', 'gain' => 2,
                    'evidence' => ['type' => 'bars', 'rows' => $bySource->sortDesc()->take(4)->map(fn ($n, $k) => [strlen((string) $k) === 26 ? (ContactImport::find($k)->filename ?? 'An import') : ucfirst((string) $k), (int) $n])->values()->all()],
                    'fix' => ['text' => 'The bounced addresses are suppressed. Review the other '.number_format($fromFile - $topN).' contacts from this file before your next campaign, and leave out the ones that never opened.', 'actions' => [['type' => 'link', 'label' => 'Contacts', 'href' => '#/contacts']]]]);
            }
        }

        // ── Engagement by provider: a provider far below the others usually means its spam folder ──
        $rates = $prov->filter(fn ($r) => $r->d >= 200)->map(fn ($r) => $r->o / max(1, $r->d));
        foreach ($rates as $p => $r) {
            $others = $rates->except($p);
            $avg = $others->isNotEmpty() ? $others->avg() : null;
            if ($avg !== null && $avg >= 0.1 && $r < 0.4 * $avg) {
                $add(['id' => 'engagement:'.$p, 'severity' => 'warn', 'area' => 'Mailbox providers · '.$this->providerName($p), 'title' => $this->providerName($p).' readers open far less than everyone else',
                    'why' => 'Real opens at '.$this->providerName($p).' are '.round($r * 100, 1).'%, against '.round($avg * 100, 1).'% elsewhere. A gap this big usually means your email lands in spam or a tab there.', 'gain' => 2,
                    'evidence' => ['type' => 'bars', 'rows' => $rates->map(fn ($x, $k) => [$this->providerName($k), round($x * 100, 1)])->values()->all(), 'unit' => '%'],
                    'fix' => ['text' => 'Run an inbox placement test to see the folder, and check that DMARC passes.', 'actions' => [['type' => 'link', 'label' => 'Inbox tests', 'href' => '#/inbox']]], 'sources' => $src('google')]);
            }
        }

        // ── Sending to dormant contacts ──
        $mk = DB::table('messages')->join('contacts', function ($j) {
            $j->on('contacts.workspace_id', '=', 'messages.workspace_id')->on('contacts.email', '=', 'messages.to_email');
        })->where('messages.workspace_id', $ws->id)->where('messages.created_at', '>=', $since)->where('messages.to_email', 'not like', self::SIMULATOR)->where('messages.type', 'marketing')->whereNotNull('messages.sent_at')
            ->selectRaw('count(*) n, sum(case when contacts.dormant then 1 else 0 end) dormant')->first();
        if ($mk && $mk->n >= 200 && $mk->dormant / $mk->n >= 0.2) {
            $add(['id' => 'dormant', 'severity' => 'warn', 'area' => 'Engagement', 'title' => round($mk->dormant / $mk->n * 100).'% of your marketing email goes to dormant contacts',
                'why' => 'Dormant contacts haven’t really opened or clicked in 120 days. Mailbox providers watch engagement, and old addresses can turn into spam traps.', 'gain' => 2,
                'evidence' => ['type' => 'stats', 'rows' => [['Marketing emails (30 days)', (int) $mk->n], ['To dormant contacts', (int) $mk->dormant]]],
                'fix' => ['text' => 'Keep “Leave out dormant contacts” on in campaigns, or run a win-back journey first.', 'actions' => [['type' => 'link', 'label' => 'Campaigns', 'href' => '#/campaigns']]], 'sources' => $src('google')]);
        }

        // ── Information: machine opens ──
        if ($enough && $summary['machine_only'] > 0) {
            $passed[] = ['id' => 'machine_opens', 'title' => 'Apple and scanner opens are left out', 'detail' => number_format($summary['machine_only']).' emails were only “opened” by machines; your open rate excludes them'];
        }

        usort($findings, fn ($a, $b) => [$a['severity'] === 'bad' ? 0 : ($a['severity'] === 'warn' ? 1 : 2), -$a['gain']] <=> [$b['severity'] === 'bad' ? 0 : ($b['severity'] === 'warn' ? 1 : 2), -$b['gain']]);
        $penalty = array_sum(array_column($findings, 'gain'));
        $score = ($enough || $findings) && $conn ? max(0, 100 - $penalty) : null;

        return ['score' => $score, 'findings' => $findings, 'passed' => $passed, 'summary' => $summary];
    }

    /**
     * Copy, plus one-click publishing when the domain's DNS is in the customer's Route 53.
     *
     * @return list<array<string, string>>
     */
    private function dmarcActions(Domain $d, string $record): array
    {
        $actions = [['type' => 'copy', 'label' => 'Copy record', 'value' => $record]];
        if ($d->route53_zone_id) {
            $actions[] = ['type' => 'fix', 'label' => 'Publish with Route 53', 'fix' => 'dmarc', 'domain' => $d->name, 'value' => $record];
        }

        return $actions;
    }

    /** Does this answer mean "listed"? (Each list has its own codes; some mean "query refused".) */
    public static function listed(string $zone, string $answer): bool
    {
        if (! preg_match('/^127\.(\d+)\.(\d+)\.(\d+)$/', $answer, $m)) {
            return false;
        }

        return match ($zone) {
            'dbl.spamhaus.org' => $m[1] === '0' && $m[2] === '1' && (int) $m[3] >= 2 && (int) $m[3] <= 255, // 127.0.1.x; 127.255.255.x = error
            default => $m[1] === '0' && $m[2] === '0' && ((int) $m[3] & ~1) > 0, // URIBL / SURBL bitmasks; 127.0.0.1 = refused
        };
    }

    /** The DMARC record for a domain, or for its parent (organisational domain) when the subdomain has none. */
    /**
     * SMTP sending: is the server reachable, are senders verified, do verified domains publish SPF and DMARC. SMTP
     * doesn't report deliveries, later bounces or complaints, so those checks can't run; say so instead of guessing.
     *
     * @param  callable(array<string, mixed>): void  $add
     * @param  list<array<string, string>>  $passed
     */
    private function smtpChecks(Workspace $ws, callable $add, array &$passed): void
    {
        $c = SmtpConnection::where('workspace_id', $ws->id)->first();
        if ($c && $c->last_error) {
            $add(['id' => 'smtp_error', 'severity' => 'bad', 'area' => 'SMTP · '.$c->host, 'title' => 'Your SMTP server is refusing '.Brand::name(),
                'why' => $c->last_error, 'fix' => ['text' => 'Check the host, port, username and password, then test the connection.', 'actions' => [['type' => 'link', 'label' => 'SMTP settings', 'href' => '#/ses/smtp']]]]);
        } elseif ($c) {
            $passed[] = ['id' => 'smtp', 'title' => 'SMTP server', 'detail' => $c->host.':'.$c->port.' accepted the last connection test'];
        }
        $senders = SenderIdentity::where('workspace_id', $ws->id)->where('status', 'verified')->get();
        if ($senders->isEmpty()) {
            $add(['id' => 'senders_missing', 'severity' => 'bad', 'area' => 'SMTP · senders', 'title' => 'No verified sender yet',
                'why' => Brand::name().' only sends from domains or addresses you have proven are yours.', 'fix' => ['text' => 'Verify your domain (one TXT record) or a single address.', 'actions' => [['type' => 'link', 'label' => 'Verify a sender', 'href' => '#/ses/smtp']]]]);
        }
        foreach ($senders->where('kind', 'domain') as $d) {
            $spf = collect($this->dns->lookup('TXT', $d->value))->first(fn ($t) => str_starts_with(strtolower(trim((string) $t, '" ')), 'v=spf1'));
            if (! $spf) {
                $add(['id' => 'spf_missing:'.$d->value, 'severity' => 'warn', 'area' => 'Authentication · '.$d->value, 'title' => 'No SPF record on '.$d->value,
                    'why' => 'SPF tells mailbox providers which servers may send as '.$d->value.'. Without it, mail from your SMTP server is more likely to be filtered.', 'gain' => 4,
                    'fix' => ['text' => 'Ask your SMTP provider for its SPF include and publish it as a TXT record on '.$d->value.'.', 'actions' => []]]);
            }
            if (! $this->dmarc($d->value)) {
                $add(['id' => 'dmarc_missing:'.$d->value, 'severity' => 'bad', 'area' => 'Authentication · '.$d->value, 'title' => 'No DMARC record on '.$d->value,
                    'why' => 'Gmail and Yahoo require DMARC from bulk senders, and Outlook.com since May 2025.', 'gain' => 6,
                    'evidence' => ['type' => 'dns', 'host' => '_dmarc.'.$d->value, 'found' => null, 'proposed' => 'v=DMARC1; p=none; rua=mailto:dmarc@'.$d->value],
                    'fix' => ['text' => 'Publish this TXT record at _dmarc.'.$d->value.'. It only monitors (p=none), so it can’t block your own mail.', 'actions' => []]]);
            }
        }
        $add(['id' => 'smtp_limits', 'severity' => 'info', 'area' => 'SMTP', 'title' => 'Some checks need Amazon SES',
            'why' => 'An SMTP server only says it accepted an email. Deliveries, bounces that arrive later and spam complaints aren’t reported back, so those rates aren’t measured. Opens, clicks, unsubscribes and immediate rejections are.']);
    }

    public function dmarc(string $domain): ?string
    {
        foreach ([$domain, substr_count($domain, '.') >= 2 ? substr($domain, strpos($domain, '.') + 1) : null] as $d) {
            if (! $d) {
                continue;
            }
            foreach ($this->dns->lookup('TXT', '_dmarc.'.$d) as $txt) {
                if (str_starts_with(strtolower(str_replace(' ', '', $txt)), 'v=dmarc1')) {
                    return trim($txt);
                }
            }
        }

        return null;
    }

    public function tag(string $record, string $tag): ?string
    {
        foreach (explode(';', $record) as $part) {
            [$k, $v] = array_pad(array_map('trim', explode('=', $part, 2)), 2, null);
            if (strtolower((string) $k) === $tag) {
                return strtolower((string) $v);
            }
        }

        return null;
    }

    /** Same record, new policy; everything else (rua, pct…) kept. */
    public function withPolicy(string $record, string $policy): string
    {
        $parts = array_values(array_filter(array_map('trim', explode(';', $record)), fn ($p) => $p !== ''));
        $out = [];
        $done = false;
        foreach ($parts as $p) {
            if (str_starts_with(strtolower($p), 'p=')) {
                $out[] = 'p='.$policy;
                $done = true;
            } else {
                $out[] = $p;
            }
        }
        if (! $done) {
            array_splice($out, 1, 0, ['p='.$policy]);
        }

        return implode('; ', $out).';';
    }

    /** @return array{messages: int, passed: int, sources: int}|null DMARC aggregate data for the last 30 days, when reports exist */
    private function dmarcReports(string $workspaceId, string $domain): ?array
    {
        $r = DB::table('dmarc_reports')->where('workspace_id', $workspaceId)->where('domain', $domain)->where('date_end', '>=', now()->subDays(self::DAYS)->toDateTimeString())
            ->selectRaw('sum(messages) m, sum(passed) p, count(*) c')->first();
        if (! $r || ! $r->m) {
            return null;
        }
        $sources = DB::table('dmarc_records')->join('dmarc_reports', 'dmarc_reports.id', '=', 'dmarc_records.report_id')->where('dmarc_reports.workspace_id', $workspaceId)
            ->where('dmarc_reports.domain', $domain)->distinct()->count('dmarc_records.source_ip');

        return ['messages' => (int) $r->m, 'passed' => (int) $r->p, 'sources' => $sources];
    }

    public function providerName(string $p): string
    {
        return ['google' => 'Gmail', 'microsoft' => 'Outlook', 'yahoo' => 'Yahoo', 'apple' => 'iCloud', 'proton' => 'Proton'][$p] ?? 'Other providers';
    }
}
