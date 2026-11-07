<?php

use App\Models\Workspace;

function loginAs($test, $user): void
{
    $test->postJson('/v1/auth/login', ['email' => $user->email, 'password' => STRONG_PASSWORD])->assertOk();
}

it('requires a verified email', function () {
    loginAs($this, makeUser());
    $this->postJson('/v1/workspaces', ['name' => 'Acme', 'slug' => 'acme'])->assertStatus(403)->assertJsonPath('error.code', 'email_unverified');
});

it('creates a workspace with the user as owner', function () {
    $user = verifiedUser();
    loginAs($this, $user);
    $this->postJson('/v1/workspaces', ['name' => ' Acme Inc ', 'slug' => 'Acme-Inc', 'send_source' => 'ses'])->assertCreated()
        ->assertJsonPath('workspace.slug', 'acme-inc')
        ->assertJsonPath('workspaces.0.role', 'owner')
        ->assertJsonPath('next', 'app');
    $ws = Workspace::firstOrFail();
    expect($ws->name)->toBe('Acme Inc')->and($ws->send_source)->toBe('ses')
        ->and($ws->trial_ends_at->isFuture())->toBeTrue()
        ->and($user->fresh()->current_workspace_id)->toBe($ws->id);
});

it('checks slugs: format, reserved, taken, available', function () {
    loginAs($this, verifiedUser());
    $this->getJson('/v1/workspaces/slug-check?slug=ab')->assertJson(['available' => false, 'reason' => 'Use 3 to 40 characters.']);
    $this->getJson('/v1/workspaces/slug-check?slug=bad_slug')->assertJson(['available' => false]);
    $this->getJson('/v1/workspaces/slug-check?slug=a--b')->assertJson(['available' => false]);
    $this->getJson('/v1/workspaces/slug-check?slug=-acme')->assertJson(['available' => false]);
    $this->getJson('/v1/workspaces/slug-check?slug=admin')->assertJson(['available' => false, 'reason' => 'That URL is reserved. Try another.']);
    $this->postJson('/v1/workspaces', ['name' => 'Acme', 'slug' => 'acme'])->assertCreated();
    $this->getJson('/v1/workspaces/slug-check?slug=acme')->assertJson(['available' => false, 'reason' => 'That URL is taken. Try another.']);
    $this->getJson('/v1/workspaces/slug-check?slug=lumen-co')->assertJson(['available' => true]);
});

it('rejects a taken slug and an unknown send source', function () {
    loginAs($this, verifiedUser());
    $this->postJson('/v1/workspaces', ['name' => 'Acme', 'slug' => 'acme'])->assertCreated();
    $this->postJson('/v1/workspaces', ['name' => 'Acme 2', 'slug' => 'acme'])->assertStatus(422)->assertJsonPath('error.code', 'slug_unavailable');
    $this->postJson('/v1/workspaces', ['name' => 'Acme 3', 'slug' => 'acme-3', 'send_source' => 'pigeon'])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['send_source']]]);
});
