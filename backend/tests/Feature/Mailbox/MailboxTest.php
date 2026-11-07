<?php

use App\Models\Contact;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\Suppression;
use App\Services\Contacts\ContactService;
use App\Services\Deliverability\FeedbackLoop;
use App\Services\Journeys\JourneyEvents;
use App\Services\Mailbox\ImapClient;
use App\Services\Mailbox\MailboxConnection;
use App\Services\Mailbox\MailboxError;
use App\Services\Mailbox\MailboxReader;
use App\Services\Platform\Settings;
use App\Services\Sending\Mailer;
use App\Services\Ses\DnsLookup;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeAws;
use Tests\Support\FakeDns;

/** An in-memory mailbox: UIDs → raw messages. */
class FakeMailbox implements MailboxConnection
{
    /** @var array<int, string> */
    public array $mail = [];

    public int $validity = 7;

    public ?string $fail = null;

    public bool $changed = false; // anything that would modify the mailbox

    public function select(string $folder): array
    {
        if ($this->fail) {
            throw new MailboxError($this->fail);
        }

        return ['validity' => $this->validity, 'next' => ($this->mail ? max(array_keys($this->mail)) : 0) + 1];
    }

    public function uidsFrom(int $from): array
    {
        return array_values(array_filter(array_keys($this->mail), fn ($u) => $u >= $from));
    }

    public function searchSubject(string $text): array
    {
        return array_values(array_keys(array_filter($this->mail, fn ($raw) => preg_match('/^Subject:[^\r\n]*'.preg_quote($text, '/').'/mi', $raw))));
    }

    public function fetch(int $uid, int $maxBytes = ImapClient::MAX_BYTES): ?string
    {
        return $this->mail[$uid] ?? null;
    }

    public function close(): void {}

    public function add(string $raw): void
    {
        $this->mail[($this->mail ? max(array_keys($this->mail)) : 100) + 1] = $raw;
    }
}

beforeEach(function () {
    FakeAws::install();
    $this->app->instance(DnsLookup::class, new FakeDns);
    [$this->ws] = sesWorkspace($this, ['member']);
    sesConnect($this, $this->ws)->assertOk();
    $this->box = new FakeMailbox;
    ImapClient::$factory = fn () => $this->box;
    JourneyEvents::flush();
});

afterEach(function () {
    ImapClient::$factory = null;
});

function mbUrl($test, string $path = ''): string
{
    return "/v1/workspaces/{$test->ws->id}/mailbox".($path ? "/$path" : '');
}

function sendTo($test, string $to, string $subject = 'Spring sale'): Message
{
    $mailer = app(Mailer::class);
    $m = $mailer->submit($test->ws, [$mailer->validate($test->ws, ['from' => 'news@acme.com', 'to' => $to, 'subject' => $subject, 'html' => '<p>Hi</p>', 'type' => 'marketing'])], 'api')[0];

    return $m->refresh();
}

function rfc822(array $headers, string $body): string
{
    $h = '';
    foreach ($headers + ['Date' => 'Sat, 31 Oct 2026 10:00:00 +0000', 'MIME-Version' => '1.0'] as $k => $v) {
        $h .= "$k: $v\r\n";
    }

    return $h."\r\n".$body;
}

function dsn(string $recipient, string $status, string $originalHeaders): string
{
    $b = 'BOUNDARY42';

    return rfc822(['From' => 'Mail Delivery System <MAILER-DAEMON@mx.example.net>', 'To' => 'news@acme.com', 'Subject' => 'Undelivered Mail Returned to Sender',
        'Content-Type' => "multipart/report; report-type=delivery-status; boundary=\"$b\""],
        "--$b\r\nContent-Type: text/plain\r\n\r\nThis is the mail system. Your message could not be delivered.\r\n"
        ."--$b\r\nContent-Type: message/delivery-status\r\n\r\nReporting-MTA: dns; mx.example.net\r\n\r\nFinal-Recipient: rfc822; $recipient\r\nAction: failed\r\nStatus: $status\r\nDiagnostic-Code: smtp; 550 5.1.1 <$recipient>: Recipient address rejected:\r\n User unknown in virtual mailbox table\r\n"
        ."--$b\r\nContent-Type: text/rfc822-headers\r\n\r\n$originalHeaders\r\n--$b--\r\n");
}

