<?php

namespace App\Services;

use App\Enums\CodePurpose;
use App\Exceptions\ApiException;
use App\Models\EmailCode;

/**
 * Issues and verifies 6-digit one-time codes.
 * Only an HMAC of the code is stored. A code expires after 10 minutes or 5 wrong attempts,
 * and issuing a new code invalidates older ones. Resend has a 30-second cooldown per email.
 */
class EmailCodeService
{
    public const TTL_MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    public const COOLDOWN_SECONDS = 30;

    public function issue(string $email, CodePurpose $purpose, ?string $ip = null): string
    {
        $latest = $this->latest($email, $purpose);
        if ($latest && $latest->created_at && $latest->consumed_at === null && $latest->created_at->gt(now()->subSeconds(self::COOLDOWN_SECONDS))) {
            $wait = self::COOLDOWN_SECONDS - (int) $latest->created_at->diffInSeconds(now());
            throw new ApiException('code_cooldown', "Please wait {$wait} seconds before requesting a new code.", 429, retryAfter: max(1, $wait));
        }

        EmailCode::where('email', $email)->where('purpose', $purpose->value)->whereNull('consumed_at')->update(['consumed_at' => now()]);

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        EmailCode::create([
            'email' => $email,
            'purpose' => $purpose->value,
            'code_hash' => $this->hash($code),
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
            'ip_address' => $ip,
        ]);

        return $code;
    }

    /** @throws ApiException when the code is wrong, expired or used up */
    public function verify(string $email, CodePurpose $purpose, string $code): void
    {
        $record = EmailCode::where('email', $email)->where('purpose', $purpose->value)->whereNull('consumed_at')->latest('id')->first();

        if (! $record || $record->expires_at->isPast()) {
            throw new ApiException('code_expired', 'This code has expired. Request a new one.', 422, ['code' => 'This code has expired. Request a new one.']);
        }
        if ($record->attempts >= self::MAX_ATTEMPTS) {
            throw new ApiException('code_locked', 'Too many wrong attempts. Request a new code.', 422, ['code' => 'Too many wrong attempts. Request a new code.']);
        }
        if (! hash_equals($record->code_hash, $this->hash(preg_replace('/\D/', '', $code) ?? ''))) {
            $record->increment('attempts');
            $left = self::MAX_ATTEMPTS - $record->attempts;
            $message = $left > 0 ? 'That code is incorrect.' : 'Too many wrong attempts. Request a new code.';
            throw new ApiException($left > 0 ? 'code_invalid' : 'code_locked', $message, 422, ['code' => $message]);
        }

        $record->forceFill(['consumed_at' => now()])->save();
    }

    private function latest(string $email, CodePurpose $purpose): ?EmailCode
    {
        return EmailCode::where('email', $email)->where('purpose', $purpose->value)->latest('id')->first();
    }

    private function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}
