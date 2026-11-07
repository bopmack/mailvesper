<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property string $owner_id
 * @property string|null $send_source
 * @property Carbon|null $trial_ends_at
 * @property array<string, mixed>|null $brand
 * @property array<string, mixed>|null $settings
 * @property Carbon|null $suspended_at
 * @property Carbon|null $deleting_at
 * @property string|null $suspended_reason
 * @property string|null $sending_method
 */
class Workspace extends Model
{
    use HasUlids;

    protected $fillable = ['name', 'slug', 'owner_id', 'send_source', 'trial_ends_at'];

    protected function casts(): array
    {
        return ['suspended_at' => 'datetime', 'deleting_at' => 'datetime', 'trial_ends_at' => 'datetime', 'brand' => 'array', 'settings' => 'array'];
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsToMany<User, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_user')->withPivot('role')->withTimestamps();
    }
}
