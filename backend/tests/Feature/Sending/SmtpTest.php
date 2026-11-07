<?php

use App\Models\ApiKey;
use App\Models\Message;
use App\Models\SenderIdentity;
use App\Models\SmtpConnection;
use App\Models\Suppression;
use App\Services\Platform\Settings;
use App\Services\Sending\Senders;
use App\Services\Ses\DnsLookup;
use App\Services\Smtp\SmtpClient;
use App\Services\Smtp\Tracking;
use App\Services\Webhooks\UrlGuard;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeAws;
use Tests\Support\FakeDns;
use Tests\Support\FakeSmtp;

const SMTP_HUMAN_UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

beforeEach(function () {
    $this->smtp = FakeSmtp::install();
    UrlGuard::$resolver = fn (string $host) => match ($host) {
        'internal.example.com' => ['10.0.0.5'],
        'localhost' => ['127.0.0.1'],
        'nowhere.example.com' => [],
        default => ['93.184.216.34'],
    };
    $this->dns = new FakeDns;
    $this->app->instance(DnsLookup::class, $this->dns);
    [$this->ws, $this->people] = sesWorkspace($this, ['member']);
});

afterEach(function () {
    SmtpClient::$factory = null;
    UrlGuard::$resolver = null;
});

function smtpUrl($test, string $path = ''): string
{
    return "/v1/workspaces/{$test->ws->id}/".($path === '' ? 'smtp' : $path);
}

