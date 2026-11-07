<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

it('renames a workspace and changes its URL, keeping the URL rules', function () {
    [$ws] = sesWorkspace($this);
    Workspace::create(['name' => 'Taken', 'slug' => 'taken-co', 'owner_id' => $ws->owner_id]);

    $this->patchJson("/v1/workspaces/{$ws->id}", ['name' => '  Acme Labs ', 'slug' => 'Acme-Labs'])->assertOk()
        ->assertJsonPath('workspace.name', 'Acme Labs')->assertJsonPath('workspace.slug', 'acme-labs')
        ->assertJsonPath('workspaces.0.name', 'Acme Labs'); // /me data comes back, so the switcher updates
    expect($ws->fresh()->slug)->toBe('acme-labs')
        ->and(AuditLog::where('action', 'workspace.updated')->value('meta'))->toMatchArray(['fields' => ['name', 'slug']]);

    $this->patchJson("/v1/workspaces/{$ws->id}", ['slug' => 'taken-co'])->assertStatus(422)->assertJsonPath('error.code', 'slug_unavailable');
    $this->patchJson("/v1/workspaces/{$ws->id}", ['slug' => 'admin'])->assertStatus(422);
    $this->patchJson("/v1/workspaces/{$ws->id}", ['slug' => 'acme-labs'])->assertOk(); // its own URL is fine
    $this->patchJson("/v1/workspaces/{$ws->id}", ['name' => 'A'])->assertStatus(422);
});

it('lets only owners and admins change the workspace, and only members see it', function () {
    [$ws] = sesWorkspace($this, ['admin', 'member']);
    switchTo($this, 'member@example.com');
    $this->patchJson("/v1/workspaces/{$ws->id}", ['name' => 'Hijacked'])->assertForbidden();
    switchTo($this, 'admin@example.com');
    $this->patchJson("/v1/workspaces/{$ws->id}", ['name' => 'Acme by Admin'])->assertOk();

    $stranger = verifiedUser(['email' => 'stranger@example.com']);
    $other = Workspace::create(['name' => 'Theirs', 'slug' => 'theirs', 'owner_id' => $stranger->id]);
    $other->members()->attach($stranger->id, ['role' => 'owner']);
    $this->patchJson("/v1/workspaces/{$other->id}", ['name' => 'Mine now'])->assertNotFound();
    expect($other->fresh()->name)->toBe('Theirs');
});

it('updates the profile name and time zone', function () {
    sesWorkspace($this);
    $this->patchJson('/v1/account', ['name' => 'Olu Owner-Ade', 'timezone' => 'Africa/Lagos'])->assertOk()
        ->assertJsonPath('user.name', 'Olu Owner-Ade')->assertJsonPath('user.timezone', 'Africa/Lagos');
    $this->patchJson('/v1/account', ['timezone' => 'Mars/Olympus'])->assertStatus(422);
    $this->patchJson('/v1/account', ['email' => 'new@example.com'])->assertOk(); // not changeable here
    expect(User::where('email', 'owner@example.com')->exists())->toBeTrue();
});

it('changes the password with the current one and signs out every other session', function () {
    [, $people] = sesWorkspace($this);
    $owner = $people['owner'];
    $other = verifiedUser(['email' => 'someone@example.com']);
    DB::table('sessions')->insert([
        ['id' => 'phone-session', 'user_id' => $owner->id, 'payload' => 'x', 'last_activity' => time()],
        ['id' => 'laptop-session', 'user_id' => $owner->id, 'payload' => 'x', 'last_activity' => time()],
        ['id' => 'not-theirs', 'user_id' => $other->id, 'payload' => 'x', 'last_activity' => time()],
    ]);

    $this->postJson('/v1/account/password', ['current_password' => 'wrong one entirely', 'password' => 'a brand new long password'])
        ->assertStatus(422)->assertJsonPath('error.code', 'wrong_password');
    $this->postJson('/v1/account/password', ['current_password' => STRONG_PASSWORD, 'password' => 'short'])->assertStatus(422);
    markPasswordBreached('breached but long enough');
    $this->postJson('/v1/account/password', ['current_password' => STRONG_PASSWORD, 'password' => 'breached but long enough'])->assertStatus(422);
    $this->postJson('/v1/account/password', ['current_password' => STRONG_PASSWORD, 'password' => STRONG_PASSWORD])->assertStatus(422);

    $this->postJson('/v1/account/password', ['current_password' => STRONG_PASSWORD, 'password' => 'a brand new long password'])
        ->assertOk()->assertJsonPath('other_sessions_ended', 2);
    expect(Hash::check('a brand new long password', $owner->fresh()->password))->toBeTrue()
        ->and(DB::table('sessions')->pluck('id')->all())->toBe(['not-theirs'])
        ->and(AuditLog::where('action', 'auth.password_changed')->exists())->toBeTrue();
    $this->getJson('/v1/me')->assertOk(); // this session stays signed in

    switchTo($this, 'someone@example.com'); // (STRONG_PASSWORD) — the old owner password no longer works
    app('auth')->forgetGuards();
    $this->postJson('/v1/auth/logout');
    app('auth')->forgetGuards();
    $this->postJson('/v1/auth/login', ['email' => 'owner@example.com', 'password' => STRONG_PASSWORD])->assertStatus(422);
    $this->postJson('/v1/auth/login', ['email' => 'owner@example.com', 'password' => 'a brand new long password'])->assertOk();
});

it('tells password-less accounts to use the reset link', function () {
    $social = User::create(['name' => 'Sam Social', 'email' => 'sam@example.com']); // as social sign-in creates it
    $social->markEmailAsVerified();
    $this->actingAs($social)->postJson('/v1/account/password', ['current_password' => 'anything at all', 'password' => 'a brand new long password'])
        ->assertStatus(422)->assertJsonPath('error.code', 'no_password');
});
