<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A sender proven for SMTP sending: a whole domain (TXT record) or one address (emailed code).
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $kind
 * @property string $value
 * @property string $status
 * @property string $token
 * @property Carbon|null $code_expires_at
 * @property int $code_attempts
 * @property array<string, string>|null $dns_check
 * @property Carbon|null $verified_at
 * @property Carbon|null $last_checked_at
 */
class SenderIdentity extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['dns_check' => 'array', 'code_expires_at' => 'datetime', 'verified_at' => 'datetime', 'last_checked_at' => 'datetime', 'code_attempts' => 'integer'];
    }
}
