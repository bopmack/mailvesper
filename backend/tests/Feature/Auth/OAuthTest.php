<?php

use App\Http\Controllers\Api\V1\Auth\OAuthController;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Edition;
use Illuminate\Support\Facades\Cache;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\SocialiteManager;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as SocialiteUser;

function configureProvider(string $p): void
{
    config(["services.$p.client_id" => 'test-client', "services.$p.client_secret" => 'test-secret', "services.$p.redirect" => "http://localhost/v1/auth/oauth/$p/callback"]);
}

/** Per-test fake provider responses: provider => Socialite user or Throwable. */
$GLOBALS['oauthFakes'] = [];

beforeEach(function () {
    openSignup();
    $GLOBALS['oauthFakes'] = [];
    Socialite::shouldReceive('driver')->andReturnUsing(function (string $provider) {
        $driver = Mockery::mock(AbstractProvider::class);
        $driver->shouldReceive('stateless')->andReturnSelf();
        $fake = $GLOBALS['oauthFakes'][$provider] ?? new RuntimeException("no fake user for $provider");
        $fake instanceof Throwable ? $driver->shouldReceive('user')->andThrow($fake) : $driver->shouldReceive('user')->andReturn($fake);

        return $driver;
    })->byDefault();
});

/** Make the provider return this user, and create a valid one-time state for the callback. */
function fakeProviderUser(string $provider, array $user, array $raw = [], array $state = []): string
{
    $GLOBALS['oauthFakes'][$provider] = (new SocialiteUser)->setRaw($raw + ['email' => $user['email'] ?? null])->map($user);
    $token = 'state'.Str::random(30);
    Cache::put('oauth:state:'.$token, $state + ['provider' => $provider, 'intent' => 'login', 'invite' => null, 'next' => null], 600);
    test()->withCookie('sl_oauth', $token); // the browser that started the flow

    return $token;
}

it('lists which providers are switched on', function () {
    $this->getJson('/v1/auth/providers')->assertOk()->assertExactJson(['google' => false, 'microsoft' => false, 'github' => false, 'apple' => false]);
    configureProvider('google');
    $this->getJson('/v1/auth/providers')->assertJsonPath('google', true);
});

it('sends people back with a clear message when a provider is not configured', function () {
    $this->get('/v1/auth/oauth/google/redirect?intent=signup')->assertRedirect('https://send.test/signup/?oauth_error=not_configured&provider=google');
    $this->getJson('/v1/auth/oauth/myspace/redirect')->assertNotFound();
});

it('redirects to the provider with a one-time state', function () {
    Socialite::swap(new SocialiteManager(app())); // the real driver builds the provider URL
    configureProvider('google');
    $res = $this->get('/v1/auth/oauth/google/redirect?next=/app/%23/campaigns&invite=abc');
    $location = $res->headers->get('Location');
    expect($location)->toStartWith('https://accounts.google.com/o/oauth2/auth?');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $q);
    $state = Cache::get('oauth:state:'.$q['state']);
    expect($state['provider'])->toBe('google')->and($state['next'])->toBe('/app/#/campaigns')->and($state['invite'])->toBeNull(); // 'abc' is not a valid token shape
    $cookie = $res->getCookie('sl_oauth'); // decrypted, as the app reads it
    expect($cookie->getValue())->toBe($q['state'])->and($cookie->getSameSite())->toBe('none')->and($cookie->isSecure())->toBeTrue()->and($cookie->isHttpOnly())->toBeTrue();
});

it('scopes the state cookie to where the browser sees the API', function () {
    config(['app.url' => 'https://send.example.com/api']);
    expect(OAuthController::cookiePath())->toBe('/api/v1/auth/oauth');
    config(['app.url' => 'https://api.example.com']);
    expect(OAuthController::cookiePath())->toBe('/v1/auth/oauth');
});

it('refuses a callback link opened in a browser that didn’t start the sign-in (login CSRF)', function () {
    $state = fakeProviderUser('google', ['id' => 'g-attacker', 'name' => 'Mallory', 'email' => 'mallory@example.com'], ['email_verified' => true]);
    $this->withCookie('sl_oauth', 'some-other-flow');
    $this->get("/v1/auth/oauth/google/callback?state=$state&code=x")->assertRedirect('https://send.test/login/?oauth_error=invalid_state&provider=google');
    expect(User::where('email', 'mallory@example.com')->exists())->toBeFalse();
});

it('never redirects outside the app after sign-in', function () {
    Socialite::swap(new SocialiteManager(app())); // the real driver builds the provider URL
    configureProvider('google');
    $location = $this->get('/v1/auth/oauth/google/redirect?next=https://evil.example/')->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $q);
    expect(Cache::get('oauth:state:'.$q['state'])['next'])->toBeNull();
});

it('rejects a missing, forged or reused state', function () {
    $this->get('/v1/auth/oauth/google/callback?state=forged&code=x')->assertRedirect('https://send.test/login/?oauth_error=invalid_state&provider=google');
    $state = fakeProviderUser('google', ['id' => 'g1', 'name' => 'Amara', 'email' => 'amara@example.com'], ['email_verified' => true]);
    $this->get("/v1/auth/oauth/google/callback?state=$state&code=x")->assertRedirect();
    $this->get("/v1/auth/oauth/google/callback?state=$state&code=x")->assertRedirect('https://send.test/login/?oauth_error=invalid_state&provider=google');
});

