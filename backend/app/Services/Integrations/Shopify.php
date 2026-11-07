<?php

namespace App\Services\Integrations;

/**
 * Shopify webhooks → contact events named shopify.<topic> with "/" as "." (shopify.orders.create,
 * shopify.checkouts.create…). products/update feeds price-drop and back-in-stock alerts instead.
 */
class Shopify
{
    /** Checks X-Shopify-Hmac-Sha256 (base64 HMAC of the raw body with the app's or store's webhook secret). */
    public static function verify(string $header, string $payload, string $secret): bool
    {
        return $header !== '' && hash_equals(base64_encode(hash_hmac('sha256', $payload, $secret, true)), $header);
    }

    /**
     * @param  array<string, mixed>  $p
     * @return array{name: string, email: string|null, first_name: string|null, last_name: string|null, fields: array<string, mixed>, properties: array<string, mixed>}|null
     */
    public static function parse(string $topic, array $p): ?array
    {
        if (! preg_match('#^[a-z_]{1,40}/[a-z_]{1,40}$#', $topic)) {
            return null;
        }
        $c = is_array($p['customer'] ?? null) ? $p['customer'] : (str_starts_with($topic, 'customers/') ? $p : []);
        $str = fn ($v) => is_string($v) && trim($v) !== '' ? trim($v) : null;
        $email = $str($p['email'] ?? null) ?? $str($p['contact_email'] ?? null) ?? $str($c['email'] ?? null);
        $consent = $c['email_marketing_consent']['state'] ?? null;
        $fields = array_filter([
            'shopify_customer_id' => isset($c['id']) ? (string) $c['id'] : null,
            'accepts_marketing' => is_string($consent) ? $consent === 'subscribed' : (isset($c['accepts_marketing']) ? (bool) $c['accepts_marketing'] : null),
        ], fn ($v) => $v !== null);
        $items = array_values(array_filter((array) ($p['line_items'] ?? []), 'is_array'));
        $props = array_filter([
            'shopify_topic' => $topic, 'id' => isset($p['id']) ? (string) $p['id'] : null, 'order_name' => $str($p['name'] ?? null),
            'total_price' => $str($p['total_price'] ?? null), 'subtotal_price' => $str($p['subtotal_price'] ?? null), 'currency' => $str($p['currency'] ?? null),
            'financial_status' => $str($p['financial_status'] ?? null), 'fulfillment_status' => $str($p['fulfillment_status'] ?? null),
            'order_status_url' => $str($p['order_status_url'] ?? null), 'checkout_url' => $str($p['abandoned_checkout_url'] ?? null),
            'item_count' => $items ? array_sum(array_map(fn ($i) => (int) ($i['quantity'] ?? 1), $items)) : null,
            'product_ids' => $items ? implode(',', array_slice(array_unique(array_map(fn ($i) => (string) ($i['product_id'] ?? ''), $items)), 0, 20)) : null,
            'first_item' => $items ? $str($items[0]['title'] ?? null) : null,
            'orders_count' => isset($c['orders_count']) ? (int) $c['orders_count'] : null, 'total_spent' => $str($c['total_spent'] ?? null),
        ], fn ($v) => $v !== null && $v !== '');

        return ['name' => 'shopify.'.str_replace('/', '.', $topic), 'email' => $email, 'first_name' => $str($c['first_name'] ?? null) ?? $str($p['billing_address']['first_name'] ?? null),
            'last_name' => $str($c['last_name'] ?? null) ?? $str($p['billing_address']['last_name'] ?? null), 'fields' => $fields, 'properties' => $props];
    }

    /**
     * A products/create or products/update payload as product state: the lowest variant price, and in stock when
     * any variant has stock (or Shopify doesn't track its inventory).
     *
     * @param  array<string, mixed>  $p
     * @return array{product_id: string, price: float|null, in_stock: bool|null, name: string|null, url: string|null, image: string|null}|null
     */
    public static function product(array $p, ?string $shop): ?array
    {
        if (! isset($p['id'])) {
            return null;
        }
        $variants = array_values(array_filter((array) ($p['variants'] ?? []), 'is_array'));
        $prices = array_values(array_filter(array_map(fn ($v) => is_numeric($v['price'] ?? null) ? (float) $v['price'] : null, $variants), fn ($v) => $v !== null));
        $stock = $variants ? (bool) array_filter($variants, fn ($v) => ($v['inventory_management'] ?? null) === null || (int) ($v['inventory_quantity'] ?? 0) > 0 || ($v['inventory_policy'] ?? '') === 'continue') : null;

        return ['product_id' => (string) $p['id'], 'price' => $prices ? min($prices) : null, 'in_stock' => $stock,
            'name' => is_string($p['title'] ?? null) ? $p['title'] : null,
            'url' => $shop && is_string($p['handle'] ?? null) ? 'https://'.$shop.'/products/'.$p['handle'] : null,
            'image' => is_string($p['image']['src'] ?? null) ? $p['image']['src'] : null];
    }
}
