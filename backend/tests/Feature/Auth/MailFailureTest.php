<?php

use App\Mail\CodeMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;

beforeEach(fn () => openSignup());

function failingMailer(): void
{
    $pending = Mockery::mock();
    $pending->shouldReceive('send')->andThrow(new TransportException('550 The mail server could not deliver mail'));
    Mail::shouldReceive('to')->andReturn($pending);
}

it('keeps the new account when the verification email bounces', function () {
    failingMailer();
    $this->postJson('/v1/auth/signup', ['name' => 'Amara', 'email' => 'amara@example.com', 'password' => STRONG_PASSWORD])
        ->assertCreated()->assertJsonPath('code_delivered', false)->assertJsonPath('next', 'verify_email');
    expect(User::count())->toBe(1);
});

it('still answers 202 for code and reset requests when delivery fails', function () {
    makeUser();
    failingMailer();
    $this->postJson('/v1/auth/code/send', ['email' => 'amara@example.com'])->assertStatus(202);
    $this->postJson('/v1/auth/password/forgot', ['email' => 'amara@example.com'])->assertStatus(202);
});

it('reports delivery on success', function () {
    Mail::fake();
    $this->postJson('/v1/auth/signup', ['name' => 'Amara', 'email' => 'amara@example.com', 'password' => STRONG_PASSWORD])->assertJsonPath('code_delivered', true);
    Mail::assertSent(CodeMail::class);
});
