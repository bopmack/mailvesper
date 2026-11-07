<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $url
 * @property string|null $description
 * @property list<string> $events
 * @property string $secret
 * @property bool $enabled
 * @property string|null $disabled_reason
 * @property int $failure_streak
 * @property Carbon|null $last_success_at
 * @property Carbon|null $last_failure_at
 * @property string|null $created_by
 * @property Carbon|null $created_at
 */
class WebhookEndpoint extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return ['events' => 'array', 'secret' => 'encrypted', 'enabled' => 'boolean', 'failure_streak' => 'integer', 'last_success_at' => 'datetime', 'last_failure_at' => 'datetime'];
    }

    public function wants(string $type): bool
    {
        return in_array('*', $this->events, true) || in_array($type, $this->events, true);
    }

    public static function newSecret(): string
    {
        return 'whsec_'.base64_encode(random_bytes(32));
    }
}
