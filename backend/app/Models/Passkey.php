<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $user_id
 * @property string $name
 * @property string $credential_id
 * @property string $public_key
 * @property int $sign_count
 * @property list<string>|null $transports
 * @property Carbon|null $last_used_at
 * @property Carbon|null $created_at
 * @property-read User $user
 */
class Passkey extends Model
{
    use HasUlids;

    protected $fillable = ['user_id', 'name', 'credential_id', 'public_key', 'sign_count', 'transports', 'aaguid', 'last_used_at'];

    protected $hidden = ['public_key'];

    protected function casts(): array
    {
        return ['transports' => 'array', 'last_used_at' => 'datetime', 'sign_count' => 'integer'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
