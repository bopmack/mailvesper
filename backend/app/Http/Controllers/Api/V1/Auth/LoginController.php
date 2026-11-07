<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit;
use App\Services\AuthSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class LoginController extends Controller
{
    public const MAX_FAILURES = 5;

    public const LOCK_SECONDS = 900;

    /** Failures per account per hour from all addresses together, so spreading a guessing run over many IPs doesn't help. */
    public const ACCOUNT_MAX_FAILURES = 25;

    public function __invoke(Request $request, AuthSession $session): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'password' => ['required', 'string', 'max:128'],
            'remember' => ['sometimes', 'boolean'],
        ]);
        $email = User::normalizeEmail($data['email']);
        $key = self::lockKey($email, (string) $request->ip());

        $account = self::accountKey($email);
        if (RateLimiter::tooManyAttempts($account, self::ACCOUNT_MAX_FAILURES)) {
            $key = $account; // report the longer wait
        }
        if (RateLimiter::tooManyAttempts($key, $key === $account ? self::ACCOUNT_MAX_FAILURES : self::MAX_FAILURES)) {
            $wait = RateLimiter::availableIn($key);
            throw new ApiException('login_locked', 'Too many failed attempts. Try again in '.ceil($wait / 60).' minutes, or sign in with an email code.', 429, retryAfter: $wait);
        }

        $user = User::where('email', $email)->first();
        // Run a real hash check even when the user doesn't exist, so response time doesn't reveal it.
        $valid = Hash::check($data['password'], $user->password ?? self::dummyHash());
        if (! $user || $user->password === null) {
            $valid = false;
        }

        if (! $user || ! $valid) {
            RateLimiter::hit($key, self::LOCK_SECONDS);
            RateLimiter::hit($account, 3600);
            Audit::log('auth.login_failed', $user, meta: ['email' => $email]);
            $left = self::MAX_FAILURES - RateLimiter::attempts($key);
            $message = $left <= 2 && $left > 0 ? "Incorrect email or password. {$left} ".($left === 1 ? 'try' : 'tries').' left before sign-in pauses for 15 minutes.' : 'Incorrect email or password.';
            throw new ApiException('invalid_credentials', $message, 422, ['password' => $message]);
        }

        RateLimiter::clear($key);
        RateLimiter::clear($account);
        if (Hash::needsRehash($user->password)) {
            $user->forceFill(['password' => $data['password']])->save();
        }

        return response()->json($session->start($request, $user, (bool) ($data['remember'] ?? false), 'password'));
    }

    private static ?string $dummy = null;

    private static function dummyHash(): string
    {
        return self::$dummy ??= Hash::make('sendline-timing-equaliser');
    }

    public static function lockKey(string $email, string $ip): string
    {
        return 'login:'.sha1($email.'|'.$ip);
    }

    public static function accountKey(string $email): string
    {
        return 'login-acct:'.sha1($email);
    }
}
