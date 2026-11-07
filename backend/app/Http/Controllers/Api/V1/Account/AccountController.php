<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit;
use App\Services\AuthSession;
use App\Services\TwoFactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/** Settings → Profile: the signed-in person's name, time zone and password. */
class AccountController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:100'],
            'timezone' => ['sometimes', 'string', 'timezone:all'],
        ]);
        $changed = [];
        if (isset($data['name']) && trim($data['name']) !== $user->name) {
            $user->name = trim($data['name']);
            $changed[] = 'name';
        }
        if (isset($data['timezone']) && $data['timezone'] !== $user->timezone) {
            $user->timezone = $data['timezone'];
            $changed[] = 'timezone';
        }
        if ($changed) {
            $user->save();
            Audit::log('account.updated', $user, null, ['fields' => $changed]);
        }

        return response()->json(AuthSession::payload($user));
    }

    /** Needs the current password; signs out every other session and forgets trusted devices, like a reset does. */
    public function password(Request $request, TwoFactor $twoFactor): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', Password::min(12)->max(128)->uncompromised(), 'different:current_password'],
        ], [
            'password.min' => 'Use at least 12 characters.',
            'password.max' => 'Use 128 characters or fewer.',
            'password.uncompromised' => 'This password appears in known data breaches. Choose a different one.',
            'password.different' => 'Choose a password that’s different from your current one.',
        ]);
        if ($user->password === null) {
            throw new ApiException('no_password', 'Your account signs in without a password. Use “Forgot password” on the login page to set one.', 422);
        }
        if (! Hash::check($data['current_password'], $user->password)) {
            throw new ApiException('wrong_password', 'That isn’t your current password.', 422, ['current_password' => 'That isn’t your current password.']);
        }

        $user->forceFill(['password' => $data['password'], 'remember_token' => Str::random(60)])->save();
        $current = $request->hasSession() ? $request->session()->getId() : null;
        $signedOut = DB::table('sessions')->where('user_id', $user->id)->when($current, fn ($q) => $q->where('id', '!=', $current))->delete();
        $twoFactor->forgetDevices($user);
        if ($request->hasSession()) {
            $request->session()->regenerate(); // a new id for this session too
        }
        Audit::log('auth.password_changed', $user, null, ['other_sessions_ended' => $signedOut]);

        return response()->json(['changed' => true, 'other_sessions_ended' => $signedOut]);
    }
}
