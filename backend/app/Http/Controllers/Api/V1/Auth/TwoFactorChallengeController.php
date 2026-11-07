<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit;
use App\Services\AuthSession;
use App\Services\Platform\Brand;
use App\Services\TwoFactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\RateLimiter;

/** Second step of sign-in for accounts with two-step verification. */
class TwoFactorChallengeController extends Controller
{
    public const MAX_ATTEMPTS = 5;

    /** Wrong codes per account per hour across sign-ins: logging in again resets the per-sign-in count, not this. */
    public const ACCOUNT_MAX_ATTEMPTS = 15;

    public function __invoke(Request $request, TwoFactor $twoFactor, AuthSession $session): JsonResponse
    {
        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:20'],
            'recovery_code' => ['nullable', 'string', 'max:30'],
            'trust_device' => ['sometimes', 'boolean'],
        ]);
        if (empty($data['code']) && empty($data['recovery_code'])) {
            throw new ApiException('validation_failed', 'Enter the 6-digit code from your app.', 422, ['code' => 'Enter the 6-digit code from your app.']);
        }

        if (! $request->hasSession()) {
            throw new ApiException('stateless_request', 'Sign-in must come from the '.Brand::name().' website.', 400);
        }
        /** @var array{user_id: string, remember: bool, method: string, expires: int, attempts?: int}|null $pending */
        $pending = $request->session()->get('auth.mfa');
        if (! $pending || $pending['expires'] < now()->timestamp) {
            $request->session()->forget('auth.mfa');
            throw new ApiException('mfa_expired', 'Your sign-in timed out. Log in again.', 401);
        }
        $user = User::find($pending['user_id']);
        if (! $user || ! $user->hasTwoFactor() || ! $user->two_factor_secret) {
            $request->session()->forget('auth.mfa');
            throw new ApiException('mfa_expired', 'Your sign-in timed out. Log in again.', 401);
        }

        $account = 'mfa-acct:'.$user->id;
        if (RateLimiter::tooManyAttempts($account, self::ACCOUNT_MAX_ATTEMPTS)) {
            $request->session()->forget('auth.mfa');
            $wait = RateLimiter::availableIn($account);
            throw new ApiException('mfa_locked', 'Too many wrong codes. Try again in '.ceil($wait / 60).' minutes.', 429, retryAfter: $wait);
        }

        $usedRecovery = false;
        $ok = ! empty($data['code'])
            ? $twoFactor->verify($user, $user->two_factor_secret, $data['code'])
            : ($usedRecovery = $twoFactor->useRecoveryCode($user, (string) $data['recovery_code']));

        if (! $ok) {
            $attempts = ($pending['attempts'] ?? 0) + 1;
            RateLimiter::hit($account, 3600);
            Audit::log('auth.two_factor_failed', $user);
            if ($attempts >= self::MAX_ATTEMPTS) {
                $request->session()->forget('auth.mfa');
                throw new ApiException('mfa_locked', 'Too many wrong codes. Log in again to retry.', 429);
            }
            $request->session()->put('auth.mfa', ['attempts' => $attempts] + $pending);
            $field = empty($data['code']) ? 'recovery_code' : 'code';
            $message = empty($data['code']) ? 'That recovery code isn’t valid or was already used.' : 'That code didn’t match. Try the newest code in your app.';
            throw new ApiException('code_invalid', $message, 422, [$field => $message]);
        }

        if ($usedRecovery) {
            Audit::log('security.recovery_code_used', $user, meta: ['left' => count($user->two_factor_recovery_codes ?? [])]);
        }
        if (! empty($data['trust_device'])) {
            Cookie::queue(Cookie::make(TwoFactor::TRUST_COOKIE, $twoFactor->trustDevice($user, $request), TwoFactor::TRUST_DAYS * 24 * 60, '/', null, null, true, false, 'lax'));
        }

        return response()->json($session->complete($request, $user, (bool) $pending['remember'], $pending['method']));
    }
}
