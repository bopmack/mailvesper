<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $user_id
 * @property string $provider
 * @property string $provider_user_id
 * @property string|null $email
 */
class SocialAccount extends Model
{
    protected $fillable = ['user_id', 'provider', 'provider_user_id', 'email'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