it('creates a verified account from Google and continues sign-up', function () {
    $state = fakeProviderUser('google', ['id' => 'g1', 'name' => 'Amara Okafor', 'email' => 'Amara@Example.com'], ['email_verified' => true]);
    $this->get("/v1/auth/oauth/google/callback?state=$state&code=x")->assertRedirect('https://send.test/signup/');
    $user = User::where('email', 'amara@example.com')->firstOrFail();
    expect($user->hasVerifiedEmail())->toBeTrue()->and($user->password)->toBeNull()->and($user->name)->toBe('Amara Okafor')
        ->and(SocialAccount::where('provider', 'google')->where('provider_user_id', 'g1')->exists())->toBeTrue();
    $this->getJson('/v1/me')->assertOk()->assertJsonPath('next', 'create_workspace');
});

it('links Google to an existing account when the email is verified, and goes to the app', function () {
    $user = verifiedUser();
    $ws = Workspace::create(['name' => 'Acme', 'slug' => 'acme', 'owner_id' => $user->id]);
    $ws->members()->attach($user->id, ['role' => 'owner']);
    $state = fakeProviderUser('google', ['id' => 'g1', 'name' => 'Amara', 'email' => 'amara@example.com'], ['email_verified' => true], ['next' => '/app/#/campaigns']);
    $this->get("/v1/auth/oauth/google/callback?state=$state&code=x")->assertRedirect('https://send.test/app/#/campaigns');
    expect($user->socialAccounts()->count())->toBe(1);
});

it('refuses to link an existing account when the provider has not verified the email', function () {
    makeUser();
    $state = fakeProviderUser('google', ['id' => 'g1', 'name' => 'Mallory', 'email' => 'amara@example.com'], ['email_verified' => false]);
    $this->get("/v1/auth/oauth/google/callback?state=$state&code=x")->assertRedirect('https://send.test/login/?oauth_error=link_requires_login&provider=google');
    expect(SocialAccount::count())->toBe(0);
    $this->getJson('/v1/me')->assertUnauthorized();
});

it('never auto-links Microsoft, and makes new Microsoft accounts verify their email', function () {
    makeUser();
    $state = fakeProviderUser('microsoft', ['id' => 'm1', 'name' => 'Someone', 'email' => 'amara@example.com']);
    $this->get("/v1/auth/oauth/microsoft/callback?state=$state&code=x")->assertRedirect('https://send.test/login/?oauth_error=link_requires_login&provider=microsoft');

    $state = fakeProviderUser('microsoft', ['id' => 'm2', 'name' => 'New Person', 'email' => 'new@example.com']);
    $this->get("/v1/auth/oauth/microsoft/callback?state=$state&code=x")->assertRedirect('https://send.test/signup/');
    expect(User::where('email', 'new@example.com')->first()->hasVerifiedEmail())->toBeFalse();
    $this->getJson('/v1/me')->assertJsonPath('next', 'verify_email');
});

it('recognises a returning user by provider id even if their email changed', function () {
    $user = verifiedUser();
    SocialAccount::create(['user_id' => $user->id, 'provider' => 'github', 'provider_user_id' => '42', 'email' => 'amara@example.com']);
    $state = fakeProviderUser('github', ['id' => '42', 'name' => 'Amara', 'email' => 'amara.new@example.com']);
    $this->get("/v1/auth/oauth/github/callback?state=$state&code=x")->assertRedirect();
    $this->getJson('/v1/me')->assertJsonPath('user.email', 'amara@example.com');
    expect(User::count())->toBe(1);
});

it('still requires two-step verification after social sign-in', function () {
    $user = verifiedUser();
    $user->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()])->save();
    $state = fakeProviderUser('google', ['id' => 'g1', 'name' => 'Amara', 'email' => 'amara@example.com'], ['email_verified' => true]);
    $this->get("/v1/auth/oauth/google/callback?state=$state&code=x")->assertRedirect('https://send.test/login/?step=mfa');
    $this->getJson('/v1/me')->assertUnauthorized();
});

it('handles a cancelled sign-in and a provider failure', function () {
    $state = fakeProviderUser('google', ['id' => 'g1', 'email' => 'a@example.com'], [], ['intent' => 'signup']);
    $this->get("/v1/auth/oauth/google/callback?state=$state&error=access_denied")->assertRedirect('https://send.test/signup/?oauth_error=cancelled&provider=google');

    $GLOBALS['oauthFakes']['google'] = new RuntimeException('token exchange failed');
    Cache::put('oauth:state:s2', ['provider' => 'google', 'intent' => 'login', 'invite' => null, 'next' => null], 600);
    $this->withCookie('sl_oauth', 's2');
    $this->get('/v1/auth/oauth/google/callback?state=s2&code=x')->assertRedirect('https://send.test/login/?oauth_error=provider_error&provider=google');
});

it('refuses to create a new account by social sign-in when sign-up is closed, but still signs in existing ones', function () {
    app(Edition::class)->signupPolicy(fn (?string $email) => false);
    configureProvider('google');
    $state = fakeProviderUser('google', ['id' => 'g1', 'name' => 'New', 'email' => 'new@example.com'], ['email_verified' => true]);
    $this->get("/v1/auth/oauth/google/callback?state=$state&code=x")->assertRedirect('https://send.test/login/?oauth_error=signup_closed&provider=google');
    expect(User::where('email', 'new@example.com')->exists())->toBeFalse();

    verifiedUser(['email' => 'old@example.com']);
    $state = fakeProviderUser('google', ['id' => 'g2', 'name' => 'Old', 'email' => 'old@example.com'], ['email_verified' => true]);
    $this->get("/v1/auth/oauth/google/callback?state=$state&code=x")->assertRedirect();
    expect(User::where('email', 'old@example.com')->first()->socialAccounts()->count())->toBe(1);
});
