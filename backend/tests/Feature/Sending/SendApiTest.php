<?php

use App\Models\ApiKey;
use App\Models\Message;
use App\Models\SesConnection;
use App\Models\Suppression;
use App\Services\Ses\DnsLookup;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeAws;
use Tests\Support\FakeDns;

beforeEach(function () {
    $this->aws = FakeAws::install();
    $this->app->instance(DnsLookup::class, new FakeDns);
    [$this->ws] = sesWorkspace($this);
    sesConnect($this, $this->ws)->assertOk(); // imports acme.com as verified
    [, $this->key] = ApiKey::generate($this->ws->id, 'Test', 'send', null);
    app('auth')->forgetGuards();
    $this->postJson('/v1/auth/logout');
    app('auth')->forgetGuards();
});

function api($test, string $method, string $uri, $body = [], array $headers = [])
{
    return $test->withHeaders(['Authorization' => 'Bearer '.$test->key] + $headers)->json($method, $uri, $body);
}

function email(array $over = []): array
{
    return $over + ['from' => 'Acme <news@acme.com>', 'to' => 'amara@example.com', 'subject' => 'Hi {{ first_name | there }}',
        'html' => '<p>Hello {{ first_name | friend }}</p>', 'variables' => ['first_name' => 'Amara']];
}

it('needs a valid, unrevoked API key', function () {
    $this->postJson('/v1/emails', email())->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
    $this->withHeaders(['Authorization' => 'Bearer sl_live_'.str_repeat('x', 40)])->postJson('/v1/emails', email())->assertUnauthorized();
    ApiKey::query()->update(['revoked_at' => now()]);
    api($this, 'POST', '/v1/emails', email())->assertUnauthorized();
});

it('sends through the customer’s SES with tracking and the configuration set', function () {
    $r = api($this, 'POST', '/v1/emails', email(['tags' => ['campaign' => 'welcome'], 'headers' => ['X-Entity-Ref-ID' => 'abc']]))->assertStatus(202);
    $d = $r->json('data.0');
    expect($d['tracking_code'])->toMatch('/^SL-[2-9A-HJ-NP-Z]{4}-[2-9A-HJ-NP-Z]{4}$/')->and($d['status'])->toBe('queued');

    $m = Message::first();
    expect($m->status)->toBe('sent')->and($m->ses_message_id)->not->toBeNull()->and($m->subject)->toBe('Hi Amara')
        ->and($m->text)->toBe('Hello Amara');
    $req = $this->aws->argsFor('SendEmail')[0];
    expect($req['FromEmailAddress'])->toBe('"Acme" <news@acme.com>')
        ->and($req['Destination'])->toBe(['ToAddresses' => ['amara@example.com']])
        ->and($req['ConfigurationSetName'])->toBe('sendline-events')
        ->and($req['Content']['Simple']['Body']['Html']['Data'])->toBe('<p>Hello Amara</p>')
        ->and(collect($req['Content']['Simple']['Headers'])->pluck('Value', 'Name')->all())->toBe(['X-Entity-Ref-ID' => 'abc', 'X-Sendline-Tracking' => $m->tracking_code, 'Feedback-ID' => 'api:transactional:'.$this->ws->id.':sendline'])
        ->and(collect($req['EmailTags'])->pluck('Value', 'Name')->all())->toBe(['sl_message' => $m->id, 'sl_workspace' => $this->ws->id, 'campaign' => 'welcome']);

    api($this, 'GET', '/v1/emails/'.$m->tracking_code)->assertOk()->assertJsonPath('status', 'sent');
});

it('adds one-click unsubscribe headers to marketing email', function () {
    api($this, 'POST', '/v1/emails', email(['type' => 'marketing', 'html' => '<a href="{{ unsubscribe_url }}">Unsubscribe</a>']))->assertStatus(202);
    $m = Message::first();
    $h = collect($this->aws->argsFor('SendEmail')[0]['Content']['Simple']['Headers'])->pluck('Value', 'Name');
    expect($h['List-Unsubscribe'])->toBe('<https://send.test/api/v1/unsubscribe/'.$m->unsubscribe_token.'>')
        ->and($h['List-Unsubscribe-Post'])->toBe('List-Unsubscribe=One-Click')
        ->and($m->html)->toContain('https://send.test/u/?t='.$m->unsubscribe_token);
});

