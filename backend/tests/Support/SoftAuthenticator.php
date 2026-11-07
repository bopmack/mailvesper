<?php

namespace Tests\Support;

use App\Services\Passkeys;

/**
 * A real software passkey (ES256, "none" attestation) so tests exercise the actual WebAuthn
 * verification instead of a mock. Origin and RP ID default to the test FRONTEND_URL.
 */
class SoftAuthenticator
{
    public string $credentialId;

    public int $counter = 0;

    private \OpenSSLAsymmetricKey $key;

    public function __construct(public string $rpId = 'send.test', public string $origin = 'https://send.test', public bool $userVerified = true)
    {
        $this->key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']) ?: throw new \RuntimeException('openssl');
        $this->credentialId = random_bytes(32);
    }

    /** @param array<string, mixed> $options the JSON from /options */
    public function create(array $options): array
    {
        $clientData = $this->clientData('webauthn.create', $options['publicKey']['challenge']);
        $ec = openssl_pkey_get_details($this->key)['ec'];
        $cose = self::cbor([1 => 2, 3 => -7, -1 => 1, -2 => new CborBytes(str_pad($ec['x'], 32, "\0", STR_PAD_LEFT)), -3 => new CborBytes(str_pad($ec['y'], 32, "\0", STR_PAD_LEFT))]);
        $authData = hash('sha256', $this->rpId, true).chr(0x41 | ($this->userVerified ? 0x04 : 0)).pack('N', $this->counter)
            .str_repeat("\0", 16).pack('n', strlen($this->credentialId)).$this->credentialId.$cose;
        $attestation = self::cbor(['fmt' => 'none', 'attStmt' => [], 'authData' => new CborBytes($authData)]);

        return ['client_data_json' => Passkeys::b64uEncode($clientData), 'attestation_object' => Passkeys::b64uEncode($attestation), 'transports' => ['internal', 'hybrid']];
    }

    /** @param array<string, mixed> $options the JSON from /auth/passkey/options */
    public function get(array $options): array
    {
        $this->counter++;
        $clientData = $this->clientData('webauthn.get', $options['publicKey']['challenge']);
        $authData = hash('sha256', $this->rpId, true).chr(0x01 | ($this->userVerified ? 0x04 : 0)).pack('N', $this->counter);
        openssl_sign($authData.hash('sha256', $clientData, true), $signature, $this->key, OPENSSL_ALGO_SHA256);

        return ['id' => Passkeys::b64uEncode($this->credentialId), 'client_data_json' => Passkeys::b64uEncode($clientData),
            'authenticator_data' => Passkeys::b64uEncode($authData), 'signature' => Passkeys::b64uEncode($signature)];
    }

    private function clientData(string $type, string $challenge): string
    {
        return (string) json_encode(['type' => $type, 'challenge' => $challenge, 'origin' => $this->origin, 'crossOrigin' => false], JSON_UNESCAPED_SLASHES);
    }

    /** Minimal CBOR: ints, text strings, byte strings (CborBytes) and maps. */
    public static function cbor(mixed $v): string
    {
        $head = function (int $major, int $n): string {
            if ($n < 24) {
                return chr($major << 5 | $n);
            }
            if ($n < 256) {
                return chr($major << 5 | 24).chr($n);
            }

            return chr($major << 5 | 25).pack('n', $n);
        };

        return match (true) {
            is_int($v) => $v >= 0 ? $head(0, $v) : $head(1, -1 - $v),
            $v instanceof CborBytes => $head(2, strlen($v->bytes)).$v->bytes,
            is_string($v) => $head(3, strlen($v)).$v,
            is_array($v) => $head(5, count($v)).implode('', array_map(fn ($k) => self::cbor($k).self::cbor($v[$k]), array_keys($v))),
            default => throw new \InvalidArgumentException('cbor'),
        };
    }
}
