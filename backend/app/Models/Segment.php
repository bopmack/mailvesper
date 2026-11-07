<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $name
 * @property string|null $group the site a ready-made segment came from
 * @property string|null $site_connection_id set: a live audience on that connected site, not contacts
 * @property string|null $preset_key
 * @property array{match: string, conditions: list<array<string, mixed>>} $rules
 * @property int|null $cached_count
 * @property Carbon|null $counted_at
 * @property Carbon|null $created_at
 */
class Segment extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['rules' => 'array', 'cached_count' => 'integer', 'counted_at' => 'datetime'];
    }
}