it('saves only a mailbox that works, starting from now, and keeps it to owners and admins', function () {
    $this->box->add(rfc822(['From' => 'old@example.com', 'Subject' => 'Old mail'], 'old'));
    $this->box->fail = 'The mail server rejected the username or password. Gmail, Outlook and Yahoo need an app password.';
    $this->putJson(mbUrl($this), ['host' => 'imap.acme.com', 'username' => 'news@acme.com', 'password' => 'x'])->assertStatus(422)
        ->assertJsonPath('error.message', 'The mail server rejected the username or password. Gmail, Outlook and Yahoo need an app password.');
    expect(Mailbox::count())->toBe(0);
    $this->box->fail = null;
    $this->putJson(mbUrl($this), ['host' => 'imap.acme.com', 'encryption' => 'tls', 'username' => 'news@acme.com', 'password' => 'app-password'])->assertOk()
        ->assertJsonPath('mailbox.port', 143)->assertJsonPath('mailbox.folder', 'INBOX')->assertJsonPath('mailbox.read_replies', true)->assertJsonMissingPath('mailbox.password');
    expect(Mailbox::first()->last_uid)->toBe(101)->and(Mailbox::first()->getRawOriginal('password'))->not->toBe('app-password'); // old mail is skipped; encrypted

    switchTo($this, 'member@example.com');
    $this->putJson(mbUrl($this), ['read_dmarc' => false])->assertForbidden();
    $this->getJson(mbUrl($this))->assertOk()->assertJsonPath('mailbox.host', 'imap.acme.com');
});

it('applies bounces that arrive later by email, and suppresses hard ones', function () {
    $this->putJson(mbUrl($this), ['host' => 'imap.acme.com', 'username' => 'news@acme.com', 'password' => 'p'])->assertOk();
    $a = sendTo($this, 'gone@example.org');
    $later = sendTo($this, 'gone@example.org', 'Second email'); // the bounce names the first one by its header
    $b = sendTo($this, 'full@example.org');
    $c = sendTo($this, 'fine@example.org');
    $this->box->add(dsn('gone@example.org', '5.1.1', "From: news@acme.com\r\nTo: gone@example.org\r\nX-Sendline-Tracking: {$a->tracking_code}\r\nSubject: Spring sale"));
    $this->box->add(dsn('full@example.org', '4.2.2', 'Subject: no tracking header here'));  // matched by recipient; soft
    $this->box->add(str_replace('Action: failed', 'Action: delayed', dsn('fine@example.org', '4.4.1', ''))); // only a delay notice

    $found = app(MailboxReader::class)->check(Mailbox::first());
    expect($found)->toBe(['read' => 3, 'replies' => 0, 'bounces' => 2, 'complaints' => 0, 'reports' => 0])
        ->and($a->tracking_code)->toContain('-')->and($a->refresh()->status)->toBe('bounced')->and($later->refresh()->status)->not->toBe('bounced')->and($b->refresh()->status)->toBe('bounced')->and($c->refresh()->status)->not->toBe('bounced')
        ->and(Suppression::check($this->ws->id, ['gone@example.org', 'full@example.org']))->toBe(['gone@example.org' => 'hard_bounce'])
        ->and(json_decode(DB::table('message_events')->where('message_id', $a->id)->where('type', 'bounced')->value('data'), true)['diagnostic'])
        ->toContain('Recipient address rejected: User unknown');
    expect(app(MailboxReader::class)->check(Mailbox::first())['read'])->toBe(0); // nothing is read twice
});

