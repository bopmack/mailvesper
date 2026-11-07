<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Passkey;
use App\Models\User;
use App\Services\Platform\Brand;
use Illuminate\Http\Request;
use lbuchs\WebAuthn\Binary\ByteBuffer;
use lbuchs\WebAuthn\WebAuthn;
use Throwable;

/**
 * WebAuthn passkeys. The relying party is the MailVesper web origin (FRONTEND_URL).
 * Passkeys require user verification (fingerprint, face or device PIN), so they count as both factors.
 */
class Passkeys
{
    public const CHALLENGE_TTL = 300;

    private function engine(): WebAuthn
    {
        return new WebAuthn(Brand::name(), $this->rpId(), null, true); // true = base64url in JSON
    }

    public function rpId(): string
    {
        return (string) parse_url((string) config('app.frontend_url'), PHP_URL_HOST);
    }

    private function origin(): string
    {
        $u = parse_url((string) config('app.frontend_url'));

        return ($u['scheme'] ?? 'https').'://'.($u['host'] ?? '').(isset($u['port']) ? ':'.$u['port'] : '');
    }

    /** @return array<string, mixed> */
    public function registrationOptions(Request $request, User $user): array
    {
        $engine = $this->engine();
        $exclude = Passkey::where('user_id', $user->id)->pluck('credential_id')->map(fn (string $id) => self::b64uDecode($id))->all();
        $args = $engine->getCreateArgs($user->id, $user->email, $user->name, 60, true, true, null, $exclude);
        $this->remember($request, 'register', $engine->getChallenge());

        return json_decode((string) json_encode($args), true);
    }

    /** @param array<string, mixed> $data */
    public function register(Request $request, User $user, array $data): Passkey
    {
        $challenge = $this->recall($request, 'register');
        $clientData = self::b64uDecode((string) $data['client_data_json']);
        $this->assertOrigin($clientData);
        try {
            $result = $this->engine()->processCreate($clientData, self::b64uDecode((string) $data['attestation_object']), $challenge, true, true, false, false);
        } catch (Throwable $e) {
            throw new ApiException('passkey_invalid', 'That passkey couldn’t be saved. Please try again.', 422);
        }

        $credentialId = self::b64uEncode((string) $result->credentialId);
        if (Passkey::where('credential_id', $credentialId)->exists()) {
            throw new ApiException('passkey_exists', 'This passkey is already registered.', 422);
        }
        $transports = array_values(array_filter((array) ($data['transports'] ?? []), fn ($t) => is_string($t) && preg_match('/^[a-z-]{2,20}$/', $t)));

        return Passkey::create([
            'user_id' => $user->id,
            'name' => mb_substr(trim((string) ($data['name'] ?? '')) ?: $this->guessName($request), 0, 80),
            'credential_id' => $credentialId,
            'public_key' => (string) $result->credentialPublicKey,
            'sign_count' => (int) ($result->signatureCounter ?? 0),
            'transports' => $transports ?: null,
            'aaguid' => isset($result->AAGUID) && is_string($result->AAGUID) ? bin2hex($result->AAGUID) : null,
        ]);
    }

    /** @return array<string, mixed> */
    public function loginOptions(Request $request): array
    {
        $engine = $this->engine();
        $args = $engine->getGetArgs([], 60, true, true, true, true, true, true); // discoverable: the device offers its passkeys
        $this->remember($request, 'login', $engine->getChallenge());

        return json_decode((string) json_encode($args), true);
    }

    /** @param array<string, mixed> $data */
    public function authenticate(Request $request, array $data): User
    {
        $challenge = $this->recall($request, 'login');
        $passkey = Passkey::with('user')->where('credential_id', (string) $data['id'])->first();
        if (! $passkey) {
            throw new ApiException('passkey_unknown', 'This passkey isn’t registered with '.Brand::name().'. Sign in another way, then add it in Settings.', 422);
        }
        $clientData = self::b64uDecode((string) $data['client_data_json']);
        $this->assertOrigin($clientData);
        $engine = $this->engine();
        try {
            $engine->processGet($clientData, self::b64uDecode((string) $data['authenticator_data']), self::b64uDecode((string) $data['signature']), $passkey->public_key, $challenge, $passkey->sign_count, true, true);
        } catch (Throwable $e) {
            throw new ApiException('passkey_invalid', 'That passkey didn’t verify. Please try again.', 422);
        }
        $counter = $engine->getSignatureCounter();
        $passkey->forceFill(['sign_count' => is_int($counter) ? $counter : $passkey->sign_count, 'last_used_at' => now()])->save();

        return $passkey->user;
    }

    /** Exact origin match, stricter than the library's suffix check. */
    private function assertOrigin(string $clientDataJson): void
    {
        $origin = json_decode($clientDataJson, true)['origin'] ?? null;
        if ($origin !== $this->origin()) {
            throw new ApiException('passkey_invalid', 'That passkey request came from the wrong site.', 422);
        }
    }

    private function remember(Request $request, string $purpose, ByteBuffer $challenge): void
    {
        $request->session()->put("passkey.$purpose", ['challenge' => base64_encode($challenge->getBinaryString()), 'expires' => now()->addSeconds(self::CHALLENGE_TTL)->timestamp]);
    }

    private function recall(Request $request, string $purpose): string
    {
        /** @var array{challenge: string, expires: int}|null $stored */
        $stored = $request->session()->pull("passkey.$purpose"); // single use
        if (! $stored || $stored['expires'] < now()->timestamp) {
            throw new ApiException('passkey_expired', 'That took too long. Please try again.', 422);
        }

        return (string) base64_decode($stored['challenge']);
    }

    private function guessName(Request $request): string
    {
        $ua = (string) $request->userAgent();

        return match (true) {
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'Android') => 'Android phone',
            str_contains($ua, 'Mac OS') => 'Mac',
            str_contains($ua, 'Windows') => 'Windows PC',
            default => 'Passkey',
        };
    }

    public static function b64uDecode(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/').str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    }

    public static function b64uEncode(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }
}
