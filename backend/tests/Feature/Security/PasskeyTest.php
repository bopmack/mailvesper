<?php

use App\Models\AuditLog;
use App\Models\Passkey;
use App\Models\User;
use Tests\Support\SoftAuthenticator;

function pkSignIn($test): void
{
    $test->postJson('/v1/auth/login', ['email' => 'amara@example.com', 'password' => STRONG_PASSWORD])->assertOk();
}

function pkSignOut($test): void
{
    $test->postJson('/v1/auth/logout')->assertOk();
    app('auth')->forgetGuards();
}

/** Signs in with a password and registers $device as a passkey. */
function pkRegister($test, SoftAuthenticator $device, string $name = 'My MacBook'): string
{
    $options = $test->postJson('/v1/account/passkeys/options', ['password' => STRONG_PASSWORD])->assertOk()->json();

    return $test->postJson('/v1/account/passkeys', ['name' => $name] + $device->create($options))->assertCreated()->json('id');
}

it('registers a real passkey after confirming the password', function () {
    verifiedUser();
    pkSignIn($this);
    $this->postJson('/v1/account/passkeys/options', ['password' => 'not my password'])->assertStatus(422)->assertJsonPath('error.code', 'password_incorrect');

    $options = $this->postJson('/v1/account/passkeys/options', ['password' => STRONG_PASSWORD])->assertOk()->json();
    expect($options['publicKey']['rp']['id'])->toBe('send.test')
        ->and($options['publicKey']['authenticatorSelection']['userVerification'])->toBe('required')
        ->and($options['publicKey']['authenticatorSelection']['residentKey'])->toBe('required');

    $device = new SoftAuthenticator;
    $this->postJson('/v1/account/passkeys', ['name' => 'My MacBook'] + $device->create($options))->assertCreated()->assertJsonPath('name', 'My MacBook');

    $this->getJson('/v1/account/passkeys')->assertOk()->assertJsonCount(1, 'passkeys')->assertJsonMissingPath('passkeys.0.public_key');
    expect(AuditLog::where('action', 'security.passkey_added')->exists())->toBeTrue();
    $this->postJson('/v1/auth/lookup', ['email' => 'amara@example.com'])->assertJsonPath('methods', fn ($m) => in_array('passkey', $m, true));
});

it('signs in with the passkey alone, even when two-step is on', function () {
    $user = verifiedUser();
    $user->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()])->save();
    $device = new SoftAuthenticator;
    $this->postJson('/v1/auth/login', ['email' => 'amara@example.com', 'password' => STRONG_PASSWORD]);
    // password login stops at the second step; finish setup directly to keep the test focused
    $this->actingAs($user, 'web');
    pkRegister($this, $device);
    pkSignOut($this);

    $options = $this->postJson('/v1/auth/passkey/options')->assertOk()->json();
    expect($options['publicKey']['userVerification'])->toBe('required');
    $this->postJson('/v1/auth/passkey', $device->get($options))->assertOk()->assertJsonPath('user.email', 'amara@example.com')->assertJsonMissingPath('two_factor_required');
    $this->getJson('/v1/me')->assertOk();

    $key = Passkey::first();
    expect($key->sign_count)->toBe(1)->and($key->last_used_at)->not->toBeNull();
    expect(AuditLog::where('action', 'auth.login')->latest('id')->first()->meta)->toMatchArray(['method' => 'passkey', 'two_factor' => true]);
});

it('refuses a replayed challenge', function () {
    verifiedUser();
    pkSignIn($this);
    $device = new SoftAuthenticator;
    pkRegister($this, $device);
    pkSignOut($this);

    $assertion = $device->get($this->postJson('/v1/auth/passkey/options')->json());
    $this->postJson('/v1/auth/passkey', $assertion)->assertOk();
    pkSignOut($this);
    $this->postJson('/v1/auth/passkey', $assertion)->assertStatus(422)->assertJsonPath('error.code', 'passkey_expired');
});

