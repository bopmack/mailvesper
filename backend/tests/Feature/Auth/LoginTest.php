<?php

use App\Models\AuditLog;

it('tells the page which methods an account has', function () {
    makeUser();
    $this->postJson('/v1/auth/lookup', ['email' => 'Amara@Example.com'])->assertOk()
        ->assertJson(['exists' => true, 'methods' => ['password', 'code']]);
    $this->postJson('/v1/auth/lookup', ['email' => 'nobody@example.com'])->assertOk()->assertJson(['exists' => false]);
});

it('logs in with the right password and starts a session', function () {
    verifiedUser();
    $this->postJson('/v1/auth/login', ['email' => 'AMARA@example.com', 'password' => STRONG_PASSWORD])->assertOk()
        ->assertJsonPath('user.email', 'amara@example.com')->assertJsonPath('next', 'create_workspace');
    $this->getJson('/v1/me')->assertOk();
    expect(AuditLog::where('action', 'auth.login')->exists())->toBeTrue();
});

it('gives the same error for a wrong password and an unknown email', function () {
    makeUser();
    $wrong = $this->postJson('/v1/auth/login', ['email' => 'amara@example.com', 'password' => 'not the password'])->assertStatus(422)->json('error');
    $unknown = $this->postJson('/v1/auth/login', ['email' => 'ghost@example.com', 'password' => 'not the password'])->assertStatus(422)->json('error');
    expect($wrong['code'])->toBe('invalid_credentials')->and($unknown['code'])->toBe('invalid_credentials')
        ->and($wrong['message'])->toBe($unknown['message']);
    $this->getJson('/v1/me')->assertUnauthorized();
});

it('locks sign-in for 15 minutes after 5 failures, then recovers', function () {
    makeUser();
    foreach (range(1, 5) as $i) {
        $this->postJson('/v1/auth/login', ['email' => 'amara@example.com', 'password' => 'wrong password '.$i])->assertStatus(422);
    }
    $this->postJson('/v1/auth/login', ['email' => 'amara@example.com', 'password' => STRONG_PASSWORD])
        ->assertStatus(429)->assertJsonPath('error.code', 'login_locked')->assertHeader('Retry-After');
    $this->travel(16)->minutes();
    $this->postJson('/v1/auth/login', ['email' => 'amara@example.com', 'password' => STRONG_PASSWORD])->assertOk();
});

it('warns when only a few tries are left', function () {
    makeUser();
    foreach (range(1, 3) as $i) {
        $res = $this->postJson('/v1/auth/login', ['email' => 'amara@example.com', 'password' => 'nope '.$i]);
    }
    expect($res->json('error.message'))->toContain('2 tries left');
});

it('refuses password login for social-only accounts', function () {
    $user = makeUser();
    $user->forceFill(['password' => null])->save();
    $this->postJson('/v1/auth/login', ['email' => 'amara@example.com', 'password' => STRONG_PASSWORD])->assertStatus(422)->assertJsonPath('error.code', 'invalid_credentials');
});

it('logs out and ends the session', function () {
    verifiedUser();
    $this->postJson('/v1/auth/login', ['email' => 'amara@example.com', 'password' => STRONG_PASSWORD])->assertOk();
    $this->postJson('/v1/auth/logout')->assertOk();
    $this->app['auth']->forgetGuards();
    $this->getJson('/v1/me')->assertUnauthorized();
});

it('requires sign-in to come from the website', function () {
    makeUser();
    $this->withHeaders(['Origin' => 'https://evil.example', 'Referer' => 'https://evil.example/'])
        ->postJson('/v1/auth/login', ['email' => 'amara@example.com', 'password' => STRONG_PASSWORD])
        ->assertStatus(400)->assertJsonPath('error.code', 'stateless_request');
});

it('locks an account after 25 failures in an hour even when every try comes from a new address', function () {
    makeUser();
    foreach (range(1, 25) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.$i"])->postJson('/v1/auth/login', ['email' => 'amara@example.com', 'password' => 'guess '.$i])->assertStatus(422);
    }
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->postJson('/v1/auth/login', ['email' => 'amara@example.com', 'password' => STRONG_PASSWORD])
        ->assertStatus(429)->assertJsonPath('error.code', 'login_locked');
    $this->travel(61)->minutes();
    $this->postJson('/v1/auth/login', ['email' => 'amara@example.com', 'password' => STRONG_PASSWORD])->assertOk();
});

it('ignores a forged X-Forwarded-For header (no proxy is trusted by default)', function () {
    makeUser();
    foreach (range(1, 5) as $i) {
        $this->withHeader('X-Forwarded-For', "198.51.100.$i")->postJson('/v1/auth/login', ['email' => 'amara@example.com', 'password' => 'guess '.$i])->assertStatus(422);
    }
    $this->withHeader('X-Forwarded-For', '198.51.100.99')->postJson('/v1/auth/login', ['email' => 'amara@example.com', 'password' => STRONG_PASSWORD])->assertStatus(429);
});
