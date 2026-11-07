<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Audit;
use App\Services\AuthSession;
use App\Support\Edition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialUser;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Throwable;

/**
 * Sign in with Google, Microsoft, GitHub or Apple.
 *
 * Security rules:
 * - CSRF on the flow is a one-time random `state` kept server-side for 10 minutes, and bound to the browser that
 *   started it by a cookie holding the same value. Without that, someone could finish a sign-in with their own account
 *   and send the callback link to a victim, signing them in as the attacker (login CSRF). The cookie is
 *   SameSite=None because Apple returns with a cross-site POST, which drops Lax cookies.
 * - An existing MailVesper account is linked by email only when the provider asserts the email is verified
 *   (Google, GitHub, Apple). Microsoft can return unverified addresses ("nOAuth"), so it never auto-links;
 *   new Microsoft accounts confirm their email with our 6-digit code.
 * - Two-step verification and invite links apply exactly as for password sign-in.
 */
class OAuthController extends Controller
{
    public const PROVIDERS = ['google', 'microsoft', 'github', 'apple'];

    public const STATE_TTL = 600;

    public const STATE_COOKIE = 'sl_oauth';

    public static function configured(string $provider): bool
    {
        $c = (array) config("services.{$provider}");
        if (empty($c['client_id'])) {
            return false;
        }

        return $provider === 'apple'
            ? ! empty($c['team_id']) && ! empty($c['key_id']) && ! empty($c['private_key'])
            : ! empty($c['client_secret']);
    }

    public function providers(): JsonResponse
    {
        return response()->json(array_combine(self::PROVIDERS, array_map(fn ($p) => self::configured($p), self::PROVIDERS)));
    }

    public function redirect(Request $request, string $provider): RedirectResponse
    {
        $this->known($provider);
        $intent = $request->query('intent') === 'signup' ? 'signup' : 'login';
        if (! self::configured($provider)) {
            return $this->back('not_configured', $provider, $intent);
        }

        $state = Str::random(40);
        $invite = (string) $request->query('invite', '');
        $next = (string) $request->query('next', '');
        Cache::put('oauth:state:'.$state, [
            'provider' => $provider,
            'intent' => $intent,
            'invite' => preg_match('/^[A-Za-z0-9]{20,100}$/', $invite) ? $invite : null,
            'next' => preg_match('#^/app/[^\s]*$#', $next) ? $next : null, // only ever redirect back into the app
        ], self::STATE_TTL);

        $driver = $this->driver($provider)->with(['state' => $state]);
        if ($provider === 'github') {
            $driver->scopes(['user:email']);
        }
        if ($provider === 'apple') {
            $driver->scopes(['name', 'email']);
        }

        return $driver->redirect()->withCookie(cookie(self::STATE_COOKIE, $state, self::STATE_TTL / 60, self::cookiePath(), null, true, true, false, 'none'));
    }

    public function callback(Request $request, string $provider, AuthSession $session, Edition $edition): RedirectResponse
    {
        $this->known($provider);
        $token = (string) $request->input('state', '');
        $browser = (string) $request->cookie(self::STATE_COOKIE, '');
        /** @var array{provider: string, intent: string, invite: string|null, next: string|null}|null $state */
        $state = Cache::pull('oauth:state:'.$token);
        if (! $state || $state['provider'] !== $provider || $browser === '' || ! hash_equals($browser, $token)) {
            return $this->back('invalid_state', $provider);
        }
        cookie()->queue(cookie()->forget(self::STATE_COOKIE, self::cookiePath()));
        if ($request->filled('error')) {
            return $this->back('cancelled', $provider, $state['intent']);
        }

        try {
            $social = $this->driver($provider)->user();
        } catch (Throwable $e) {
            report($e);

            return $this->back('provider_error', $provider, $state['intent']);
        }

        $email = User::normalizeEmail((string) $social->getEmail());
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->back('no_email', $provider, $state['intent']);
        }
        $verified = $this->emailVerified($provider, $social);

