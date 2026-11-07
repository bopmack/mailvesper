<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A store or payment provider sending its events to MailVesper by webhook.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $provider stripe | shopify
 * @property string $token
 * @property string|null $secret
 * @property string|null $shop_domain
 * @property bool $enabled
 * @property int $events_received
 * @property Carbon|null $last_event_at
 * @property string|null $last_error
 * @property Carbon|null $last_error_at
 */
class Integration extends Model
{
    use HasUlids;

    public const PROVIDERS = ['stripe', 'shopify'];

    protected $guarded = ['id'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return ['secret' => 'encrypted', 'enabled' => 'boolean', 'events_received' => 'integer', 'last_event_at' => 'datetime', 'last_error_at' => 'datetime'];
    }
}
