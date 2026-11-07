<?php

use App\Exceptions\ApiException;
use App\Models\DeliveryHook;
use App\Models\Message;
use App\Models\Suppression;
use App\Services\Sending\Mailer;
use App\Services\Ses\DnsLookup;
use App\Services\Smtp\RelayEvents;
use App\Services\Smtp\SmtpClient;
use App\Services\Webhooks\UrlGuard;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeDns;
use Tests\Support\FakeSmtp;

beforeEach(function () {
    $this->smtp = FakeSmtp::install();
    UrlGuard::$resolver = fn (string $host) => ['93.184.216.34'];
    $this->dns = new FakeDns;
    $this->app->instance(DnsLookup::class, $this->dns);
    [$this->ws] = sesWorkspace($this, ['member']);
});

afterEach(function () {
    SmtpClient::$factory = null;
    UrlGuard::$resolver = null;
});

function drUrl($test, string $path = ''): string
{
    return "/v1/workspaces/{$test->ws->id}/".$path;
}

/** SMTP through $host with acme.com verified. */
function drSmtp($test, string $host = 'smtp.sendgrid.net'): void
{
    $test->putJson(drUrl($test, 'smtp'), ['host' => $host, 'port' => 587, 'encryption' => 'tls', 'username' => 'apikey', 'password' => 'secret-pass-1'])->assertOk()->assertJsonPath('test.ok', true);
    $s = $test->postJson(drUrl($test, 'smtp/senders'), ['domain' => 'acme.com'])->assertCreated()->json('sender');
    $test->dns->records['TXT '.$s['record']['host']] = [$s['record']['value']];
    $test->postJson(drUrl($test, "smtp/senders/{$s['id']}/check"))->assertOk()->assertJsonPath('sender.status', 'verified');
}

function drSend($test, string $to, array $extra = []): Message
{
    $mailer = app(Mailer::class);
    $p = $mailer->validate($test->ws, ['from' => 'news@acme.com', 'to' => $to, 'subject' => 'Hi', 'html' => '<p>x</p>'] + $extra);

    return $mailer->submit($test->ws, [$p], 'api')[0]->refresh();
}

/** The hook's path, after turning delivery reports on for $provider. */
function drHook($test, string $provider, ?string $key = null): string
{
    $body = ['provider' => $provider] + ($key !== null ? ['signing_key' => $key] : []);
    $url = $test->putJson(drUrl($test, 'delivery-reports'), $body)->assertOk()->json('hook.url');

    return '/v1/hooks/delivery/'.basename((string) $url);
}

it('puts Gmail’s Feedback-ID and the relay’s metadata header on every SMTP email, and keeps the caller’s own', function () {
    drSmtp($this);
    $m = drSend($this, 'amara@example.com', ['type' => 'marketing']);
    $h = $this->smtp->last()->getHeaders();
    expect($h->get('Feedback-ID')->getBodyAsString())->toBe('api:marketing:'.$this->ws->id.':sendline')
        ->and(json_decode($h->get('X-SMTPAPI')->getBodyAsString(), true))->toBe(['unique_args' => ['sl_message' => $m->id]]);

    drSend($this, 'b@example.com', ['headers' => ['Feedback-ID' => 'mine:acme', 'X-SMTPAPI' => '{"category":"x"}']]);
    $h = $this->smtp->last()->getHeaders();
    expect($h->get('Feedback-ID')->getBodyAsString())->toBe('mine:acme')->and(iterator_count($h->all('X-SMTPAPI')))->toBe(1)
        ->and($h->get('X-SMTPAPI')->getBodyAsString())->toBe('{"category":"x"}');

    // Our tracking header is ours alone.
    expect(fn () => app(Mailer::class)->validate($this->ws, ['from' => 'news@acme.com', 'to' => 'c@example.com', 'subject' => 'Hi', 'html' => '<p>x</p>', 'headers' => ['X-Sendline-Tracking' => 'fake']]))
        ->toThrow(ApiException::class);

    expect(RelayEvents::headers('postmark', 'x'))->toBe(['X-PM-Metadata-sl_message' => 'x', 'X-PM-KeepID' => 'true'])
        ->and(RelayEvents::guess('smtp.eu.mailgun.org'))->toBe('mailgun')->and(RelayEvents::guess('smtp-relay.brevo.com'))->toBe('brevo')
        ->and(RelayEvents::guess('smtp.gmail.com'))->toBeNull()->and(RelayEvents::headers(null, 'x'))->toBe([]);
});

