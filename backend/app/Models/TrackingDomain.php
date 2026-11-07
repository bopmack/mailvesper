<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $workspace_id
 * @property string $host
 * @property string $status pending | active
 * @property bool $ses_ready
 * @property Carbon|null $verified_at
 * @property Carbon|null $last_checked_at
 * @property string|null $last_error
 */
class TrackingDomain extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['ses_ready' => 'boolean', 'verified_at' => 'datetime', 'last_checked_at' => 'datetime'];
    }
}