it('refuses a cloned authenticator whose counter went backwards', function () {
    verifiedUser();
    pkSignIn($this);
    $device = new SoftAuthenticator;
    pkRegister($this, $device);
    pkSignOut($this);
    $device->counter = 5;
    $this->postJson('/v1/auth/passkey', $device->get($this->postJson('/v1/auth/passkey/options')->json()))->assertOk();
    pkSignOut($this);

    $device->counter = 2; // a copy of the key with an older counter
    $this->postJson('/v1/auth/passkey', $device->get($this->postJson('/v1/auth/passkey/options')->json()))->assertStatus(422)->assertJsonPath('error.code', 'passkey_invalid');
    $this->getJson('/v1/me')->assertUnauthorized();
});

it('refuses the wrong site, a missing fingerprint check, a forged signature and unknown keys', function () {
    verifiedUser();
    pkSignIn($this);
    $device = new SoftAuthenticator;
    pkRegister($this, $device);
    pkSignOut($this);

    $phish = new SoftAuthenticator(origin: 'https://send.test.evil.com');
    $phish->credentialId = $device->credentialId;
    $this->postJson('/v1/auth/passkey', $phish->get($this->postJson('/v1/auth/passkey/options')->json()))->assertStatus(422)->assertJsonPath('error.code', 'passkey_invalid');

    $device->userVerified = false;
    $this->postJson('/v1/auth/passkey', $device->get($this->postJson('/v1/auth/passkey/options')->json()))->assertStatus(422)->assertJsonPath('error.code', 'passkey_invalid');
    $device->userVerified = true;

    $forged = $device->get($this->postJson('/v1/auth/passkey/options')->json());
    $forged['signature'] = (new SoftAuthenticator)->get(['publicKey' => ['challenge' => 'x']])['signature'];
    $this->postJson('/v1/auth/passkey', $forged)->assertStatus(422)->assertJsonPath('error.code', 'passkey_invalid');

    $this->postJson('/v1/auth/passkey', (new SoftAuthenticator)->get($this->postJson('/v1/auth/passkey/options')->json()))->assertStatus(422)->assertJsonPath('error.code', 'passkey_unknown');
    $this->getJson('/v1/me')->assertUnauthorized();
});

it('needs a fresh challenge to register and refuses the same key twice', function () {
    verifiedUser();
    pkSignIn($this);
    $device = new SoftAuthenticator;
    $this->postJson('/v1/account/passkeys', $device->create(['publicKey' => ['challenge' => 'abc']]))->assertStatus(422)->assertJsonPath('error.code', 'passkey_expired');
    pkRegister($this, $device);
    $options = $this->postJson('/v1/account/passkeys/options', ['password' => STRONG_PASSWORD])->json();
    expect($options['publicKey']['excludeCredentials'])->toHaveCount(1);
    $this->postJson('/v1/account/passkeys', $device->create($options))->assertStatus(422)->assertJsonPath('error.code', 'passkey_exists');
});

it('renames and removes only your own passkeys', function () {
    verifiedUser();
    pkSignIn($this);
    $id = pkRegister($this, new SoftAuthenticator);
    $this->patchJson("/v1/account/passkeys/$id", ['name' => 'Work laptop'])->assertOk()->assertJsonPath('name', 'Work laptop');

    pkSignOut($this);
    verifiedUser(['email' => 'other@example.com']);
    $this->postJson('/v1/auth/login', ['email' => 'other@example.com', 'password' => STRONG_PASSWORD])->assertOk();
    $this->deleteJson("/v1/account/passkeys/$id")->assertNotFound();
    expect(Passkey::count())->toBe(1);

    pkSignOut($this);
    pkSignIn($this);
    $this->deleteJson("/v1/account/passkeys/$id")->assertOk();
    expect(Passkey::count())->toBe(0)->and(AuditLog::where('action', 'security.passkey_removed')->exists())->toBeTrue();
});

it('requires a signed-in user to manage passkeys', function () {
    $this->getJson('/v1/account/passkeys')->assertUnauthorized();
    $this->postJson('/v1/account/passkeys/options')->assertUnauthorized();
    expect(User::count())->toBe(0);
});