it('reads replies, starts journeys with them, and ignores automatic answers', function () {
    $this->putJson(mbUrl($this), ['host' => 'imap.acme.com', 'username' => 'news@acme.com', 'password' => 'p'])->assertOk();
    $amaka = app(ContactService::class)->upsert($this->ws, ['email' => 'amaka@example.com'], 'api');
    app(ContactService::class)->upsert($this->ws, ['email' => 'tunde@example.com'], 'api');
    $jid = $this->postJson("/v1/workspaces/{$this->ws->id}/journeys", ['name' => 'Replies'])->json('journey.id');
    $this->patchJson("/v1/workspaces/{$this->ws->id}/journeys/$jid", ['graph' => ['nodes' => [['id' => 't1', 'type' => 'trigger', 'config' => ['trigger' => 'email_replied']],
        ['id' => 'k1', 'type' => 'tag', 'config' => ['tag' => 'replied']]], 'edges' => [['from' => 't1', 'to' => 'k1']]], 'settings' => ['reentry' => 'always']])->assertOk();
    $this->postJson("/v1/workspaces/{$this->ws->id}/journeys/$jid/publish")->assertOk();
    JourneyEvents::flush();

    $m1 = sendTo($this, 'amaka@example.com', 'Your order');
    $m2 = sendTo($this, 'tunde@example.com', 'Spring sale');
    $this->box->add(rfc822(['From' => 'Amaka <Amaka@Example.com>', 'To' => 'news@acme.com', 'Subject' => 'Re: Re: about my order', 'In-Reply-To' => "<{$m1->ses_message_id}@email.amazonses.com>",
        'Content-Type' => 'text/plain; charset=utf-8'], "Yes please, send it Friday.\r\n\r\nOn Sat, 31 Oct 2026, Acme wrote:\r\n> Your order is ready"));
    $this->box->add(rfc822(['From' => 'tunde@example.com', 'To' => 'news@acme.com', 'Subject' => 'RE: AW: Spring sale', 'Content-Type' => 'text/plain'], 'How much is shipping?')); // by subject
    $m3 = sendTo($this, 'amaka@example.com', 'Invoice');
    $m3->forceFill(['ses_message_id' => "{$m3->id}@acme.com"])->save(); // as SMTP sending stores it
    $this->box->add(rfc822(['From' => 'amaka@example.com', 'To' => 'news@acme.com', 'Subject' => 'A question', 'In-Reply-To' => "<{$m3->id}@acme.com>", 'References' => "<{$m3->id}@acme.com>"], 'Different subject, same thread'));
    $this->box->add(rfc822(['From' => 'tunde@example.com', 'To' => 'news@acme.com', 'Subject' => 'Out of Office: Spring sale', 'Auto-Submitted' => 'auto-replied', 'In-Reply-To' => "<{$m2->ses_message_id}@email.amazonses.com>"], 'Away until Monday'));
    $this->box->add(rfc822(['From' => 'stranger@example.com', 'To' => 'news@acme.com', 'Subject' => 'Hello'], 'Unrelated'));
    $this->box->add(rfc822(['From' => 'news@acme.com', 'To' => 'news@acme.com', 'Subject' => 'Re: Spring sale'], 'Our own copy'));

    expect(app(MailboxReader::class)->check(Mailbox::first()))->toBe(['read' => 6, 'replies' => 3, 'bounces' => 0, 'complaints' => 0, 'reports' => 0]);
    $e = DB::table('message_events')->where('message_id', $m1->id)->where('type', 'replied')->first();
    expect(json_decode($e->data, true))->toBe(['from' => 'amaka@example.com', 'subject' => 'Re: Re: about my order', 'snippet' => 'Yes please, send it Friday.'])
        ->and(DB::table('message_events')->where('message_id', $m2->id)->where('type', 'replied')->count())->toBe(1)
        ->and(DB::table('message_events')->where('message_id', $m3->id)->where('type', 'replied')->count())->toBe(1)
        ->and(DB::table('journey_runs')->where('journey_id', $jid)->count())->toBe(3)
        ->and(DB::table('contact_events')->where('contact_id', $amaka->id)->where('name', 'email.replied')->value('properties'))->toContain($m1->id);
    $this->getJson(mbUrl($this))->assertJsonPath('mailbox.replies', 3);
});

