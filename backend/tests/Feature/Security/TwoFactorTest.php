<?php

use App\Models\TrustedDevice;
use App\Models\User;
use App\Services\TwoFactor;
use Illuminate\Support\Facades\Cache;
use PragmaRX\Google2FA\Google2FA;

function signIn($test, string $email = 'amara@example.com', string $password = STRONG_PASSWORD)
{
    return $test->postJson('/v1/auth/login', ['email' => $email, 'password' => $password]);
}

function totp(string $secret, int $offsetSteps = 0): string
{
    $g = new Google2FA;

    return $g->oathTotp($secret, $g->getTimestamp() + $offsetSteps);
}

/** Turns on two-step for the signed-in user and returns [secret, recovery codes]. */
function enableTwoFactor($test): array
{
    $setup = $test->postJson('/v1/account/two-factor/setup', ['password' => STRONG_PASSWORD])->assertOk()->json();
    $codes = $test->postJson('/v1/account/two-factor/confirm', ['code' => totp($setup['secret'])])->assertOk()->json('recovery_codes');
    Cache::flush(); // a new time-step for the next code in the same test

    return [$setup['secret'], $codes];
}

function freshLogin($test): void
{
    $test->postJson('/v1/auth/logout')->assertOk();
    app('auth')->forgetGuards();
}

it('requires the password to start setup and returns a QR code', function () {
    verifiedUser();
    signIn($this)->assertOk();
    $this->postJson('/v1/account/two-factor/setup', ['password' => 'wrong password here'])->assertStatus(422)->assertJsonPath('error.code', 'password_incorrect');
    $this->postJson('/v1/account/two-factor/setup', ['password' => STRONG_PASSWORD])->assertOk()
        ->assertJsonStructure(['secret', 'otpauth_url', 'qr_svg'])
        ->assertJsonPath('otpauth_url', fn ($u) => str_starts_with($u, 'otpauth://totp/MailVesper:amara%40example.com?'));
});

it('turns on only after a correct code and gives 10 recovery codes', function () {
    verifiedUser();
    signIn($this)->assertOk();
    $secret = $this->postJson('/v1/account/two-factor/setup', ['password' => STRONG_PASSWORD])->json('secret');
    $this->postJson('/v1/account/two-factor/confirm', ['code' => '000000'])->assertStatus(422);
    expect(User::first()->hasTwoFactor())->toBeFalse();
    $codes = $this->postJson('/v1/account/two-factor/confirm', ['code' => totp($secret)])->assertOk()->json('recovery_codes');
    expect($codes)->toHaveCount(10)->and(User::first()->hasTwoFactor())->toBeTrue();
    $this->getJson('/v1/me')->assertJsonPath('user.two_factor', true)->assertJsonPath('user.recovery_codes_left', 10);
});

it('asks for the second step at sign-in and accepts the current code', function () {
    verifiedUser();
    signIn($this)->assertOk();
    [$secret] = enableTwoFactor($this);
    freshLogin($this);

    signIn($this)->assertOk()->assertExactJson(['mfa_required' => true]);
    $this->getJson('/v1/me')->assertUnauthorized(); // not signed in yet
    $this->postJson('/v1/auth/two-factor', ['code' => '000000'])->assertStatus(422)->assertJsonPath('error.code', 'code_invalid');
    $this->postJson('/v1/auth/two-factor', ['code' => totp($secret)])->assertOk()->assertJsonPath('user.two_factor', true);
    $this->getJson('/v1/me')->assertOk();
});

it('never accepts the same code twice', function () {
    verifiedUser();
    signIn($this)->assertOk();
    [$secret] = enableTwoFactor($this);
    $code = totp($secret);
    freshLogin($this);
    signIn($this)->assertJson(['mfa_required' => true]);
    $this->postJson('/v1/auth/two-factor', ['code' => $code])->assertOk();
    freshLogin($this);
    signIn($this)->assertJson(['mfa_required' => true]);
    $this->postJson('/v1/auth/two-factor', ['code' => $code])->assertStatus(422);
});

it('accepts each recovery code once', function () {
    verifiedUser();
    signIn($this)->assertOk();
    [, $codes] = enableTwoFactor($this);
    freshLogin($this);
    signIn($this)->assertJson(['mfa_required' => true]);
    $this->postJson('/v1/auth/two-factor', ['recovery_code' => strtoupper($codes[0])])->assertOk()->assertJsonPath('user.recovery_codes_left', 9);
    freshLogin($this);
    signIn($this)->assertJson(['mfa_required' => true]);
    $this->postJson('/v1/auth/two-factor', ['recovery_code' => $codes[0]])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['recovery_code']]]);
});

