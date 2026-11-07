<?php

use App\Models\Message;
use App\Models\Suppression;
use App\Services\Ses\DnsLookup;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeAws;
use Tests\Support\FakeDns;

beforeEach(function () {
    $this->aws = FakeAws::install();
    $this->app->instance(DnsLookup::class, new FakeDns);
    [$this->ws, $this->people] = sesWorkspace($this, ['member', 'viewer']);
    sesConnect($this, $this->ws)->assertOk();
});

function switchUser($test, string $email): void
{
    app('auth')->forgetGuards();
    $test->postJson('/v1/auth/logout');
    app('auth')->forgetGuards();
    $test->flushHeaders()->withHeaders(['Origin' => 'http://localhost', 'Referer' => 'http://localhost/login/', 'Accept' => 'application/json']);
    $test->postJson('/v1/auth/login', ['email' => $email, 'password' => STRONG_PASSWORD])->assertOk();
}

it('creates an API key, shows the secret once, and revokes it', function () {
    $r = $this->postJson("/v1/workspaces/{$this->ws->id}/api-keys", ['name' => 'Production', 'scope' => 'send'])->assertCreated();
    $secret = $r->json('secret');
    expect($secret)->toStartWith('sl_live_')->and(strlen($secret))->toBe(48);
    $this->getJson("/v1/workspaces/{$this->ws->id}/api-keys")->assertOk()->assertJsonCount(1, 'keys')->assertDontSee($secret)->assertJsonPath('keys.0.prefix', substr($secret, 0, 12));

    // The key works for the public API…
    $this->withHeaders(['Authorization' => "Bearer $secret"])->postJson('/v1/emails', ['from' => 'news@acme.com', 'to' => 'a@example.com', 'subject' => 'x', 'text' => 'x'])->assertStatus(202);
    // …until it is revoked.
    switchUser($this, 'owner@example.com');
    $this->deleteJson("/v1/workspaces/{$this->ws->id}/api-keys/".$r->json('key.id'))->assertOk();
    $this->flushHeaders()->withHeaders(['Authorization' => "Bearer $secret", 'Accept' => 'application/json'])->postJson('/v1/emails', ['from' => 'news@acme.com', 'to' => 'a@example.com', 'subject' => 'x', 'text' => 'x'])->assertUnauthorized();
    expect(DB::table('audit_logs')->whereIn('action', ['api_key.created', 'api_key.revoked'])->count())->toBe(2);
});

it('limits API keys to owners and admins', function () {
    switchUser($this, 'member@example.com');
    $this->getJson("/v1/workspaces/{$this->ws->id}/api-keys")->assertForbidden();
    $this->postJson("/v1/workspaces/{$this->ws->id}/api-keys", ['name' => 'x'])->assertForbidden();
});

it('manages suppressions: add, search, filter, remove, export', function () {
    $this->postJson("/v1/workspaces/{$this->ws->id}/suppressions", ['emails' => ['A@x.com', 'a@x.com', 'b@x.com', 'nope']])->assertOk()->assertJson(['added' => 2, 'already' => 0, 'invalid' => 1]);
    Suppression::add($this->ws->id, 'spam@x.com', 'complaint');
    $r = $this->getJson("/v1/workspaces/{$this->ws->id}/suppressions?q=a@")->assertOk();
    expect($r->json('suppressions'))->toHaveCount(1)->and($r->json('counts'))->toBe(['complaint' => 1, 'manual' => 2]);
    expect($this->getJson("/v1/workspaces/{$this->ws->id}/suppressions?reason=complaint")->json('suppressions.0.email'))->toBe('spam@x.com');

    $csv = $this->get("/v1/workspaces/{$this->ws->id}/suppressions/export")->assertOk()->streamedContent();
    expect($csv)->toStartWith("email,reason,detail,added_at\n")->toContain('spam@x.com,complaint');

    $spamId = DB::table('suppressions')->where('email', 'spam@x.com')->value('id');
    switchUser($this, 'member@example.com');
    $this->deleteJson("/v1/workspaces/{$this->ws->id}/suppressions/$spamId")->assertForbidden(); // complaints: owners/admins only
    $aId = DB::table('suppressions')->where('email', 'a@x.com')->value('id');
    $this->deleteJson("/v1/workspaces/{$this->ws->id}/suppressions/$aId")->assertOk();

    switchUser($this, 'viewer@example.com');
    $this->postJson("/v1/workspaces/{$this->ws->id}/suppressions", ['emails' => ['c@x.com']])->assertForbidden();
});

it('sends a test email from the app and lists it', function () {
    $this->postJson("/v1/workspaces/{$this->ws->id}/emails/test", ['from' => 'news@acme.com', 'to' => 'me@example.com', 'first_name' => 'Ada'])->assertStatus(202)->assertJsonPath('source', 'test');
    expect(Message::first())->status->toBe('sent')->html->toContain('Hello Ada');
    $this->getJson("/v1/workspaces/{$this->ws->id}/messages")->assertOk()->assertJsonCount(1, 'messages')->assertJsonPath('messages.0.to', 'me@example.com');

    switchUser($this, 'viewer@example.com');
    $this->postJson("/v1/workspaces/{$this->ws->id}/emails/test", ['from' => 'news@acme.com', 'to' => 'me@example.com'])->assertForbidden();
});