it('adds DMARC reports that arrive by email', function () {
    $this->putJson(mbUrl($this), ['host' => 'imap.acme.com', 'username' => 'dmarc@acme.com', 'password' => 'p'])->assertOk();
    $xml = '<?xml version="1.0"?><feedback><report_metadata><org_name>google.com</org_name><report_id>mb-1</report_id><date_range><begin>'.now()->subDay()->timestamp.'</begin><end>'.now()->timestamp.'</end></date_range></report_metadata>'
        .'<policy_published><domain>acme.com</domain><p>none</p></policy_published><record><row><source_ip>54.240.1.1</source_ip><count>12</count><policy_evaluated><disposition>none</disposition><dkim>pass</dkim><spf>pass</spf></policy_evaluated></row><identifiers><header_from>acme.com</header_from></identifiers><auth_results><dkim><domain>acme.com</domain><result>pass</result></dkim></auth_results></record></feedback>';
    $b = 'ZZ';
    $this->box->add(rfc822(['From' => 'noreply-dmarc-support@google.com', 'To' => 'dmarc@acme.com', 'Subject' => 'Report domain: acme.com Submitter: google.com Report-ID: mb-1',
        'Content-Type' => "multipart/mixed; boundary=\"$b\""], "--$b\r\nContent-Type: text/plain\r\n\r\nReport attached\r\n--$b\r\nContent-Type: application/gzip; name=\"google.com!acme.com!1!2.xml.gz\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"google.com!acme.com!1!2.xml.gz\"\r\n\r\n"
        .chunk_split(base64_encode(gzencode($xml)))."--$b--\r\n"));

    expect(app(MailboxReader::class)->check(Mailbox::first()))->toMatchArray(['reports' => 1]);
    expect($this->getJson("/v1/workspaces/{$this->ws->id}/copilot/dmarc")->assertOk()->json('dmarc')['acme.com']['messages'])->toBe(12);
});

it('waits for a reply in a journey, and records a connection problem without losing its place', function () {
    $this->putJson(mbUrl($this), ['host' => 'imap.acme.com', 'username' => 'news@acme.com', 'password' => 'p'])->assertOk();
    $this->box->fail = 'Couldn’t reach imap.acme.com on port 993. Check the host and port.';
    $this->postJson(mbUrl($this, 'check'))->assertStatus(422)->assertJsonPath('error.code', 'mailbox_failed');
    expect(Mailbox::first()->last_error)->toBe('Couldn’t reach imap.acme.com on port 993. Check the host and port.')->and(Mailbox::first()->last_uid)->toBe(0);
    $this->box->fail = null;
    $this->postJson(mbUrl($this, 'check'))->assertOk()->assertJsonPath('mailbox.last_error', null);
    $this->artisan('mailbox:check')->assertSuccessful();
    expect(Contact::count())->toBe(0); // nothing to read; nothing invented
});

/** A feedback-loop report (RFC 5965) about an email whose headers were $originalHeaders. */
function arf(string $type, string $originalHeaders, string $from = 'Yahoo! Mail AntiSpam Feedback <feedback@arf.mail.yahoo.com>', string $ua = 'Yahoo!-Mail-Feedback/2.0'): string
{
    $b = 'ARF42';

    return rfc822(['From' => $from, 'To' => 'fbl@sendline.test', 'Subject' => 'FW: Spring sale',
        'Content-Type' => "multipart/report; report-type=feedback-report; boundary=\"$b\""],
        "--$b\r\nContent-Type: text/plain\r\n\r\nThis is an email abuse report for an email message received from IP 192.0.2.1.\r\n"
        ."--$b\r\nContent-Type: message/feedback-report\r\n\r\nFeedback-Type: $type\r\nUser-Agent: $ua\r\nVersion: 1\r\nOriginal-Rcpt-To: <redacted>\r\nArrival-Date: Sat, 31 Oct 2026 10:00:00 +0000\r\n\r\n"
        ."--$b\r\nContent-Type: message/rfc822\r\n\r\n$originalHeaders\r\n\r\nHi\r\n--$b--\r\n");
}

