<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A workspace's SMTP relay provider webhook (Settings → Sending → Delivery reports): deliveries, bounces and spam
 * complaints for the email it sent over SMTP.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $provider
 * @property string $token_hash
 * @property string $token
 * @property string|null $signing_key
 * @property int $events
 * @property Carbon|null $last_event_at
 * @property string|null $last_error
 */
class DeliveryHook extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected $hidden = ['signing_key', 'token', 'token_hash'];

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function url(): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/api/v1/hooks/delivery/'.$this->token;
    }

    protected function casts(): array
    {
        return ['token' => 'encrypted', 'signing_key' => 'encrypted', 'events' => 'integer', 'last_event_at' => 'datetime'];
    }
}
