<?php

namespace App\Services\Commerce;

use App\Jobs\SendProductAlert;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Price drops and restocks. Each product's last known price and stock are kept; when an update lowers the price
 * or brings it back in stock, the contacts who showed interest get an event (sendline.price_drop /
 * sendline.back_in_stock) that journeys can start from. The first update of a product only records it.
 *
 * Interest comes from the contact's own events, sent with the product's id in "product_id":
 * product_viewed (last 30 days) for both, and back_in_stock_requested (last 90 days) for restocks.
 */
class ProductAlerts
{
    public const VIEW_DAYS = 30;

    public const REQUEST_DAYS = 90;

    /**
     * @param  array<string, mixed>  $meta  name, url, image
     * @return list<string> the alerts started (price_drop, back_in_stock)
     */
    public function update(string $workspaceId, string $productId, ?float $price, ?bool $inStock, array $meta = []): array
    {
        $alerts = DB::transaction(function () use ($workspaceId, $productId, $price, $inStock, $meta) {
            $old = DB::table('product_states')->where('workspace_id', $workspaceId)->where('product_id', $productId)->lockForUpdate()->first();
            $row = ['price' => $price ?? $old?->price, 'in_stock' => $inStock ?? ($old ? (bool) $old->in_stock : null), 'updated_at' => now()];
            foreach (['name' => 255, 'url' => 500, 'image' => 500] as $k => $max) {
                $row[$k] = isset($meta[$k]) && is_string($meta[$k]) && $meta[$k] !== '' ? mb_substr($meta[$k], 0, $max) : $old?->{$k};
            }
            DB::table('product_states')->updateOrInsert(['workspace_id' => $workspaceId, 'product_id' => $productId], $row);
            if (! $old) {
                return [];
            }
            $props = ['product_id' => $productId, 'name' => $row['name'], 'url' => $row['url'], 'image' => $row['image'], 'price' => $row['price'] !== null ? (float) $row['price'] : null];
            $out = [];
            if ($price !== null && $old->price !== null && $price < (float) $old->price) {
                $out['price_drop'] = $props + ['old_price' => (float) $old->price, 'drop_percent' => (int) floor(100 * ((float) $old->price - $price) / (float) $old->price)];
            }
            if ($inStock === true && $old->in_stock !== null && ! (bool) $old->in_stock) {
                $out['back_in_stock'] = $props;
            }

            return $out;
        });
        foreach ($alerts as $kind => $props) {
            SendProductAlert::dispatch($workspaceId, $kind, array_filter($props, fn ($v) => $v !== null), (string) Str::ulid());
        }

        return array_keys($alerts);
    }
}
