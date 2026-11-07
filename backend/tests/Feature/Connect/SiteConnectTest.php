<?php

use App\Models\ApiKey;
use App\Models\Domain;
use App\Models\SiteConnection;
use App\Services\Ses\DnsLookup;
use App\Services\Webhooks\UrlGuard;
use Tests\Support\FakeAws;
use Tests\Support\FakeDns;

beforeEach(function () {
    FakeAws::install();
    $this->app->instance(DnsLookup::class, new FakeDns);
    [$this->ws, $this->people] = sesWorkspace($this, ['viewer']);
    $this->verifier = str_repeat('a1B2c3D4', 6); // 48 characters, as the site makes it
    $this->challenge = rtrim(strtr(base64_encode(hash('sha256', $this->verifier, true)), '+/', '-_'), '=');
    $this->redirect = 'https://micro.example.com/admin/sendline/callback';
});

function approve($test, array $over = [])
{
    return $test->postJson("/v1/workspaces/{$test->ws->id}/connect/approve", $over + ['redirect_uri' => $test->redirect, 'state' => 'st4te-abc123', 'code_challenge' => $test->challenge,
        'code_challenge_method' => 'S256', 'name' => 'Micro Lemon']);
}

function ready($test): void
{
    sesConnect($test, $test->ws)->assertOk();
    Domain::updateOrCreate(['workspace_id' => $test->ws->id, 'name' => 'example.com'], ['region' => 'eu-west-1', 'status' => 'verified']);
}

it('tells a site what this installation is and where to connect', function () {
    $this->getJson('/v1/meta')->assertOk()->assertJson(['product' => 'sendline', 'features' => ['connect' => 1]])
        ->assertJsonPath('connect_url', rtrim((string) config('app.frontend_url'), '/').'/app/#/connect')
        ->assertJsonPath('api_base', rtrim((string) config('app.url'), '/').'/v1');
});

it('checks the site’s return address before anything else', function () {
    $this->getJson('/v1/connect/check?'.http_build_query(['redirect_uri' => $this->redirect, 'state' => 'xyz12345']))->assertOk()
        ->assertJson(['site_url' => 'https://micro.example.com', 'host' => 'micro.example.com', 'cancel_url' => $this->redirect.'?error=access_denied&state=xyz12345']);
    foreach (['http://micro.example.com/cb', 'javascript:alert(1)', 'https://user:pw@evil.com/cb', 'https://micro.example.com/cb#x', '//evil.com', 'ftp://x.com/a'] as $bad) {
        $this->getJson('/v1/connect/check?'.http_build_query(['redirect_uri' => $bad]))->assertStatus(422)->assertJsonPath('error.code', 'invalid_redirect');
    }
    $this->getJson('/v1/connect/check?'.http_build_query(['redirect_uri' => 'http://localhost:8000/cb']))->assertOk(); // local development
});

it('only connects a workspace that can send, and only for owners and admins', function () {
    approve($this)->assertStatus(422)->assertJson(['error' => ['code' => 'not_ready']]);
    $this->getJson("/v1/workspaces/{$this->ws->id}/connect/readiness")->assertOk()->assertJsonPath('readiness.ready', false)
        ->assertJsonPath('readiness.reason', 'Connect a sending provider first: your own Amazon SES or any SMTP server.');
    ready($this);
    $r = $this->getJson("/v1/workspaces/{$this->ws->id}/connect/readiness")->json('readiness');
    expect($r)->toMatchArray(['ready' => true, 'method' => 'ses', 'reason' => null])->and($r['domains'])->toContain('example.com');
    approve($this, ['code_challenge' => 'too-short'])->assertStatus(422);
    approve($this, ['code_challenge_method' => 'plain'])->assertStatus(422);
    switchTo($this, $this->people['viewer']->email);
    approve($this)->assertStatus(403);
});

