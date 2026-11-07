<?php

use App\Jobs\DeliverWebhook;
use App\Models\ApiKey;
use App\Models\AuditLog;
use App\Models\Message;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Sending\Mailer;
use App\Services\Ses\DnsLookup;
use App\Services\Ses\EventProcessor;
use App\Services\Webhooks\UrlGuard;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeAws;
use Tests\Support\FakeDns;

beforeEach(function () {
    FakeAws::install();
    $this->app->instance(DnsLookup::class, new FakeDns);
    UrlGuard::$resolver = fn (string $host) => match ($host) {
        'internal.example.com' => ['10.0.0.5'],
        'nowhere.example.com' => [],
        default => ['93.184.216.34'],
    };
    $this->hookStatus = 200;
    Http::fake(['hooks.example.com/*' => fn () => Http::response('ok', $this->hookStatus)]);
    [$this->ws] = sesWorkspace($this, ['member']);
    sesConnect($this, $this->ws)->assertOk();
});

afterEach(fn () => UrlGuard::$resolver = null);

function addEndpoint($test, array $events = ['email.delivered', 'email.bounced'])
{
    return $test->postJson("/v1/workspaces/{$test->ws->id}/webhooks", ['url' => 'https://hooks.example.com/sendline', 'events' => $events]);
}

function deliveredEvent($test): Message
{
    $mailer = app(Mailer::class);
    $m = $mailer->submit($test->ws, [$mailer->validate($test->ws, ['from' => 'news@acme.com', 'to' => 'amara@gmail.com', 'subject' => 'Hi', 'text' => 'Hi', 'tags' => ['order' => '1001']])], 'api')[0]->refresh();
    app(EventProcessor::class)->apply($test->ws->id, ['eventType' => 'Delivery', 'mail' => ['messageId' => $m->ses_message_id, 'tags' => ['sl_message' => [$m->id]]], 'delivery' => ['timestamp' => now()->toIso8601ZuluString(), 'smtpResponse' => '250 gsmtp']]);

    return $m;
}

it('refuses non-HTTPS, private-network and unknown hosts', function () {
    $url = "/v1/workspaces/{$this->ws->id}/webhooks";
    $this->postJson($url, ['url' => 'http://hooks.example.com/x', 'events' => ['*']])->assertStatus(422)->assertJsonPath('error.fields.url', 'Use a full https:// address.');
    $this->postJson($url, ['url' => 'https://internal.example.com/x', 'events' => ['*']])->assertStatus(422)->assertJsonPath('error.fields.url', 'That address points to a private network. Use a public URL.');
    $this->postJson($url, ['url' => 'https://127.0.0.1/x', 'events' => ['*']])->assertStatus(422);
    $this->postJson($url, ['url' => 'https://nowhere.example.com/x', 'events' => ['*']])->assertStatus(422);
    $this->postJson($url, ['url' => 'https://hooks.example.com/x', 'events' => ['email.nope']])->assertStatus(422);
});

it('posts signed events the receiver can verify (Standard Webhooks)', function () {
    $secret = addEndpoint($this)->assertCreated()->json('secret');
    expect($secret)->toStartWith('whsec_');
    $m = deliveredEvent($this);

    Http::assertSent(function (HttpRequest $r) use ($secret, $m) {
        $id = $r->header('webhook-id')[0];
        $ts = $r->header('webhook-timestamp')[0];
        $expected = base64_encode(hash_hmac('sha256', "$id.$ts.".$r->body(), base64_decode(substr($secret, 6)), true));
        $body = json_decode($r->body(), true);

        return $r->header('webhook-signature')[0] === "v1,$expected"
            && $body['type'] === 'email.delivered' && $body['data']['tracking_code'] === $m->tracking_code
            && $body['data']['tags'] === ['order' => '1001'] && $body['data']['provider'] === 'google' && $body['data']['smtp_response'] === '250 gsmtp';
    });
    expect(WebhookDelivery::where('status', 'success')->count())->toBe(1);
    Http::assertSentCount(1); // email.sent/accepted aren't subscribed
});

it('retries failures on a backoff schedule, then gives up', function () {
    addEndpoint($this)->assertCreated();
    $this->hookStatus = 500;
    deliveredEvent($this);
    $d = WebhookDelivery::first();
    expect($d->status)->toBe('retrying')->and($d->response_code)->toBe(500)->and($d->next_attempt_at->diffInSeconds(now(), true))->toBeGreaterThan(50)
        ->and(DB::table('jobs')->count())->toBe(1);

    foreach (range(2, 7) as $attempt) {
        DB::table('webhook_deliveries')->where('id', $d->id)->update(['status' => 'retrying']);
        (new DeliverWebhook($d->id))->handle();
    }
    expect($d->refresh())->status->toBe('failed')->attempts->toBe(7);
});

