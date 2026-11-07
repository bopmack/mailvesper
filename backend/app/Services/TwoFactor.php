<?php

namespace App\Services;

use App\Models\TrustedDevice;
use App\Models\User;
use App\Services\Platform\Brand;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/** TOTP (authenticator app) two-step verification, recovery codes and trusted devices. */
class TwoFactor
{
    public const TRUST_COOKIE = 'sl_trusted';

    public const TRUST_DAYS = 30;

    public const RECOVERY_CODES = 10;

    public function __construct(private Google2FA $engine) {}

    public function newSecret(): string
    {
        return $this->engine->generateSecretKey(32);
    }

    public function otpauthUrl(User $user, string $secret): string
    {
        return $this->engine->getQRCodeUrl(Brand::name(), $user->email, $secret);
    }

    public function qrSvg(string $url): string
    {
        return (new Writer(new ImageRenderer(new RendererStyle(200, 1), new SvgImageBackEnd)))->writeString($url);
    }

    /** Accepts the current code (±30 s) and never the same time-step twice, so a seen code can't be replayed. */
    public function verify(User $user, string $secret, string $code): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        if (strlen($code) !== 6) {
            return false;
        }
        $key = '2fa:last-step:'.$user->id;
        $last = Cache::get($key);
        // Passing an int (0 when nothing is cached) makes the library return the matched time-step;
        // with null it would return plain `true` and we couldn't block replays.
        $step = $this->engine->verifyKeyNewer($secret, $code, is_int($last) ? $last : 0, 1);
        if (! is_int($step)) {
            return false;
        }
        Cache::put($key, $step, now()->addMinutes(5));

        return true;
    }

    /** @return list<string> */
    public function newRecoveryCodes(): array
    {
        return array_map(fn () => Str::lower(Str::random(5).'-'.Str::random(5)), range(1, self::RECOVERY_CODES));
    }

    /** Consumes a recovery code if it matches one of the user's remaining codes. */
    public function useRecoveryCode(User $user, string $code): bool
    {
        $code = Str::lower(trim($code));
        /** @var list<string> $codes */
        $codes = $user->two_factor_recovery_codes ?? [];
        foreach ($codes as $i => $stored) {
            if (hash_equals($stored, $code)) {
                unset($codes[$i]);
                $user->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();

                return true;
            }
        }

        return false;
    }

    public function trustDevice(User $user, Request $request): string
    {
        $token = Str::random(48);
        TrustedDevice::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
            'ip_address' => $request->ip(),
            'expires_at' => now()->addDays(self::TRUST_DAYS),
        ]);

        return $token;
    }

    public function isTrusted(User $user, Request $request): bool
    {
        $token = $request->cookie(self::TRUST_COOKIE);
        if (! is_string($token) || $token === '') {
            return false;
        }
        $device = TrustedDevice::where('user_id', $user->id)->where('token_hash', hash('sha256', $token))->where('expires_at', '>', now())->first();
        $device?->forceFill(['last_used_at' => now()])->save();

        return $device !== null;
    }

    public function forgetDevices(User $user): void
    {
        TrustedDevice::where('user_id', $user->id)->delete();
    }
}
