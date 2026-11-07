<?php

use App\Exceptions\ApiException;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Platform\Settings;
use App\Services\Sending\Mailer;
use App\Services\Ses\DnsLookup;
use App\Support\Edition;
use App\Support\Installation;
use Illuminate\Support\Facades\Mail;
use Tests\Support\FakeAws;
use Tests\Support\FakeDns;

// Community: one admin login (the first account) that can run many workspaces. Pro features aren't here at all.

it('never lets anyone sign up, not even before the installer has created the admin', function () {
    Mail::fake();
    $this->getJson('/v1/auth/setup')->assertOk()->assertExactJson(['edition' => 'community', 'signup_open' => false, 'first_run' => true,
        'links' => ['privacy' => null, 'terms' => null, 'support_email' => null],
        'brand' => ['name' => 'MailVesper', 'accent' => null, 'company' => null, 'address' => null, 'logo' => null, 'logo_dark' => null, 'icon' => null, 'custom' => false]]);
    $this->postJson('/v1/auth/signup', ['name' => 'Eve', 'email' => 'eve@example.com', 'password' => STRONG_PASSWORD])
        ->assertForbidden()->assertJsonPath('error.code', 'signup_closed');
    expect(User::count())->toBe(0);
    Mail::assertNothingSent();
});

it('closes sign-up once the admin exists', function () {
    verifiedUser(['email' => 'ada@example.com']);
    $this->getJson('/v1/auth/setup')->assertOk()->assertJson(['signup_open' => false, 'first_run' => false]);
    $this->postJson('/v1/auth/signup', ['name' => 'Eve', 'email' => 'eve@example.com', 'password' => STRONG_PASSWORD])
        ->assertForbidden()->assertJsonPath('error.code', 'signup_closed');
    expect(User::count())->toBe(1);
});

it('runs many workspaces from the one admin login', function () {
    $admin = verifiedUser(['email' => 'ada@example.com']);
    $this->actingAs($admin);
    foreach (['acme' => 'Acme', 'globex' => 'Globex', 'initech' => 'Initech'] as $slug => $name) {
        $this->postJson('/v1/workspaces', ['name' => $name, 'slug' => $slug])->assertCreated();
    }
    $me = $this->getJson('/v1/me')->assertOk();
    expect(collect($me->json('workspaces'))->pluck('name')->all())->toBe(['Acme', 'Globex', 'Initech'])
        ->and($me->json('invitations'))->toBe([]);
    $globex = Workspace::where('slug', 'globex')->firstOrFail();
    $this->postJson("/v1/workspaces/{$globex->id}/switch")->assertOk()->assertJsonPath('current_workspace_id', $globex->id);
});

it('has no Pro features: their endpoints don’t exist, and /me says Community with the upgrade link', function () {
    $admin = verifiedUser(['email' => 'ada@example.com']);
    $ws = Workspace::create(['name' => 'Acme', 'slug' => 'acme', 'owner_id' => $admin->id]);
    $ws->members()->attach($admin->id, ['role' => 'owner']);
    $this->actingAs($admin);
    foreach (['get' => ['agent', 'members', 'inbox-tests', 'seed-mailboxes'], 'post' => ['agent/messages', 'invitations', 'copilot/ask', 'transfer', 'leave']] as $verb => $paths) {
        foreach ($paths as $p) {
            $this->json($verb, "/v1/workspaces/{$ws->id}/$p")->assertNotFound();
        }
    }
    $this->getJson('/v1/pro/assets/agent.js')->assertNotFound();
    $e = $this->getJson('/v1/me')->assertOk()->json('edition');
    expect($e)->toMatchArray(['name' => 'community', 'features' => [], 'scripts' => [], 'license' => null])
        ->and($e['upgrade_url'])->toStartWith('https://');
    expect(app(Edition::class)->has('agent'))->toBeFalse();
});

