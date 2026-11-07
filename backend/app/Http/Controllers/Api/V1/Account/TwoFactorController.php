<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit;
use App\Services\TwoFactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/** Settings → Security → Two-step verification. */
class TwoFactorController extends Controller
{
    public function __construct(private TwoFactor $twoFactor) {}

    /** Step 1: confirm it's really you, then get a secret + QR code. Nothing is enforced until confirmed. */
    public function setup(Request $request): JsonResponse
    {
        $user = $this->user($request);
        if ($user->hasTwoFactor()) {
            throw new ApiException('two_factor_enabled', 'Two-step verification is already on.', 409);
        }
        $this->confirmPassword($request, $user);

        $secret = $this->twoFactor->newSecret();
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => null, 'two_factor_recovery_codes' => null])->save();
        $url = $this->twoFactor->otpauthUrl($user, $secret);

        return response()->json(['secret' => $secret, 'otpauth_url' => $url, 'qr_svg' => $this->twoFactor->qrSvg($url)]);
    }

    /** Step 2: prove the app works by entering a code. Returns recovery codes, shown once. */
    public function confirm(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validate(['code' => ['required', 'string']]);
        if ($user->hasTwoFactor()) {
            throw new ApiException('two_factor_enabled', 'Two-step verification is already on.', 409);
        }
        if (! $user->two_factor_secret) {
            throw new ApiException('two_factor_not_started', 'Start setup again to get a new QR code.', 422);
        }
        if (! $this->twoFactor->verify($user, $user->two_factor_secret, $data['code'])) {
            throw new ApiException('code_invalid', 'That code didn’t match. Check the time on your phone and try the newest code.', 422, ['code' => 'That code didn’t match.']);
        }

        $codes = $this->twoFactor->newRecoveryCodes();
        $user->forceFill(['two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => $codes])->save();
        Audit::log('security.two_factor_enabled', $user);

        return response()->json(['enabled' => true, 'recovery_codes' => $codes]);
    }

    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $this->requireCurrentCode($request, $user);
        $codes = $this->twoFactor->newRecoveryCodes();
        $user->forceFill(['two_factor_recovery_codes' => $codes])->save();
        Audit::log('security.recovery_codes_regenerated', $user);

        return response()->json(['recovery_codes' => $codes]);
    }

    public function disable(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $this->requireCurrentCode($request, $user);
        $user->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null, 'two_factor_recovery_codes' => null])->save();
        $this->twoFactor->forgetDevices($user);
        Audit::log('security.two_factor_disabled', $user);

        return response()->json(['enabled' => false]);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    private function confirmPassword(Request $request, User $user): void
    {
        if ($user->password === null) {
            return; // social-only accounts have no password to confirm; their session already passed sign-in
        }
        $password = (string) $request->input('password', '');
        if ($password === '' || ! Hash::check($password, $user->password)) {
            throw new ApiException('password_incorrect', 'That password isn’t right.', 422, ['password' => 'That password isn’t right.']);
        }
    }

    private function requireCurrentCode(Request $request, User $user): void
    {
        if (! $user->hasTwoFactor() || ! $user->two_factor_secret) {
            throw new ApiException('two_factor_disabled', 'Two-step verification is off.', 409);
        }
        $code = (string) $request->input('code', '');
        if (! $this->twoFactor->verify($user, $user->two_factor_secret, $code)) {
            throw new ApiException('code_invalid', 'Enter the current code from your authenticator app.', 422, ['code' => 'That code didn’t match.']);
        }
    }
}
