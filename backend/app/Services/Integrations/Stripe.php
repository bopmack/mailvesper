<?php

namespace App\Services\Integrations;

/**
 * Stripe webhook events → contact events named stripe.<type> (stripe.invoice.paid, stripe.checkout.session.expired…).
 * Events that carry no email (subscriptions, for instance) are matched by the Stripe customer id saved on the contact
 * the first time an event with both arrives.
 */
class Stripe
{
    /** Seconds a signature stays valid (Stripe's own default tolerance). */
    public const TOLERANCE = 300;

    /** Currencies Stripe counts in whole units (no cents). */
    private const ZERO_DECIMAL = ['bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga', 'pyg', 'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf'];

    /** Checks the Stripe-Signature header (t=…, v1=…) against the endpoint's signing secret. */
    public static function verify(string $header, string $payload, string $secret, ?int $now = null): bool
    {
        $t = null;
        $sigs = [];
        foreach (explode(',', $header) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($k === 't' && ctype_digit($v)) {
                $t = (int) $v;
            } elseif ($k === 'v1') {
                $sigs[] = $v;
            }
        }
        if ($t === null || ! $sigs || abs(($now ?? time()) - $t) > self::TOLERANCE) {
            return false;
        }
        $expected = hash_hmac('sha256', $t.'.'.$payload, $secret);
        foreach ($sigs as $s) {
            if (hash_equals($expected, $s)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array{id: string, name: string, email: string|null, customer: string|null, first_name: string|null, last_name: string|null, properties: array<string, mixed>}|null
     */
    public static function parse(array $event): ?array
    {
        $type = is_string($event['type'] ?? null) ? $event['type'] : '';
        $o = $event['data']['object'] ?? null;
        if (! preg_match('/^[a-z0-9_.]{1,90}$/', $type) || ! is_array($o) || ! is_string($event['id'] ?? null)) {
            return null;
        }
        $email = self::first([$o['email'] ?? null, $o['customer_email'] ?? null, $o['receipt_email'] ?? null, $o['customer_details']['email'] ?? null, $o['billing_details']['email'] ?? null]);
        $name = self::first([$o['name'] ?? null, $o['customer_name'] ?? null, $o['customer_details']['name'] ?? null, $o['billing_details']['name'] ?? null]);
        $customer = is_string($o['customer'] ?? null) ? $o['customer'] : (($o['object'] ?? null) === 'customer' && is_string($o['id'] ?? null) ? $o['id'] : null);
        [$first, $last] = $name ? array_pad(explode(' ', trim($name), 2), 2, null) : [null, null];

        $currency = is_string($o['currency'] ?? null) ? strtolower($o['currency']) : null;
        $money = fn ($v) => is_int($v) ? ($currency && in_array($currency, self::ZERO_DECIMAL, true) ? $v : round($v / 100, 2)) : null;
        $props = ['stripe_event' => $type, 'object_id' => $o['id'] ?? null, 'status' => $o['status'] ?? null, 'currency' => $currency ? strtoupper($currency) : null];
        foreach (['amount', 'amount_total', 'amount_paid', 'amount_due', 'amount_refunded'] as $k) {
            $props[$k] = $money($o[$k] ?? null);
        }
        $item = $o['items']['data'][0] ?? $o['lines']['data'][0] ?? null;
        $price = is_array($item) ? ($item['price'] ?? $item['plan'] ?? null) : null;
        $props += [
            'plan' => is_array($price) ? ($price['nickname'] ?? $price['lookup_key'] ?? $price['id'] ?? null) : null,
            'subscription_id' => is_string($o['subscription'] ?? null) ? $o['subscription'] : (($o['object'] ?? null) === 'subscription' ? ($o['id'] ?? null) : null),
            'cancel_at_period_end' => $o['cancel_at_period_end'] ?? null,
            'current_period_end' => is_int($o['current_period_end'] ?? null) ? gmdate('Y-m-d\TH:i:s\Z', $o['current_period_end']) : null,
            'trial_end' => is_int($o['trial_end'] ?? null) ? gmdate('Y-m-d\TH:i:s\Z', $o['trial_end']) : null,
            'invoice_url' => $o['hosted_invoice_url'] ?? null, 'invoice_pdf' => $o['invoice_pdf'] ?? null, 'receipt_url' => $o['receipt_url'] ?? null,
            'recovery_url' => $o['after_expiration']['recovery']['url'] ?? null, // an expired Checkout: the link that brings them back
            'failure_message' => $o['last_payment_error']['message'] ?? $o['failure_message'] ?? null,
        ];

        return ['id' => $event['id'], 'name' => 'stripe.'.$type, 'email' => $email, 'customer' => $customer, 'first_name' => $first, 'last_name' => $last,
            'properties' => array_filter($props, fn ($v) => $v !== null && (is_scalar($v)))];
    }

    /** @param list<mixed> $values */
    private static function first(array $values): ?string
    {
        foreach ($values as $v) {
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
        }

        return null;
    }
}
