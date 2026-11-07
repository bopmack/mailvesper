<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $domain
 * @property string $from_email
 * @property string|null $message_id
 * @property string $token
 * @property string $status waiting | done | failed
 * @property array<string, mixed>|null $result
 * @property string|null $error
 * @property Carbon $created_at
 * @property Carbon|null $finished_at
 */
class DeliverabilityCheck extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['result' => 'array', 'finished_at' => 'datetime'];
    }
}