it('ends the pending sign-in after 5 wrong codes', function () {
    verifiedUser();
    signIn($this)->assertOk();
    [$secret] = enableTwoFactor($this);
    freshLogin($this);
    signIn($this)->assertJson(['mfa_required' => true]);
    foreach (range(1, 4) as $_) {
        $this->postJson('/v1/auth/two-factor', ['code' => '000000'])->assertStatus(422);
    }
    $this->postJson('/v1/auth/two-factor', ['code' => '000000'])->assertStatus(429)->assertJsonPath('error.code', 'mfa_locked');
    $this->postJson('/v1/auth/two-factor', ['code' => totp($secret)])->assertStatus(401)->assertJsonPath('error.code', 'mfa_expired');
});

it('expires the pending sign-in after 10 minutes', function () {
    verifiedUser();
    signIn($this)->assertOk();
    [$secret] = enableTwoFactor($this);
    freshLogin($this);
    signIn($this)->assertJson(['mfa_required' => true]);
    $this->travel(11)->minutes();
    $this->postJson('/v1/auth/two-factor', ['code' => totp($secret)])->assertStatus(401)->assertJsonPath('error.code', 'mfa_expired');
});

it('sets an HttpOnly trust cookie and stores only a hash when asked to trust the device', function () {
    verifiedUser();
    signIn($this)->assertOk();
    [$secret] = enableTwoFactor($this);
    freshLogin($this);
    signIn($this)->assertJson(['mfa_required' => true]);
    $res = $this->postJson('/v1/auth/two-factor', ['code' => totp($secret), 'trust_device' => true])->assertOk();
    $cookie = collect($res->headers->getCookies())->first(fn ($c) => $c->getName() === 'sl_trusted');
    expect($cookie)->not->toBeNull()->and($cookie->isHttpOnly())->toBeTrue()
        ->and(TrustedDevice::count())->toBe(1)
        ->and(strlen(TrustedDevice::first()->token_hash))->toBe(64);
});

it('skips the second step on a trusted device', function () {
    $user = verifiedUser();
    signIn($this)->assertOk();
    [$secret] = enableTwoFactor($this);
    $token = app(TwoFactor::class)->trustDevice($user->fresh(), request());
    freshLogin($this);

    $this->withCredentials()->withCookie('sl_trusted', $token);
    signIn($this)->assertOk()->assertJsonPath('user.email', 'amara@example.com'); // no mfa_required
    freshLogin($this);
    $this->withCredentials()->withCookie('sl_trusted', 'not-a-real-token');
    signIn($this)->assertJson(['mfa_required' => true]);
});

it('turns off only with a current code, and forgets trusted devices', function () {
    $user = verifiedUser();
    signIn($this)->assertOk();
    [$secret] = enableTwoFactor($this);
    app(TwoFactor::class)->trustDevice($user->fresh(), request());
    $this->postJson('/v1/account/two-factor/disable', ['code' => '000000'])->assertStatus(422);
    $this->postJson('/v1/account/two-factor/disable', ['code' => totp($secret)])->assertOk()->assertJson(['enabled' => false]);
    expect(User::first()->hasTwoFactor())->toBeFalse()->and(TrustedDevice::count())->toBe(0);
});

it('regenerates recovery codes with a current code', function () {
    verifiedUser();
    signIn($this)->assertOk();
    [$secret, $old] = enableTwoFactor($this);
    $new = $this->postJson('/v1/account/two-factor/recovery-codes', ['code' => totp($secret)])->assertOk()->json('recovery_codes');
    expect($new)->toHaveCount(10)->not->toEqual($old);
});

it('locks two-step for an hour after 15 wrong codes, however many times the password is entered again', function () {
    verifiedUser();
    signIn($this)->assertOk();
    [$secret] = enableTwoFactor($this);
    freshLogin($this);
    foreach (range(1, 3) as $round) {
        signIn($this)->assertOk()->assertJsonPath('mfa_required', true);
        foreach (range(1, 5) as $i) {
            $this->postJson('/v1/auth/two-factor', ['code' => '000000'])->assertStatus($i < 5 ? 422 : 429);
        }
    }
    signIn($this)->assertOk();
    $this->postJson('/v1/auth/two-factor', ['code' => totp($secret)])->assertStatus(429)->assertJsonPath('error.code', 'mfa_locked');
    $this->travel(61)->minutes();
    signIn($this)->assertOk();
    $this->postJson('/v1/auth/two-factor', ['code' => totp($secret)])->assertOk();
});
