<?php

use App\Models\DeliverabilityCheck;
use App\Models\Message;
use App\Services\Mailbox\ImapClient;
use App\Services\Mailbox\MailboxConnection;
use App\Services\Platform\Settings;
use App\Services\Ses\DnsLookup;
use Tests\Support\FakeAws;
use Tests\Support\FakeDns;

beforeEach(function () {
    FakeAws::install();
    $this->dns = new FakeDns;
    $this->app->instance(DnsLookup::class, $this->dns);
    [$this->ws] = sesWorkspace($this, ['viewer']);
    sesConnect($this, $this->ws)->assertOk();
    foreach (['checks.address' => 'check@sendline.test', 'checks.imap_host' => 'imap.sendline.test', 'checks.imap_password' => 'p'] as $k => $v) {
        Settings::set($k, $v);
    }
    // The check mailbox: whatever the test puts in $this->inbox.
    $this->inbox = [];
    $test = $this;
    ImapClient::$factory = fn () => new class($test) implements MailboxConnection
    {
        public function __construct(private $test) {}

        public function select(string $folder): array
        {
            return ['validity' => 1, 'next' => count($this->test->inbox) + 1];
        }

        public function uidsFrom(int $from): array
        {
            return [];
        }

        public function searchSubject(string $text): array
        {
            return array_keys(array_filter($this->test->inbox, fn ($r) => str_contains($r, $text)));
        }

        public function fetch(int $uid, int $maxBytes = ImapClient::MAX_BYTES): ?string
        {
            return $this->test->inbox[$uid] ?? null;
        }

        public function close(): void {}
    };
});

afterEach(function () {
    ImapClient::$factory = null;
});

it('sends a real test email through the workspace’s route, reads it back and explains SPF, DKIM and DMARC', function () {
    $this->postJson("/v1/workspaces/{$this->ws->id}/deliverability/checks", ['from' => 'news@other.com'])->assertStatus(422);
    $id = $this->postJson("/v1/workspaces/{$this->ws->id}/deliverability/checks", ['from' => 'news@acme.com'])->assertCreated()->assertJsonPath('check.status', 'waiting')->json('check.id');
    $check = DeliverabilityCheck::find($id);
    $sent = Message::find($check->message_id);
    expect($sent->to_email)->toBe('check@sendline.test')->and($sent->subject)->toContain($check->token);

    // How it arrives: through SES's outbound IP, without a DKIM signature, for a domain with SPF and DMARC.
    $this->dns->records['TXT acme.com'] = ['v=spf1 include:amazonses.com ~all'];
    $this->dns->records['TXT amazonses.com'] = ['v=spf1 ip4:54.240.0.0/18 -all'];
    $this->dns->records['TXT _dmarc.acme.com'] = ['v=DMARC1; p=quarantine; rua=mailto:d@acme.com'];
    $this->inbox[7] = "Return-Path: <bounce@acme.com>\r\nReceived: from a8-12.smtp-out.amazonses.com (a8-12.smtp-out.amazonses.com [54.240.8.12])\r\n\tby mx.sendline.test with ESMTPS; Sat, 31 Oct 2026 10:00:00 +0000\r\n"
        ."From: Acme <news@acme.com>\r\nTo: check@sendline.test\r\nSubject: MailVesper setup check {$check->token}\r\nDate: Sat, 31 Oct 2026 10:00:00 +0000\r\nMIME-Version: 1.0\r\nContent-Type: text/plain\r\n\r\nHello\r\n";
    $this->travel(15)->seconds();
    $r = $this->getJson("/v1/workspaces/{$this->ws->id}/deliverability/checks/$id")->assertOk()->assertJsonPath('check.status', 'done')->json('check.result');
    expect($r['sending_ip'])->toBe('54.240.8.12')->and($r['spf']['result'])->toBe('pass')->and($r['dkim'])->toBe([])
        ->and($r['dmarc'])->toMatchArray(['result' => 'pass', 'policy' => 'quarantine', 'aligned_by' => ['spf']])
        ->and($r['verdict'])->toBe(['level' => 'bad', 'title' => 'No valid DKIM signature for your domain']);

    // Each email's report uses that check, plus its own content, delivery and engagement.
    $rep = $this->getJson("/v1/workspaces/{$this->ws->id}/activity/{$sent->tracking_code}/report")->assertOk()->json('report');
    expect($rep['authentication']['check']['result']['sending_ip'])->toBe('54.240.8.12')->and($rep['delivery']['via'])->toBe('ses')
        ->and($rep['placement'])->toBe(['known' => false, 'inbox_tests' => false])->and($rep['engagement']['opens'])->toBe(0);
    switchTo($this, 'viewer@example.com');
    $this->postJson("/v1/workspaces/{$this->ws->id}/deliverability/checks", ['from' => 'news@acme.com'])->assertForbidden();
    $this->getJson("/v1/workspaces/{$this->ws->id}/deliverability/checks?domain=acme.com")->assertOk()->assertJsonCount(1, 'checks');
});

it('says so when the test email never arrives, and when checks are off', function () {
    $id = $this->postJson("/v1/workspaces/{$this->ws->id}/deliverability/checks", ['from' => 'news@acme.com'])->assertCreated()->json('check.id');
    $this->travel(16)->minutes();
    $this->artisan('checks:collect')->assertSuccessful();
    expect(DeliverabilityCheck::find($id))->status->toBe('failed')->error->toContain('didn’t arrive within 15 minutes');
    Settings::set('checks.address', null);
    $this->postJson("/v1/workspaces/{$this->ws->id}/deliverability/checks", ['from' => 'news@acme.com'])->assertStatus(422)->assertJsonPath('error.code', 'checks_off');
});

it('does not blame DKIM when the test never left the mail server', function () {
    $id = $this->postJson("/v1/workspaces/{$this->ws->id}/deliverability/checks", ['from' => 'news@acme.com'])->assertCreated()->json('check.id');
    $token = DeliverabilityCheck::find($id)->token;
    $this->dns->records['A imap.sendline.test'] = ['198.51.100.4']; // the check mailbox's own server…
    $this->inbox[3] = "Received: from [198.51.100.4] (port=51000 helo=mail.acme.com)\r\n\tby imap.sendline.test with esmtpsa\r\nFrom: news@acme.com\r\nTo: check@sendline.test\r\nSubject: MailVesper setup check $token\r\n\r\nHi\r\n";
    $this->travel(15)->seconds();
    $r = $this->getJson("/v1/workspaces/{$this->ws->id}/deliverability/checks/$id")->json('check.result'); // …sent it
    expect($r['same_server'])->toBeTrue()->and($r['verdict']['level'])->toBe('warn')->and($r['verdict']['title'])->toContain('stayed on one mail server');
});
