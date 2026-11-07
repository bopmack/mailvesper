<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $email
 * @property string $purpose
 * @property string $code_hash
 * @property int $attempts
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property Carbon|null $created_at
 */
class EmailCode extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['email', 'purpose', 'code_hash', 'attempts', 'expires_at', 'consumed_at', 'ip_address'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'consumed_at' => 'datetime', 'attempts' => 'integer'];
    }
}
