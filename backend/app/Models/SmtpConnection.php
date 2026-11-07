<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A workspace's own SMTP server for sending (instead of Amazon SES).
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $host
 * @property int $port
 * @property string $encryption
 * @property string|null $username
 * @property string|null $password
 * @property int $max_per_second
 * @property int|null $max_per_day
 * @property Carbon|null $verified_at
 * @property string|null $last_error
 * @property Carbon|null $last_checked_at
 */
class SmtpConnection extends Model
{
    use HasUlids;

    public const ENCRYPTION = ['tls', 'ssl', 'none'];

    protected $guarded = ['id'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return ['password' => 'encrypted', 'port' => 'integer', 'max_per_second' => 'integer', 'max_per_day' => 'integer', 'verified_at' => 'datetime', 'last_checked_at' => 'datetime'];
    }
}