it('applies feedback-loop complaints that arrive in the mailbox, never DMARC failure reports, and each once', function () {
    $this->putJson(mbUrl($this), ['host' => 'imap.acme.com', 'username' => 'news@acme.com', 'password' => 'p'])->assertOk();
    $a = sendTo($this, 'angry@yahoo.com');
    $b = sendTo($this, 'fine@yahoo.com');
    $this->box->add(arf('abuse', "From: news@acme.com\r\nTo: redacted@yahoo.com\r\nX-Sendline-Tracking: {$a->tracking_code}\r\nSubject: Spring sale"));
    $this->box->add(arf('abuse', "From: news@acme.com\r\nX-Sendline-Tracking: {$a->tracking_code}\r\nSubject: Spring sale")); // a second copy
    $this->box->add(arf('auth-failure', "From: news@acme.com\r\nX-Sendline-Tracking: {$b->tracking_code}\r\nSubject: Spring sale", 'dmarc@google.com', 'Google DMARC'));
    $this->box->add(arf('abuse', "From: other@elsewhere.com\r\nX-Sendline-Tracking: zzzzzzzzzz\r\nSubject: Not ours"));

    $found = $this->postJson(mbUrl($this, 'check'))->assertOk()->json('found');
    expect($found)->toMatchArray(['read' => 4, 'complaints' => 1, 'replies' => 0, 'bounces' => 0])
        ->and($a->refresh()->status)->toBe('complained')->and($b->refresh()->status)->not->toBe('complained')
        ->and(Suppression::check($this->ws->id, ['angry@yahoo.com', 'fine@yahoo.com']))->toBe(['angry@yahoo.com' => 'complaint'])
        ->and(json_decode((string) DB::table('message_events')->where('message_id', $a->id)->where('type', 'complained')->value('data'), true))->toBe(['feedback_type' => 'abuse', 'reported_by' => 'Yahoo'])
        ->and(Mailbox::first()->complaints)->toBe(1);
    $this->getJson("/v1/workspaces/{$this->ws->id}/delivery-reports")->assertOk()->assertJsonPath('complaints.by', [['source' => 'Feedback loop · Yahoo', 'count' => 1]]);
});

it('reads the installation’s complaint mailbox for every workspace, by tracking header or Message-ID', function () {
    foreach (['fbl.address' => 'fbl@sendline.test', 'fbl.imap_host' => 'imap.sendline.test', 'fbl.imap_password' => 'p'] as $k => $v) {
        Settings::set($k, $v);
    }
    $loops = app(FeedbackLoop::class);
    $a = sendTo($this, 'a@aol.com');
    $b = sendTo($this, 'b@hotmail.com');
    $c = sendTo($this, 'c@example.com');
    expect($loops->collect())->toBe(0); // the first check starts from now
    $this->box->add(arf('abuse', "From: news@acme.com\r\nX-Sendline-Tracking: {$a->tracking_code}"));
    // Older Microsoft style: a note with the original attached, no ARF part. The original keeps SES's Message-ID.
    $this->box->add(rfc822(['From' => 'staff@hotmail.com', 'Subject' => 'complaint about message', 'Content-Type' => 'multipart/mixed; boundary="J1"'],
        "--J1\r\nContent-Type: text/plain\r\n\r\nA user marked this as junk.\r\n--J1\r\nContent-Type: message/rfc822\r\n\r\nFrom: news@acme.com\r\nMessage-ID: <{$b->ses_message_id}@eu-west-1.amazonses.com>\r\nSubject: Spring sale\r\n\r\nHi\r\n--J1--\r\n"));
    // A bounce of the report itself carries an original too: not a complaint.
    $this->box->add(dsn('c@example.com', '5.1.1', "From: news@acme.com\r\nX-Sendline-Tracking: {$c->tracking_code}"));

    expect($loops->collect())->toBe(2)
        ->and($a->refresh()->status)->toBe('complained')->and($b->refresh()->status)->toBe('complained')->and($c->refresh()->status)->not->toBe('complained')
        ->and(json_decode((string) DB::table('message_events')->where('message_id', $b->id)->where('type', 'complained')->value('data'), true)['reported_by'])->toBe('Microsoft')
        ->and($loops->collect())->toBe(0); // read once
    expect(Cache::get('fbl.status'))->toMatchArray(['error' => null, 'complaints' => 2]);
    $this->getJson("/v1/workspaces/{$this->ws->id}/delivery-reports")->assertOk()->assertJsonPath('fbl.address', 'fbl@sendline.test');
});