it('exchanges a one-time code, with the right verifier, for the site’s own key', function () {
    ready($this);
    $url = approve($this)->assertOk()->json('redirect');
    expect($url)->toStartWith($this->redirect.'?code=')->toContain('&state=st4te-abc123');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    $token = fn (array $over = []) => $this->postJson('/v1/connect/token', $over + ['code' => $q['code'], 'code_verifier' => $this->verifier, 'redirect_uri' => $this->redirect]);

    $token(['code_verifier' => str_repeat('z', 48)])->assertStatus(400)->assertJsonPath('error.code', 'invalid_grant');
    $token(['redirect_uri' => 'https://micro.example.com/other'])->assertStatus(400);
    $r = $token()->assertOk()->assertJson(['workspace' => ['id' => $this->ws->id], 'site_url' => 'https://micro.example.com'])->json();
    expect($r['api_key'])->toStartWith('sl_live_')->and($r['api_base'])->toEndWith('/v1');
    $token()->assertStatus(400); // once only
    expect(ApiKey::where('workspace_id', $this->ws->id)->where('name', 'Site: micro.example.com')->value('scope'))->toBe('full');

    // The site's key: its status, and whether it may send from its address.
    $s = $this->withToken($r['api_key'])->getJson('/v1/connect/status?from=hello@example.com')->assertOk()->json();
    expect($s['connection']['site_url'])->toBe('https://micro.example.com')->and($s['from'])->toBe(['address' => 'hello@example.com', 'allowed' => true])->and($s['sending']['ready'])->toBeTrue();
    expect($this->withToken($r['api_key'])->getJson('/v1/connect/status?from=hello@other.com')->json('from.allowed'))->toBeFalse();
});

it('expires codes, replaces an earlier key on reconnecting, and disconnects from either side', function () {
    ready($this);
    $code = function () {
        parse_str((string) parse_url(approve($this)->json('redirect'), PHP_URL_QUERY), $q);

        return $q['code'];
    };
    $exchange = fn (string $c) => $this->postJson('/v1/connect/token', ['code' => $c, 'code_verifier' => $this->verifier, 'redirect_uri' => $this->redirect]);

    $late = $code();
    $this->travel(6)->minutes();
    $exchange($late)->assertStatus(400);
    $this->travelBack();

    $first = $exchange($code())->assertOk()->json('api_key');
    $second = $exchange($code())->assertOk()->json('api_key');
    $this->withToken($first)->getJson('/v1/connect/status')->assertStatus(401); // replaced
    $this->withToken($second)->getJson('/v1/connect/status')->assertOk();

    $sites = $this->getJson("/v1/workspaces/{$this->ws->id}/connections")->assertOk()->json('sites');
    expect($sites)->toHaveCount(1)->and($sites[0])->toMatchArray(['name' => 'Micro Lemon', 'site_url' => 'https://micro.example.com']);
    $this->deleteJson("/v1/workspaces/{$this->ws->id}/connections/{$sites[0]['id']}")->assertOk();
    $this->withToken($second)->getJson('/v1/connect/status')->assertStatus(401);
    expect($this->getJson("/v1/workspaces/{$this->ws->id}/connections")->json('sites'))->toBe([]);

    // The site can disconnect itself too.
    $third = $exchange($code())->assertOk()->json('api_key');
    $this->withToken($third)->postJson('/v1/connect/disconnect')->assertOk();
    $this->withToken($third)->getJson('/v1/connect/status')->assertStatus(401);
    expect(SiteConnection::whereNull('revoked_at')->whereNotNull('connected_at')->count())->toBe(0);
});

it('takes the address it reads a connected site’s users from: same host, public HTTPS, secret stored encrypted', function () {
    ready($this);
    $loc = approve($this)->assertOk()->json('redirect');
    parse_str((string) parse_url($loc, PHP_URL_QUERY), $q);
    $key = $this->postJson('/v1/connect/token', ['code' => $q['code'], 'code_verifier' => $this->verifier, 'redirect_uri' => $this->redirect])->assertOk()->json('api_key');
    UrlGuard::$resolver = fn (string $host) => ['93.184.216.34'];

    $hook = fn (string $url) => $this->withToken($key)->postJson('/v1/connect/audience-hook', ['url' => $url]);
    $hook('https://evil.example.org/sendline/audience')->assertStatus(422); // another host
    $hook('http://micro.example.com/sendline/audience')->assertStatus(422); // not https
    $secret = $hook('https://micro.example.com/sendline/audience')->assertOk()->json('secret');
    $c = SiteConnection::where('workspace_id', $this->ws->id)->firstOrFail();
    expect($secret)->toStartWith('whsec_')->and($c->audience_url)->toBe('https://micro.example.com/sendline/audience')
        ->and($c->getRawOriginal('audience_secret'))->not->toBe($secret); // encrypted at rest
    $plain = $this->postJson("/v1/workspaces/{$this->ws->id}/api-keys", ['name' => 'Plain', 'scope' => 'full'])->assertCreated()->json('secret');
    $this->withToken($plain)->postJson('/v1/connect/audience-hook', ['url' => 'https://micro.example.com/sendline/audience'])->assertStatus(422)->assertJsonPath('error.code', 'not_connected');
    UrlGuard::$resolver = null;
});