        $linked = SocialAccount::where('provider', $provider)->where('provider_user_id', (string) $social->getId())->first();
        if ($linked) {
            $user = $linked->user;
        } else {
            $user = User::where('email', $email)->first();
            if ($user && ! $verified) {
                return $this->back('link_requires_login', $provider, $state['intent']);
            }
            if (! $user && ! $edition->signupOpen($email)) {
                return $this->back('signup_closed', $provider, $state['intent']);
            }
            $user = DB::transaction(function () use ($user, $social, $provider, $email) {
                if (! $user) {
                    $user = User::create(['name' => $this->nameFor($social, $email), 'email' => $email]);
                    Audit::log('auth.signup', $user, meta: ['method' => 'oauth:'.$provider]);
                }
                SocialAccount::create(['user_id' => $user->id, 'provider' => $provider, 'provider_user_id' => (string) $social->getId(), 'email' => $email]);
                Audit::log('security.social_linked', $user, meta: ['provider' => $provider]);

                return $user;
            });
        }
        if ($verified && ! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        $result = $session->start($request, $user, true, 'oauth:'.$provider);
        if (! empty($result['mfa_required'])) {
            return redirect($this->front('/login/', ['step' => 'mfa', 'invite' => $state['invite']]));
        }

        // Pro hooks in here (accepting a team invitation that came with the sign-in).
        $error = $user->hasVerifiedEmail() ? $edition->socialLogin($user, $state) : null;

        $me = AuthSession::payload($user->fresh() ?? $user);
        if ($me['next'] === 'app' && ! $error) {
            return redirect(rtrim((string) config('app.frontend_url'), '/').($state['next'] ?? '/app/'));
        }

        // Unfinished sign-up (verify email, accept or create a workspace): the sign-up page resumes from the session.
        return redirect($this->front('/signup/', ['invite' => $user->hasVerifiedEmail() ? null : $state['invite'], 'oauth_error' => $error]));
    }

    /** Where the browser sees these routes: under APP_URL's path (production serves the API at /api). */
    public static function cookiePath(): string
    {
        return rtrim((string) parse_url((string) config('app.url'), PHP_URL_PATH), '/').'/v1/auth/oauth';
    }

    private function known(string $provider): void
    {
        if (! in_array($provider, self::PROVIDERS, true)) {
            throw new ApiException('not_found', 'Not found.', 404);
        }
    }

    private function driver(string $provider): AbstractProvider
    {
        /** @var AbstractProvider $driver */
        $driver = Socialite::driver($provider);

        return $driver->stateless(); // CSRF is handled by our own one-time state
    }

    private function emailVerified(string $provider, SocialUser $social): bool
    {
        $raw = method_exists($social, 'getRaw') ? (array) $social->getRaw() : [];

        return match ($provider) {
            'google' => filter_var($raw['email_verified'] ?? $raw['verified_email'] ?? false, FILTER_VALIDATE_BOOL),
            'apple' => filter_var($raw['email_verified'] ?? false, FILTER_VALIDATE_BOOL),
            'github' => true, // Socialite only returns GitHub's primary, verified email
            default => false, // Microsoft: never trusted for linking
        };
    }

    private function nameFor(SocialUser $social, string $email): string
    {
        $name = trim((string) ($social->getName() ?: $social->getNickname()));

        return $name !== '' ? mb_substr($name, 0, 100) : Str::title(str_replace(['.', '_', '-'], ' ', Str::before($email, '@')));
    }

    private function back(string $code, string $provider, string $intent = 'login'): RedirectResponse
    {
        return redirect($this->front($intent === 'signup' ? '/signup/' : '/login/', ['oauth_error' => $code, 'provider' => $provider]));
    }

    /** @param array<string, string|null> $query */
    private function front(string $path, array $query): string
    {
        $query = array_filter($query, fn ($v) => $v !== null && $v !== '');

        return rtrim((string) config('app.frontend_url'), '/').$path.($query ? '?'.http_build_query($query) : '');
    }
}
