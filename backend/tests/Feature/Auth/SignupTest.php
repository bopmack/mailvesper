<?php

use App\Enums\CodePurpose;
use App\Mail\CodeMail;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

beforeEach(fn () => openSignup());

beforeEach(fn () => Mail::fake());

it('creates an account, signs the user in and emails a verification code', function () {
    $this->postJson('/v1/auth/signup', ['name' => '  Ayodele A. ', 'email' => ' Ayodele@Acme-Corp.IO ', 'password' => STRONG_PASSWORD])
        ->assertCreated()
        ->assertJsonPath('user.email', 'ayodele@acme-corp.io')
        ->assertJsonPath('user.name', 'Ayodele A.')
        ->assertJsonPath('user.email_verified', false)
        ->assertJsonPath('next', 'verify_email');

    $user = User::where('email', 'ayodele@acme-corp.io')->firstOrFail();
    expect(Hash::check(STRONG_PASSWORD, $user->password))->toBeTrue()
        ->and($user->password)->toStartWith('$argon2id$');
    Mail::assertSent(CodeMail::class, fn (CodeMail $m) => $m->hasTo('ayodele@acme-corp.io') && $m->purpose === CodePurpose::VerifyEmail && preg_match('/^\d{6}$/', $m->code));
    $this->getJson('/v1/me')->assertOk()->assertJsonPath('user.email', 'ayodele@acme-corp.io');
    expect(AuditLog::where('action', 'auth.signup')->count())->toBe(1);
});

it('rejects an email that already has an account', function () {
    makeUser();
    $this->postJson('/v1/auth/signup', ['name' => 'Amara', 'email' => 'AMARA@example.com', 'password' => STRONG_PASSWORD])
        ->assertStatus(422)->assertJsonPath('error.code', 'email_taken')->assertJsonStructure(['error' => ['fields' => ['email']]]);
    Mail::assertNothingSent();
});

it('requires at least 12 characters', function () {
    $this->postJson('/v1/auth/signup', ['name' => 'Amara', 'email' => 'new@example.com', 'password' => 'short pass'])
        ->assertStatus(422)->assertJsonPath('error.fields.password', 'Use at least 12 characters.');
});

it('rejects passwords found in data breaches', function () {
    markPasswordBreached('password123456');
    $this->postJson('/v1/auth/signup', ['name' => 'Amara', 'email' => 'new@example.com', 'password' => 'password123456'])
        ->assertStatus(422)->assertJsonPath('error.fields.password', 'This password appears in known data breaches. Choose a different one.');
    expect(User::count())->toBe(0);
});

it('validates name, email and timezone', function () {
    $this->postJson('/v1/auth/signup', ['name' => 'A', 'email' => 'not-an-email', 'password' => STRONG_PASSWORD, 'timezone' => 'Mars/Base'])
        ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['name', 'email', 'timezone']]]);
});
