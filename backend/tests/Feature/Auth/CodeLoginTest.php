<?php

use App\Enums\CodePurpose;
use App\Mail\CodeMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

beforeEach(fn () => Mail::fake());

it('emails a code to existing accounts and signs in with it', function () {
    makeUser();
    $this->postJson('/v1/auth/code/send', ['email' => 'amara@example.com'])->assertStatus(202)->assertJson(['sent' => true]);
    $code = null;
    Mail::assertSent(CodeMail::class, function (CodeMail $m) use (&$code) {
        $code = $m->code;

        return $m->purpose === CodePurpose::Login;
    });

    $this->postJson('/v1/auth/code/verify', ['email' => 'amara@example.com', 'code' => $code])->assertOk()
        ->assertJsonPath('user.email_verified', true); // proving the inbox verifies the email
    expect(User::first()->hasVerifiedEmail())->toBeTrue();
    $this->getJson('/v1/me')->assertOk();
});

it('answers the same for unknown emails and sends nothing', function () {
    $this->postJson('/v1/auth/code/send', ['email' => 'ghost@example.com'])->assertStatus(202)->assertJson(['sent' => true]);
    Mail::assertNothingSent();
});

it('rejects a wrong code', function () {
    makeUser();
    $this->postJson('/v1/auth/code/send', ['email' => 'amara@example.com'])->assertStatus(202);
    $sent = null;
    Mail::assertSent(CodeMail::class, function (CodeMail $m) use (&$sent) {
        $sent = $m->code;

        return true;
    });
    $this->postJson('/v1/auth/code/verify', ['email' => 'amara@example.com', 'code' => $sent === '000000' ? '111111' : '000000'])
        ->assertStatus(422)->assertJsonPath('error.code', 'code_invalid');
    $this->getJson('/v1/me')->assertUnauthorized();
});

it('rejects a code for an email that never requested one', function () {
    makeUser();
    $this->postJson('/v1/auth/code/verify', ['email' => 'amara@example.com', 'code' => '123456'])->assertStatus(422)->assertJsonPath('error.code', 'code_expired');
});
