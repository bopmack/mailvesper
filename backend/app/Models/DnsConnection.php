<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $workspace_id
 * @property string $provider
 * @property string $token
 * @property Carbon|null $verified_at
 */
class DnsConnection extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['token' => 'encrypted', 'verified_at' => 'datetime'];
    }
}
