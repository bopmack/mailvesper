<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string|null $workspace_id
 * @property string|null $user_id
 * @property string $action
 * @property array<string, mixed>|null $meta
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['workspace_id', 'user_id', 'action', 'ip_address', 'user_agent', 'meta'];

    protected function casts(): array
    {
        return ['meta' => 'array', 'created_at' => 'datetime'];
    }
}
