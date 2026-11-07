<?php

use App\Mail\ResetPasswordMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

beforeEach(fn () => Mail::fake());

function resetUrlFor($test, string $email): string
{
    $test->postJson('/v1/auth/password/forgot', ['email' => $email])->assertStatus(202);
    $url = null;
    Mail::assertSent(ResetPasswordMail::class, function (ResetPasswordMail $m) use (&$url) {
        $url = $m->url;

        return true;
    });

    return $url;
}

it('emails a reset link to the MailVesper login page', function () {
    makeUser();
    $url = resetUrlFor($this, 'amara@example.com');
    expect($url)->toStartWith('https://send.test/login/?reset=')->toContain('email=amara%40example.com');
});

it('answers the same for unknown emails and sends nothing', function () {
    $this->postJson('/v1/auth/password/forgot', ['email' => 'ghost@example.com'])->assertStatus(202)->assertJson(['sent' => true]);
    Mail::assertNothingSent();
});

it('resets the password, signs out other sessions and allows the new password', function () {
    $user = makeUser();
    DB::table('sessions')->insert(['id' => 'old-session', 'user_id' => $user->id, 'payload' => 'x', 'last_activity' => time()]);
    parse_str((string) parse_url(resetUrlFor($this, 'amara@example.com'), PHP_URL_QUERY), $q);

    $this->postJson('/v1/auth/password/reset', ['email' => 'amara@example.com', 'token' => $q['reset'], 'password' => 'a brand new long passphrase'])->assertOk();
    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);
    $this->postJson('/v1/auth/login', ['email' => 'amara@example.com', 'password' => STRONG_PASSWORD])->assertStatus(422);
    $this->postJson('/v1/auth/login', ['email' => 'amara@example.com', 'password' => 'a brand new long passphrase'])->assertOk();
});

it('rejects invalid and expired tokens', function () {
    makeUser();
    parse_str((string) parse_url(resetUrlFor($this, 'amara@example.com'), PHP_URL_QUERY), $q);
    $this->postJson('/v1/auth/password/reset', ['email' => 'amara@example.com', 'token' => 'bogus', 'password' => 'a brand new long passphrase'])
        ->assertStatus(422)->assertJsonPath('error.code', 'reset_invalid');
    $this->travel(31)->minutes();
    $this->postJson('/v1/auth/password/reset', ['email' => 'amara@example.com', 'token' => $q['reset'], 'password' => 'a brand new long passphrase'])
        ->assertStatus(422)->assertJsonPath('error.code', 'reset_invalid');
});

it('uses a reset token only once', function () {
    makeUser();
    parse_str((string) parse_url(resetUrlFor($this, 'amara@example.com'), PHP_URL_QUERY), $q);
    $body = ['email' => 'amara@example.com', 'token' => $q['reset'], 'password' => 'a brand new long passphrase'];
    $this->postJson('/v1/auth/password/reset', $body)->assertOk();
    $this->postJson('/v1/auth/password/reset', $body)->assertStatus(422);
});
