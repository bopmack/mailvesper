<?php

use App\Mail\CodeMail;
use Illuminate\Support\Facades\Mail;

beforeEach(fn () => openSignup());

beforeEach(fn () => Mail::fake());

function signUpAndGetCode($test): string
{
    $test->postJson('/v1/auth/signup', ['name' => 'Amara', 'email' => 'amara@example.com', 'password' => STRONG_PASSWORD])->assertCreated();
    $code = null;
    Mail::assertSent(CodeMail::class, function (CodeMail $m) use (&$code) {
        $code = $m->code;

        return true;
    });

    return $code;
}

it('verifies the email with the right code', function () {
    $code = signUpAndGetCode($this);
    $this->postJson('/v1/auth/email/verify', ['code' => $code])->assertOk()
        ->assertJsonPath('user.email_verified', true)->assertJsonPath('next', 'create_workspace');
});

it('rejects a wrong code and locks after 5 wrong attempts', function () {
    $code = signUpAndGetCode($this);
    $wrong = $code === '000000' ? '111111' : '000000';
    foreach (range(1, 4) as $_) {
        $this->postJson('/v1/auth/email/verify', ['code' => $wrong])->assertStatus(422)->assertJsonPath('error.code', 'code_invalid');
    }
    $this->postJson('/v1/auth/email/verify', ['code' => $wrong])->assertStatus(422)->assertJsonPath('error.code', 'code_locked');
    // Even the right code no longer works once locked.
    $this->postJson('/v1/auth/email/verify', ['code' => $code])->assertStatus(422)->assertJsonPath('error.code', 'code_locked');
});

it('expires codes after 10 minutes', function () {
    $code = signUpAndGetCode($this);
    $this->travel(11)->minutes();
    $this->postJson('/v1/auth/email/verify', ['code' => $code])->assertStatus(422)->assertJsonPath('error.code', 'code_expired');
});

it('enforces a 30 second resend cooldown and invalidates the old code', function () {
    $old = signUpAndGetCode($this);
    $this->postJson('/v1/auth/email/resend')->assertStatus(429)->assertJsonPath('error.code', 'code_cooldown')->assertHeader('Retry-After');
    $this->travel(31)->seconds();
    $this->postJson('/v1/auth/email/resend')->assertStatus(202);
    Mail::assertSent(CodeMail::class, 2);
    $this->postJson('/v1/auth/email/verify', ['code' => $old])->assertStatus(422);
});

it('requires a 6-digit code', function () {
    signUpAndGetCode($this);
    $this->postJson('/v1/auth/email/verify', ['code' => '12ab'])->assertStatus(422)->assertJsonPath('error.fields.code', 'Enter the 6-digit code.');
});

it('needs a session to verify', function () {
    $this->postJson('/v1/auth/email/verify', ['code' => '123456'])->assertUnauthorized();
});
