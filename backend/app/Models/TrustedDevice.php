<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $user_id
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $last_used_at
 */
class TrustedDevice extends Model
{
    protected $fillable = ['user_id', 'token_hash', 'user_agent', 'ip_address', 'expires_at', 'last_used_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'last_used_at' => 'datetime'];
    }
}
