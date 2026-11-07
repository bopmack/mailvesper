<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $name
 * @property string|null $description
 * @property bool $default_subscribed
 */
class Topic extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['default_subscribed' => 'boolean'];
    }
}