it('keeps Copilot’s findings in Community, with the chat marked as Pro', function () {
    [$ws] = sesWorkspace($this);
    $c = $this->getJson("/v1/workspaces/{$ws->id}/copilot")->assertOk()->json('copilot');
    expect($c)->toHaveKeys(['score', 'findings', 'passed'])->and($c['ai'])->toBe(['available' => false, 'pro' => false]);
});

it('treats the first account as the installation admin until admins are named', function () {
    $first = verifiedUser(['email' => 'first@example.com']);
    $owner = verifiedUser(['email' => 'owner@example.com']);
    expect(Installation::isAdmin($first))->toBeTrue()->and(Installation::isAdmin($owner))->toBeFalse();
    $this->artisan('platform:admin', ['email' => 'owner@example.com'])->assertSuccessful();
    expect(Installation::isAdmin($owner))->toBeTrue()->and(Installation::isAdmin($first))->toBeFalse()
        ->and(Installation::adminId())->toBe($owner->id);
    $this->artisan('platform:admin', ['email' => 'nobody@example.com'])->assertFailed();
    $this->artisan('platform:admin', ['email' => 'owner@example.com', '--remove' => true])->assertSuccessful();
    expect(Installation::isAdmin($first))->toBeTrue();
});

it('keeps a suspended account out: no sign-in, and signed out on its next request', function () {
    verifiedUser(['email' => 'admin@example.com']);
    $u = verifiedUser(['email' => 'kemi@example.com']);
    $this->postJson('/v1/auth/login', ['email' => 'kemi@example.com', 'password' => STRONG_PASSWORD])->assertOk();
    $u->forceFill(['suspended_at' => now(), 'suspended_reason' => 'Spam'])->save();
    app('auth')->forgetGuards(); // a new request loads the account afresh, as in production
    $this->getJson('/v1/me')->assertStatus(403)->assertJsonPath('error.code', 'account_suspended')->assertJsonPath('error.message', 'This account is suspended: Spam Contact the platform’s support.');
    app('auth')->forgetGuards();
    $this->getJson('/v1/me')->assertUnauthorized();
    $this->postJson('/v1/auth/login', ['email' => 'kemi@example.com', 'password' => STRONG_PASSWORD])->assertStatus(403)->assertJsonPath('error.code', 'account_suspended');
});

it('refuses to send from a workspace whose sending the platform paused, and says why', function () {
    $this->aws = FakeAws::install();
    $this->app->instance(DnsLookup::class, new FakeDns);
    [$ws] = sesWorkspace($this);
    sesConnect($this, $ws)->assertOk();
    $ws->forceFill(['suspended_at' => now(), 'suspended_reason' => 'High bounce rate: please clean your list'])->save();
    $this->getJson('/v1/me')->assertJsonPath('workspace_suspended', 'High bounce rate: please clean your list');
    $mailer = app(Mailer::class);
    $p = $mailer->validate($ws, ['from' => 'news@acme.com', 'to' => 'x@gmail.com', 'subject' => 'Hi', 'html' => '<p>Hi</p>']);
    expect(fn () => $mailer->submit($ws->fresh(), [$p], 'api'))->toThrow(ApiException::class, 'Sending is paused for this workspace by the platform: High bounce rate');
});

it('shows the operator\'s own privacy, terms and help links, and only safe ones', function () {
    Settings::set('site.privacy_url', 'https://example.com/privacy');
    Settings::set('site.terms_url', 'javascript:alert(1)');
    Settings::set('site.support_email', 'help@example.com');
    $this->getJson('/v1/auth/setup')->assertOk()->assertJsonPath('links', ['privacy' => 'https://example.com/privacy', 'terms' => null, 'support_email' => 'help@example.com']);

    Settings::set('site.terms_url', 'https://example.com/terms" onclick="x');
    Settings::set('site.support_email', 'not an email');
    $this->getJson('/v1/auth/setup')->assertJsonPath('links.terms', null)->assertJsonPath('links.support_email', null);
});