it('turns SendGrid’s events into deliveries, bounces and complaints, once each', function () {
    drSmtp($this);
    [$a, $b, $c] = [drSend($this, 'amara@example.com'), drSend($this, 'gone@example.com'), drSend($this, 'angry@example.com')];
    $path = drHook($this, 'sendgrid');
    expect(DB::table('delivery_hooks')->value('token'))->not->toContain(basename($path)); // encrypted at rest

    $batch = [
        ['email' => 'amara@example.com', 'timestamp' => 1793000000, 'event' => 'delivered', 'response' => '250 2.0.0 OK 1793000000 gsmtp', 'sl_message' => $a->id],
        ['email' => 'amara@example.com', 'timestamp' => 1793000005, 'event' => 'open', 'sl_message' => $a->id], // we track opens ourselves
        ['email' => 'gone@example.com', 'timestamp' => 1793000010, 'event' => 'bounce', 'type' => 'bounce', 'status' => '5.1.1', 'reason' => '550 5.1.1 no such user', 'sl_message' => $b->id],
        ['email' => 'angry@example.com', 'timestamp' => 1793000020, 'event' => 'spamreport', 'sl_message' => $c->id],
    ];
    $this->postJson($path, $batch)->assertOk();
    $this->postJson($path, $batch)->assertOk(); // SendGrid retried

    expect($a->refresh()->status)->toBe('delivered')->and($a->delivered_at->getTimestamp())->toBe(1793000000)->and($a->open_count)->toBe(0)
        ->and($b->refresh()->status)->toBe('bounced')->and($c->refresh()->status)->toBe('complained');
    expect(Suppression::check($this->ws->id, ['gone@example.com', 'angry@example.com']))->toMatchArray(['gone@example.com' => 'hard_bounce', 'angry@example.com' => 'complaint'])->toHaveCount(2);
    expect(DB::table('message_events')->where('message_id', $a->id)->where('type', 'delivered')->count())->toBe(1)
        ->and(json_decode((string) DB::table('message_events')->where('message_id', $c->id)->where('type', 'complained')->value('data'), true))->toBe(['feedback_type' => 'abuse', 'via' => 'SendGrid'])
        ->and(DB::table('message_events')->where('message_id', $b->id)->where('type', 'bounced')->value('data'))->toContain('5.1.1 550 5.1.1 no such user');

    $page = $this->getJson(drUrl($this, 'delivery-reports'))->assertOk();
    expect($page->json('hook'))->toMatchArray(['provider' => 'sendgrid', 'events' => 3, 'signed' => false])->and($page->json('provider_guess'))->toBe('sendgrid')
        ->and($page->json('complaints'))->toBe(['days' => 30, 'total' => 1, 'by' => [['source' => 'SendGrid', 'count' => 1]]]);

    $this->postJson('/v1/hooks/delivery/'.str_repeat('a', 48), $batch)->assertNotFound();
    $this->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'application/json'], 'not json')->assertStatus(400);

    // Members see the page, but not the secret URL, and can't change it.
    switchTo($this, 'member@example.com');
    expect($this->getJson(drUrl($this, 'delivery-reports'))->assertOk()->json('hook.url'))->toBeNull();
    $this->putJson(drUrl($this, 'delivery-reports'), ['provider' => 'mailgun'])->assertForbidden();
    switchTo($this, 'owner@example.com');
    $old = $path;
    $new = '/v1/hooks/delivery/'.basename((string) $this->postJson(drUrl($this, 'delivery-reports/rotate'))->assertOk()->json('hook.url'));
    $this->postJson($old, $batch)->assertNotFound();
    $this->postJson($new, $batch)->assertOk();
});