it('pauses an endpoint that keeps failing, and stops sending to it', function () {
    $id = addEndpoint($this, ['*'])->json('endpoint.id');
    WebhookEndpoint::query()->update(['failure_streak' => DeliverWebhook::DISABLE_AFTER - 1]);
    $this->hookStatus = 503;
    deliveredEvent($this);
    $e = WebhookEndpoint::first();
    expect($e->enabled)->toBeFalse()->and($e->disabled_reason)->toContain('Paused automatically');
    $before = WebhookDelivery::count();
    deliveredEvent($this);
    expect(WebhookDelivery::count())->toBe($before);

    $this->patchJson("/v1/workspaces/{$this->ws->id}/webhooks/$id", ['enabled' => true])->assertOk()->assertJsonPath('endpoint.disabled_reason', null);
});

it('sends a test ping and replays a delivery, showing the response', function () {
    $id = addEndpoint($this)->json('endpoint.id');
    $this->postJson("/v1/workspaces/{$this->ws->id}/webhooks/$id/test")->assertOk()->assertJsonPath('delivery.status', 'success')->assertJsonPath('delivery.response_code', 200);

    $this->hookStatus = 500;
    deliveredEvent($this);
    $failed = WebhookDelivery::where('event_type', 'email.delivered')->first();
    $this->hookStatus = 200;
    $r = $this->postJson("/v1/workspaces/{$this->ws->id}/webhook-deliveries/{$failed->id}/replay")->assertOk();
    expect($r->json('delivery.status'))->toBe('success')->and($r->json('delivery.payload'))->toBe($failed->payload);
    expect($this->getJson("/v1/workspaces/{$this->ws->id}/webhooks/$id/deliveries")->json('deliveries'))->toHaveCount(3);
    expect($this->getJson("/v1/workspaces/{$this->ws->id}/webhooks")->json('endpoints.0.stats_24h'))->toMatchArray(['total' => 2, 'success' => 1, 'retrying' => 1]);
});

it('rotates secrets and keeps webhooks to owners and admins', function () {
    $r = addEndpoint($this)->json();
    $new = $this->postJson("/v1/workspaces/{$this->ws->id}/webhooks/{$r['endpoint']['id']}/rotate")->assertOk()->json('secret');
    expect($new)->not->toBe($r['secret']);
    expect(DB::table('webhook_endpoints')->value('secret'))->not->toContain('whsec_'); // encrypted at rest

    app('auth')->forgetGuards();
    $this->postJson('/v1/auth/logout');
    app('auth')->forgetGuards();
    $this->postJson('/v1/auth/login', ['email' => 'member@example.com', 'password' => STRONG_PASSWORD])->assertOk();
    $this->getJson("/v1/workspaces/{$this->ws->id}/webhooks")->assertForbidden();
});

it('lets a full-access key register and remove its own endpoint (for integrations)', function () {
    [, $send] = ApiKey::generate($this->ws->id, 'send', 'send', null);
    [, $full] = ApiKey::generate($this->ws->id, 'full', 'full', null);
    $this->flushHeaders()->withHeaders(['Authorization' => "Bearer $send", 'Accept' => 'application/json'])->postJson('/v1/webhooks', ['url' => 'https://hooks.example.com/of', 'events' => ['email.unsubscribed']])->assertForbidden();
    $h = ['Authorization' => "Bearer $full", 'Accept' => 'application/json'];
    $this->flushHeaders()->withHeaders($h)->postJson('/v1/webhooks', ['url' => 'https://internal.example.com/x', 'events' => ['*']])->assertStatus(422)->assertJsonPath('error.fields.url', 'That address points to a private network. Use a public URL.');
    $r = $this->flushHeaders()->withHeaders($h)->postJson('/v1/webhooks', ['url' => 'https://hooks.example.com/of', 'events' => ['email.unsubscribed', 'email.complained'], 'description' => 'My app'])->assertCreated()->json();
    expect($r['secret'])->toStartWith('whsec_')->and($r['endpoint']['events'])->toBe(['email.unsubscribed', 'email.complained']);
    expect($this->flushHeaders()->withHeaders($h)->getJson('/v1/webhooks')->assertOk()->json('data.0'))->toMatchArray(['id' => $r['endpoint']['id'], 'description' => 'My app'])->not->toHaveKey('secret');
    $this->flushHeaders()->withHeaders($h)->deleteJson('/v1/webhooks/'.$r['endpoint']['id'])->assertOk();
    $this->flushHeaders()->withHeaders($h)->deleteJson('/v1/webhooks/'.$r['endpoint']['id'])->assertNotFound();
    expect(AuditLog::where('action', 'webhook.created')->value('meta'))->toHaveKey('api_key');
});
