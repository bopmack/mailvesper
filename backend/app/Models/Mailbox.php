<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $host
 * @property int $port
 * @property string $encryption ssl | tls
 * @property string $username
 * @property string $password
 * @property string $folder
 * @property bool $read_replies
 * @property bool $read_bounces
 * @property bool $read_dmarc
 * @property bool $enabled
 * @property int|null $uid_validity
 * @property int|null $last_uid
 * @property int $replies
 * @property int $bounces
 * @property int $complaints
 * @property int $reports
 * @property Carbon|null $last_checked_at
 * @property string|null $last_error
 */
class Mailbox extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return ['password' => 'encrypted', 'port' => 'integer', 'read_replies' => 'boolean', 'read_bounces' => 'boolean', 'read_dmarc' => 'boolean', 'enabled' => 'boolean',
            'uid_validity' => 'integer', 'last_uid' => 'integer', 'replies' => 'integer', 'bounces' => 'integer', 'complaints' => 'integer', 'reports' => 'integer', 'last_checked_at' => 'datetime'];
    }
}