it('checks SendGrid’s and Mailgun’s signatures when a key is saved', function () {
    drSmtp($this);
    $m = drSend($this, 'amara@example.com');
    $pk = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $pub = openssl_pkey_get_details($pk)['key'];
    $path = drHook($this, 'sendgrid', preg_replace('/-----[A-Z ]+-----|\s+/', '', $pub));
    $body = json_encode([['email' => 'amara@example.com', 'timestamp' => 1793000000, 'event' => 'delivered', 'sl_message' => $m->id]]);
    $ts = '1793000000';
    openssl_sign($ts.$body, $sig, $pk, OPENSSL_ALGO_SHA256);
    $post = fn (string $s) => $this->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_TWILIO_EMAIL_EVENT_WEBHOOK_SIGNATURE' => $s, 'HTTP_X_TWILIO_EMAIL_EVENT_WEBHOOK_TIMESTAMP' => $ts], $body);
    $post(base64_encode('nope'))->assertForbidden();
    expect(DeliveryHook::first()->last_error)->toContain('wrong signature')->and($m->refresh()->status)->toBe('sent');
    $post(base64_encode($sig))->assertOk();
    expect($m->refresh()->status)->toBe('delivered');

    // Mailgun: HMAC of timestamp + token with the webhook signing key.
    $m2 = drSend($this, 'gone@example.com');
    $path = drHook($this, 'mailgun', 'key-abc');
    $event = ['event' => 'failed', 'severity' => 'permanent', 'reason' => 'bounce', 'recipient' => 'gone@example.com', 'timestamp' => 1793000100.5,
        'delivery-status' => ['code' => 550, 'message' => '5.1.1 mailbox unavailable'], 'user-variables' => ['sl_message' => $m2->id]];
    $this->postJson($path, ['signature' => ['timestamp' => '1', 'token' => 't', 'signature' => 'bad'], 'event-data' => $event])->assertForbidden();
    $this->postJson($path, ['signature' => ['timestamp' => '1', 'token' => 't', 'signature' => hash_hmac('sha256', '1t', 'key-abc')], 'event-data' => $event])->assertOk();
    expect($m2->refresh()->status)->toBe('bounced')->and(Suppression::check($this->ws->id, ['gone@example.com']))->toBe(['gone@example.com' => 'hard_bounce']);

    // Switching provider drops a key that can't verify the new one.
    $this->putJson(drUrl($this, 'delivery-reports'), ['provider' => 'postmark'])->assertOk()->assertJsonPath('hook.signed', false);
});