function smtpSetup($test): void
{
    $test->putJson(smtpUrl($test), ['host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'tls', 'username' => 'apikey', 'password' => 'secret-pass-1'])->assertOk()->assertJsonPath('test.ok', true);
}

/** A verified domain sender, as if the TXT record were published. */
function smtpDomain($test, string $domain = 'acme.com'): void
{
    $s = $test->postJson(smtpUrl($test, 'smtp/senders'), ['domain' => $domain])->assertCreated()->json('sender');
    $test->dns->records['TXT '.$s['record']['host']] = [$s['record']['value']];
    $test->postJson(smtpUrl($test, "smtp/senders/{$s['id']}/check"))->assertOk()->assertJsonPath('sender.status', 'verified');
}

function smtpApi($test, array $body)
{
    [, $key] = ApiKey::generate($test->ws->id, 'k', 'send', null);

    return $test->flushHeaders()->withHeaders(['Authorization' => "Bearer $key", 'Accept' => 'application/json'])->postJson('/v1/emails', $body);
}

it('saves SMTP settings, tests them, and uses SMTP when nothing else is connected', function () {
    $this->smtp->failLogin = [535, '5.7.8 Authentication credentials invalid'];
    $r = $this->putJson(smtpUrl($this), ['host' => 'SMTP.Example.com', 'port' => 587, 'encryption' => 'tls', 'username' => 'apikey', 'password' => 'wrong'])->assertOk();
    expect($r->json('test'))->toBe(['ok' => false, 'message' => 'The SMTP server didn’t accept the username or password.'])
        ->and($r->json('smtp.working'))->toBeFalse()->and($r->json('smtp.host'))->toBe('smtp.example.com')->and($r->json('smtp.password'))->toBe('••••••••')->and($r->json('method'))->toBeNull();

    $this->smtp->failLogin = null;
    $r = $this->putJson(smtpUrl($this), ['host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'tls', 'username' => 'apikey', 'password' => '••••••••'])->assertOk(); // keeps the saved password
    expect($r->json('test.ok'))->toBeTrue()->and($r->json('method'))->toBe('smtp')->and(SmtpConnection::first()->password)->toBe('wrong');
    expect(DB::table('smtp_connections')->value('password'))->not->toBe('wrong'); // encrypted at rest

    $this->putJson(smtpUrl($this), ['host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'none', 'username' => 'u', 'password' => 'p'])->assertStatus(422);
    $this->putJson(smtpUrl($this), ['host' => 'smtp.example.com', 'port' => 0, 'encryption' => 'tls'])->assertStatus(422); // any real port is fine, not an impossible one
    switchTo($this, 'member@example.com');
    $this->putJson(smtpUrl($this), ['host' => 'x.example.com', 'port' => 587, 'encryption' => 'tls'])->assertForbidden();
});

it('verifies a domain by its TXT record, and checks SPF and DMARC', function () {
    smtpSetup($this);
    $s = $this->postJson(smtpUrl($this, 'smtp/senders'), ['domain' => 'https://www.Acme.com/'])->assertCreated()->json('sender');
    expect($s['value'])->toBe('acme.com')->and($s['status'])->toBe('pending')->and($s['record']['host'])->toBe('_sendline.acme.com')->and($s['record']['value'])->toStartWith('sendline-verify=');
    $this->postJson(smtpUrl($this, "smtp/senders/{$s['id']}/check"))->assertOk()->assertJsonPath('sender.status', 'pending')->assertJsonPath('sender.dns_check.verification', 'missing');

    $this->dns->records['TXT _sendline.acme.com'] = ['"'.$s['record']['value'].'"'];
    $this->dns->records['TXT acme.com'] = ['v=spf1 include:_spf.example.com ~all'];
    $this->postJson(smtpUrl($this, "smtp/senders/{$s['id']}/check"))->assertOk()->assertJsonPath('sender.status', 'verified')
        ->assertJsonPath('sender.dns_check', ['verification' => 'found', 'spf' => 'found', 'spf_record' => 'v=spf1 include:_spf.example.com ~all', 'dmarc' => 'missing']);
    $this->getJson(smtpUrl($this, 'senders'))->assertOk()->assertExactJson(['method' => 'smtp', 'domains' => ['acme.com'], 'emails' => [], 'default' => null]);
    expect(Senders::allows($this->ws->id, 'news@acme.com'))->toBeTrue()->and(Senders::allows($this->ws->id, 'a@mail.acme.com'))->toBeTrue()->and(Senders::allows($this->ws->id, 'a@other.com'))->toBeFalse();
});

it('verifies a single address with a code emailed through the customer’s own server', function () {
    $this->postJson(smtpUrl($this, 'smtp/senders'), ['email' => 'me@gmail.com'])->assertStatus(422)->assertJsonPath('error.code', 'smtp_not_ready');
    smtpSetup($this);
    $s = $this->postJson(smtpUrl($this, 'smtp/senders'), ['email' => 'Me@Gmail.com'])->assertCreated()->json('sender');
    expect($s)->toMatchArray(['value' => 'me@gmail.com', 'status' => 'pending', 'code_sent' => true])->not->toHaveKey('record');
    $mail = $this->smtp->last();
    expect($mail->getTo()[0]->getAddress())->toBe('me@gmail.com')->and($mail->getFrom()[0]->getAddress())->toBe('me@gmail.com');
    preg_match('/^(\d{6}) is your MailVesper verification code$/', (string) $mail->getSubject(), $m);

    $this->postJson(smtpUrl($this, "smtp/senders/{$s['id']}/confirm"), ['code' => '000000'])->assertStatus(422)->assertJsonPath('error.code', 'code_invalid');
    $this->postJson(smtpUrl($this, "smtp/senders/{$s['id']}/resend"))->assertStatus(429); // not straight away
    $this->postJson(smtpUrl($this, "smtp/senders/{$s['id']}/confirm"), ['code' => $m[1]])->assertOk()->assertJsonPath('sender.status', 'verified');
    expect(Senders::allows($this->ws->id, 'me@gmail.com'))->toBeTrue()->and(Senders::allows($this->ws->id, 'other@gmail.com'))->toBeFalse(); // one address, not the whole gmail.com
});

it('sends through SMTP with tracking, unsubscribe headers and the same events as SES', function () {
    smtpSetup($this);
    smtpApi($this, ['from' => 'news@acme.com', 'to' => 'amara@example.com', 'subject' => 'Hi', 'html' => '<p>x</p>'])->assertStatus(422)
        ->assertJsonPath('error.message', 'You haven’t verified a sender yet. Verify your domain or address under Sending → Senders first.');
    smtpDomain($this);
    $id = smtpApi($this, ['from' => 'Acme <news@acme.com>', 'to' => 'amara@example.com', 'subject' => 'Hello {{ first_name | there }}', 'type' => 'marketing',
        'html' => '<html><body><p>Hi</p><a href="https://acme.com/sale?x=1&amp;y=2">Sale</a> <a href="mailto:help@acme.com">Mail us</a> <a href="{{ unsubscribe_url }}">Unsubscribe</a></body></html>'])
        ->assertStatus(202)->json('data.0.id');

    $m = Message::find($id);
    // Activity shows what really happened: sent through SMTP (it never reports delivery), not "Sending".
    $this->getJson(smtpUrl($this, 'activity'))->assertJsonPath('messages.0.status', 'sent')->assertJsonPath('messages.0.via', 'smtp');
    expect($m->status)->toBe('sent')->and($m->sent_at)->not->toBeNull()->and($m->delivered_at)->not->toBeNull()->and($m->ses_message_id)->toBe($m->id.'@'.substr((string) strrchr($m->from_email, '@'), 1));
    $mail = $this->smtp->last();
    $html = (string) $mail->getHtmlBody();
    expect([$mail->getFrom()[0]->getName(), $mail->getFrom()[0]->getAddress()])->toBe(['Acme', 'news@acme.com'])->and($mail->getSubject())->toBe('Hello there')
        ->and($mail->getHeaders()->get('X-Sendline-Tracking')->getBodyAsString())->toBe($m->tracking_code)
        ->and($mail->getHeaders()->get('List-Unsubscribe-Post')->getBodyAsString())->toBe('List-Unsubscribe=One-Click')
        ->and($html)->toContain(htmlspecialchars(Tracking::link($m, 'https://acme.com/sale?x=1&y=2'), ENT_QUOTES))
        ->and($html)->toContain('href="mailto:help@acme.com"')->and($html)->toContain('/u/?t=')->and($html)->toContain(Tracking::pixel($m));
    expect(DB::table('message_events')->where('message_id', $m->id)->pluck('type')->all())->toContain('sent')->not->toContain('delivered');

    // Opens and clicks come back through MailVesper's own address.
    $this->travel(2)->minutes();
    $this->withHeaders(['User-Agent' => SMTP_HUMAN_UA])->get(parse_url(Tracking::pixel($m), PHP_URL_PATH))->assertOk()->assertHeader('Content-Type', 'image/gif');
    $this->withHeaders(['User-Agent' => SMTP_HUMAN_UA])->get(parse_url(Tracking::link($m, 'https://acme.com/sale?x=1&y=2'), PHP_URL_PATH).'?u='.rawurlencode('https://acme.com/sale?x=1&y=2'))
        ->assertRedirect('https://acme.com/sale?x=1&y=2');
    $m->refresh();
    expect($m->open_count)->toBe(1)->and($m->click_count)->toBe(1)->and(DB::table('message_events')->where('message_id', $m->id)->pluck('type')->all())->toContain('opened', 'clicked');

    // A tampered link never redirects; a tampered pixel is just an image.
    $this->get(parse_url(Tracking::link($m, 'https://acme.com/'), PHP_URL_PATH).'?u='.rawurlencode('https://evil.example/'))->assertNotFound();
    $this->get('/v1/t/o/'.$m->id.'/'.str_repeat('0', 24).'.gif')->assertOk();
    expect($m->refresh()->open_count)->toBe(1);
});

it('sorts SMTP failures: unknown recipient bounces and is suppressed, relay refusals reject, outages retry, bad passwords flag the connection', function () {
    smtpSetup($this);
    smtpDomain($this);
    $send = fn (string $to) => Message::find(smtpApi($this, ['from' => 'news@acme.com', 'to' => $to, 'subject' => 'S', 'text' => 'x'])->json('data.0.id'));

    $this->smtp->failSends = [[550, '550 5.1.1 <ghost@example.com>: Recipient address rejected: User unknown']];
    $m = $send('ghost@example.com');
    expect($m->status)->toBe('bounced')->and(Suppression::check($this->ws->id, ['ghost@example.com']))->toBe(['ghost@example.com' => 'hard_bounce']);

    $this->smtp->failSends = [[550, '550 5.7.1 Relaying denied']];
    $m = $send('real@example.com');
    expect($m->status)->toBe('rejected')->and($m->error)->toContain('Relaying denied')->and(Suppression::check($this->ws->id, ['real@example.com']))->toBe([]);

    $this->smtp->failSends = [[421, '421 4.7.0 Try again later']];
    $m = $send('later@example.com');
    expect($m->status)->toBe('queued')->and($m->error)->toContain('Retrying');

    $this->smtp->failSends = [[535, '535 5.7.8 Authentication failed']];
    $m = $send('pw@example.com');
    expect($m->status)->toBe('queued')->and(SmtpConnection::first()->last_error)->toBe('The SMTP server didn’t accept the username or password.');
});

it('keeps SES workspaces on SES, and switches between SES and SMTP only to a working method', function () {
    FakeAws::install();
    $this->app->instance(DnsLookup::class, new FakeDns);
    sesConnect($this, $this->ws)->assertOk();
    expect(Senders::method($this->ws->id))->toBe('ses');
    smtpSetup($this); // SMTP saved and working, but SES stays active until switched
    expect(Senders::method($this->ws->id))->toBe('ses');
    $this->postJson(smtpUrl($this, 'sending/method'), ['method' => 'smtp'])->assertOk()->assertJsonPath('method', 'smtp');
    expect(Senders::method($this->ws->id))->toBe('smtp');
    $this->postJson(smtpUrl($this, 'sending/method'), ['method' => 'ses'])->assertOk()->assertJsonPath('method', 'ses');
    $this->deleteJson(smtpUrl($this))->assertOk()->assertJsonPath('smtp', null);
    $this->postJson(smtpUrl($this, 'sending/method'), ['method' => 'smtp'])->assertStatus(422)->assertJsonPath('error.code', 'smtp_not_ready');
});

it('holds email over the daily SMTP limit and retries later', function () {
    smtpSetup($this);
    smtpDomain($this);
    SmtpConnection::first()->forceFill(['max_per_day' => 1])->save();
    $first = smtpApi($this, ['from' => 'news@acme.com', 'to' => 'a@example.com', 'subject' => 'S', 'text' => 'x'])->json('data.0.id');
    $second = smtpApi($this, ['from' => 'news@acme.com', 'to' => 'b@example.com', 'subject' => 'S', 'text' => 'x'])->json('data.0.id');
    expect(Message::find($first)->status)->toBe('sent')->and(Message::find($second)->status)->toBe('queued')->and(Message::find($second)->error)->toContain('daily SMTP limit');
});

it('refuses SMTP servers on private or local addresses, unless the platform admin allows them', function () {
    foreach (['internal.example.com' => 'internal.example.com points to a private or local address. Use your mail server’s public name.',
        '127.0.0.1' => '127.0.0.1 points to a private or local address. Use your mail server’s public name.',
        'nowhere.example.com' => 'We couldn’t find nowhere.example.com. Check the host name.'] as $host => $msg) {
        $this->putJson(smtpUrl($this), ['host' => $host, 'port' => 25, 'encryption' => 'none'])->assertStatus(422)->assertJsonPath('error.fields.host', $msg);
    }
    expect(SmtpConnection::count())->toBe(0);
    Settings::set('smtp.allow_private_hosts', true); // a self-hosted install relaying through its own network
    $this->putJson(smtpUrl($this), ['host' => 'internal.example.com', 'port' => 25, 'encryption' => 'none'])->assertOk()->assertJsonPath('test.ok', true);
});

it('answers a connection test for a host that is no longer allowed instead of failing', function () {
    smtpSetup($this);
    SmtpClient::$factory = null; // the real client: the host check runs before any connection
    SmtpConnection::first()->forceFill(['host' => 'internal.example.com'])->save();
    $this->postJson(smtpUrl($this, 'smtp/test'))->assertOk()->assertJsonPath('test', ['ok' => false, 'message' => 'internal.example.com points to a private or local address. Use your mail server’s public name.']);
});

it('verifies a domain before any provider is connected, then makes it the default sender once one is', function () {
    // No SES, no SMTP yet: the domain can still be verified.
    $id = $this->postJson(smtpUrl($this, 'smtp/senders'), ['domain' => 'acme.com'])->assertCreated()->json('sender.id');
    $token = SenderIdentity::find($id)->token;
    $this->dns->records['TXT _sendline.acme.com'] = ["sendline-verify=$token"];
    $this->dns->records['TXT acme.com'] = ['v=spf1 include:sendgrid.net ~all'];
    $this->postJson(smtpUrl($this, "smtp/senders/$id/check"))->assertOk()->assertJsonPath('sender.status', 'verified')
        ->assertJsonPath('sender.dns_check.spf_record', 'v=spf1 include:sendgrid.net ~all');
    $this->putJson(smtpUrl($this, 'senders/default'), ['email' => 'hello@acme.com'])->assertStatus(422)->assertJsonPath('error.message', 'Connect Amazon SES or an SMTP server first, then verify this address’s domain.');

    $this->putJson(smtpUrl($this), ['host' => 'smtp.sendgrid.net', 'port' => 587, 'encryption' => 'tls', 'username' => 'apikey', 'password' => 'SG.key'])->assertOk()->assertJsonPath('method', 'smtp');
    $this->putJson(smtpUrl($this, 'senders/default'), ['email' => 'billing@other.com'])->assertStatus(422);
    $this->putJson(smtpUrl($this, 'senders/default'), ['email' => 'Hello@Acme.com', 'name' => 'Acme'])->assertOk()
        ->assertJsonPath('default', ['email' => 'hello@acme.com', 'name' => 'Acme'])->assertJsonPath('domains', ['acme.com']); // any address on the domain
    switchTo($this, 'member@example.com');
    $this->putJson(smtpUrl($this, 'senders/default'), ['email' => 'news@acme.com'])->assertForbidden();
});

it('accepts SMTP as the answer to "How will you send?"', function () {
    $this->postJson('/v1/workspaces', ['name' => 'Second', 'slug' => 'second-co', 'send_source' => 'smtp'])->assertCreated();
});

it('takes whatever port the provider uses', function () {
    $this->putJson(smtpUrl($this), ['host' => 'smtp.example.com', 'port' => 1025, 'encryption' => 'tls', 'username' => 'u', 'password' => 'p'])->assertOk()->assertJsonPath('smtp.port', 1025);
    $this->putJson(smtpUrl($this), ['host' => 'smtp.example.com', 'port' => 70000, 'encryption' => 'tls'])->assertStatus(422);
});

it('says the test email went through the SMTP server, not Amazon SES', function () {
    smtpSetup($this);
    smtpDomain($this);
    $this->postJson(smtpUrl($this, 'emails/test'), ['from' => 'news@acme.com', 'to' => 'me@example.org'])->assertStatus(202);
    expect((string) $this->smtp->last()->getHtmlBody())->toContain('through your SMTP server (smtp.example.com)')->not->toContain('Amazon SES');
});
