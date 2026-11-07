<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $endpoint_id
 * @property string $workspace_id
 * @property string $event_type
 * @property string|null $message_id
 * @property array<string, mixed> $payload
 * @property string $status
 * @property int $attempts
 * @property int|null $response_code
 * @property string|null $response_body
 * @property string|null $error
 * @property int|null $duration_ms
 * @property Carbon|null $next_attempt_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $created_at
 */
class WebhookDelivery extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'attempts' => 'integer', 'response_code' => 'integer', 'duration_ms' => 'integer', 'next_attempt_at' => 'datetime', 'delivered_at' => 'datetime'];
    }
}
