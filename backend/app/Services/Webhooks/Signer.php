<?php

namespace App\Services\Webhooks;

/**
 * Standard Webhooks signatures (standardwebhooks.com), the scheme used by Svix, Resend and others:
 * webhook-signature = "v1," + base64(HMAC-SHA256(secret, "{id}.{timestamp}.{body}")).
 */
class Signer
{
    /** @return array<string, string> headers */
    public static function headers(string $secret, string $id, int $timestamp, string $body): array
    {
        return ['webhook-id' => $id, 'webhook-timestamp' => (string) $timestamp, 'webhook-signature' => 'v1,'.self::sign($secret, $id, $timestamp, $body)];
    }

    public static function sign(string $secret, string $id, int $timestamp, string $body): string
    {
        $key = base64_decode(str_starts_with($secret, 'whsec_') ? substr($secret, 6) : $secret, true) ?: $secret;

        return base64_encode(hash_hmac('sha256', "$id.$timestamp.$body", $key, true));
    }
}