it('only sends from verified domains (and their subdomains)', function () {
    api($this, 'POST', '/v1/emails', email(['from' => 'me@gmail.com']))->assertStatus(422)->assertJsonPath('error.code', 'from_domain_not_verified')
        ->assertJsonPath('error.message', 'gmail.com isn’t a verified domain. Send from acme.com or verify it under Domains.');
    api($this, 'POST', '/v1/emails', email(['from' => 'news@mail.acme.com']))->assertStatus(202);
});

it('validates the payload field by field', function () {
    api($this, 'POST', '/v1/emails', ['from' => 'nope', 'to' => ['a@b.com', 'bad'], 'subject' => ''])->assertStatus(422)
        ->assertJsonPath('error.fields.from', fn ($m) => str_contains($m, 'address like'))
        ->assertJsonPath('error.fields.to', 'Not a valid email address: bad')
        ->assertJsonPath('error.fields.html', 'Send html, text, or both.');
    api($this, 'POST', '/v1/emails', email(['to' => array_map(fn ($i) => "u$i@x.com", range(1, 51))]))->assertStatus(422);
    api($this, 'POST', '/v1/emails', email(['headers' => ['Bcc' => 'spy@x.com']]))->assertStatus(422)->assertJsonPath('error.fields.headers', 'MailVesper sets the Bcc header itself.');
    expect(Message::count())->toBe(0);
});

it('never sends to suppressed addresses', function () {
    Suppression::add($this->ws->id, 'Gone@Example.com', 'hard_bounce');
    $r = api($this, 'POST', '/v1/emails', email(['to' => ['gone@example.com', 'ok@example.com']]))->assertStatus(202);
    expect(collect($r->json('data'))->pluck('status', 'to')->all())->toBe(['gone@example.com' => 'suppressed', 'ok@example.com' => 'queued']);
    expect($this->aws->argsFor('SendEmail'))->toHaveCount(1)->and($this->aws->argsFor('SendEmail')[0]['Destination']['ToAddresses'])->toBe(['ok@example.com']);
});

it('replays the same Idempotency-Key instead of sending twice', function () {
    $h = ['Idempotency-Key' => 'order-1234-receipt'];
    $first = api($this, 'POST', '/v1/emails', email(), $h)->assertStatus(202)->json();
    api($this, 'POST', '/v1/emails', email(), $h)->assertStatus(202)->assertHeader('Idempotent-Replayed', 'true')->assertJson($first);
    expect(Message::count())->toBe(1);
    api($this, 'POST', '/v1/emails', email(['subject' => 'Other']), $h)->assertStatus(409)->assertJsonPath('error.code', 'idempotency_conflict');
});

it('sends batches with per-item errors', function () {
    api($this, 'POST', '/v1/emails/batch', [email(), email(['from' => 'x'])])->assertStatus(422)->assertJsonPath('error.fields', fn ($f) => isset($f['1.from']));
    $r = api($this, 'POST', '/v1/emails/batch', [email(), email(['to' => ['b@x.com', 'c@x.com'], 'variables' => ['first_name' => 'Bo']])])->assertStatus(202);
    expect($r->json('data'))->toHaveCount(3)->and(Message::distinct()->count('batch_id'))->toBe(1);
    expect(Message::where('to_email', 'c@x.com')->value('subject'))->toBe('Hi Bo');
    api($this, 'POST', '/v1/emails/batch', array_fill(0, 101, email()))->assertStatus(422);
});

it('retries throttling with backoff and explains permanent failures', function () {
    $this->aws->fail('SendEmail', 'Throttling');
    api($this, 'POST', '/v1/emails', email())->assertStatus(202);
    $m = Message::first();
    expect($m->status)->toBe('queued')->and($m->attempts)->toBe(1)->and($m->error)->toContain('slow down')
        ->and(DB::table('jobs')->count())->toBe(1);

    $this->aws->fail('SendEmail', 'MessageRejected');
    api($this, 'POST', '/v1/emails', email(['to' => 'z@example.com']))->assertStatus(202);
    expect(Message::where('to_email', 'z@example.com')->first())->status->toBe('rejected')->error->toContain('SES rejected');
});

it('sends to many recipients through the queue, under the send rate', function () {
    $r = api($this, 'POST', '/v1/emails', email(['to' => array_map(fn ($i) => "p$i@example.com", range(1, 20))]))->assertStatus(202);
    expect($r->json('data'))->toHaveCount(20)->and(Message::where('status', 'sent')->count())->toBe(20)
        ->and(Message::distinct()->count('tracking_code'))->toBe(20);
});

it('needs a connected SES', function () {
    SesConnection::query()->delete();
    api($this, 'POST', '/v1/emails', email())->assertStatus(422)->assertJsonPath('error.code', 'ses_not_connected');
});