it('reads Postmark, Brevo and SparkPost events, finding the email by metadata, Message-ID or recipient', function () {
    drSmtp($this, 'smtp.postmarkapp.com');
    [$a, $b, $c, $d] = [drSend($this, 'a@example.com'), drSend($this, 'b@example.com'), drSend($this, 'c@example.com'), drSend($this, 'd@example.com')];
    expect($this->smtp->last()->getHeaders()->get('X-PM-Metadata-sl_message')->getBodyAsString())->toBe($d->id);

    $path = drHook($this, 'postmark');
    $this->postJson($path, ['RecordType' => 'Delivery', 'Recipient' => 'a@example.com', 'DeliveredAt' => '2026-11-05T16:33:54.9Z', 'Details' => '250 OK', 'Metadata' => ['sl_message' => $a->id]])->assertOk();
    $this->postJson($path, ['RecordType' => 'Bounce', 'Type' => 'SoftBounce', 'Email' => 'b@example.com', 'BouncedAt' => '2026-11-05T16:34:00Z', 'Details' => 'mailbox full', 'Metadata' => ['sl_message' => $b->id]])->assertOk();
    $this->postJson($path, ['RecordType' => 'SpamComplaint', 'Type' => 'SpamComplaint', 'Email' => 'c@example.com', 'BouncedAt' => '2026-11-05T16:35:00Z', 'Metadata' => ['sl_message' => $c->id]])->assertOk();
    $this->postJson($path, ['RecordType' => 'Bounce', 'Type' => 'AutoResponder', 'Email' => 'd@example.com', 'Metadata' => ['sl_message' => $d->id]])->assertOk();
    expect($a->refresh()->status)->toBe('delivered')->and($a->delivered_at->toIso8601ZuluString())->toBe('2026-11-05T16:33:54Z')
        ->and($b->refresh()->status)->toBe('bounced')->and(Suppression::check($this->ws->id, ['b@example.com']))->toBe([]) // soft: not suppressed
        ->and($c->refresh()->status)->toBe('complained')->and($d->refresh()->status)->toBe('sent');

    // Brevo: by our custom header, then by Message-ID (ours, when kept).
    $e = drSend($this, 'e@example.com');
    $f = drSend($this, 'f@example.com');
    $path = drHook($this, 'brevo');
    $this->postJson($path, ['event' => 'hard_bounce', 'email' => 'e@example.com', 'ts_event' => 1793000000, 'reason' => 'unknown user', 'X-Mailin-custom' => 'x:1|sl_message:'.$e->id])->assertOk();
    $this->postJson($path, ['event' => 'spam', 'email' => 'f@example.com', 'ts_event' => 1793000001, 'message-id' => '<'.$f->ses_message_id.'>'])->assertOk();
    expect($e->refresh()->status)->toBe('bounced')->and($f->refresh()->status)->toBe('complained');

    // SparkPost: batches; bounce class decides hard or soft; no id at all falls back to the recipient's latest email.
    $g = drSend($this, 'g@example.com');
    $h = drSend($this, 'h@example.com');
    $path = drHook($this, 'sparkpost');
    $this->postJson($path, [
        ['msys' => ['message_event' => ['type' => 'bounce', 'bounce_class' => '10', 'rcpt_to' => 'g@example.com', 'raw_reason' => '550 no user', 'timestamp' => '1793000000', 'rcpt_meta' => ['sl_message' => $g->id]]]],
        ['msys' => ['message_event' => ['type' => 'delay', 'rcpt_to' => 'h@example.com', 'raw_reason' => '421 try later', 'timestamp' => '2026-11-05T16:00:00.000Z']]],
        ['msys' => ['track_event' => ['type' => 'open', 'rcpt_to' => 'h@example.com']]],
    ])->assertOk();
    expect($g->refresh()->status)->toBe('bounced')->and(Suppression::check($this->ws->id, ['g@example.com']))->toBe(['g@example.com' => 'hard_bounce'])
        ->and($h->refresh()->status)->toBe('delayed');

    expect(RelayEvents::parse('sparkpost', [['msys' => ['message_event' => ['type' => 'bounce', 'bounce_class' => '21', 'rcpt_to' => 'x@y.z']]]])[0]['section']['bounceType'])->toBe('Transient')
        ->and(RelayEvents::parse('mailgun', ['event-data' => ['event' => 'failed', 'severity' => 'temporary', 'recipient' => 'x@y.z']])[0]['type'])->toBe('DeliveryDelay')
        ->and(RelayEvents::parse('mailgun', ['event-data' => ['event' => 'failed', 'severity' => 'permanent', 'reason' => 'suppress-bounce', 'recipient' => 'x@y.z']])[0]['type'])->toBe('Reject')
        ->and(RelayEvents::parse('sendgrid', [['event' => 'bounce', 'type' => 'blocked', 'email' => 'x@y.z']])[0]['section']['bounceType'])->toBe('Transient');
});

it('stops applying a deleted workspace’s or a removed hook’s events', function () {
    drSmtp($this);
    $m = drSend($this, 'a@example.com');
    $path = drHook($this, 'sendgrid');
    $this->deleteJson(drUrl($this, 'delivery-reports'))->assertOk()->assertJsonPath('hook', null);
    $this->postJson($path, [['event' => 'delivered', 'email' => 'a@example.com', 'sl_message' => $m->id]])->assertNotFound();
    expect($m->refresh()->status)->toBe('sent');
});
