<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit;
use App\Services\TwoFactor;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class PasswordResetController extends Controller
{
    public function forgot(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email:rfc', 'max:254']]);
        $email = User::normalizeEmail($data['email']);

        // Always the same response, whether or not the account exists or the link was throttled.
        Password::broker()->sendResetLink(['email' => $email]);

        return response()->json(['sent' => true], 202);
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'token' => ['required', 'string', 'max:200'],
            'password' => ['required', 'string', PasswordRule::min(12)->max(128)->uncompromised()],
        ], ['password.min' => 'Use at least 12 characters.', 'password.uncompromised' => 'This password appears in known data breaches. Choose a different one.']);
        $email = User::normalizeEmail($data['email']);

        $status = Password::broker()->reset(
            ['email' => $email, 'token' => $data['token'], 'password' => $data['password']],
            function (User $user, string $password) use ($request) {
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)]);
                if (! $user->hasVerifiedEmail()) {
                    $user->email_verified_at = now(); // the reset link proved inbox ownership
                }
                $user->save();
                DB::table('sessions')->where('user_id', $user->id)->delete(); // sign out everywhere
                app(TwoFactor::class)->forgetDevices($user);
                RateLimiter::clear(LoginController::lockKey($user->email, (string) $request->ip()));
                event(new PasswordReset($user));
                Audit::log('auth.password_reset', $user);
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw new ApiException('reset_invalid', 'This reset link is invalid or has expired. Request a new one.', 422);
        }

        return response()->json(['reset' => true]);
    }
}
