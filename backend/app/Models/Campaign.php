<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $name
 * @property string $status
 * @property string|null $audience_type
 * @property string|null $audience_id
 * @property list<string>|null $audience_contacts
 * @property bool $exclude_dormant
 * @property string|null $topic_id
 * @property string|null $from_email
 * @property string|null $from_name
 * @property string|null $reply_to
 * @property string|null $subject
 * @property string|null $subject_b
 * @property string|null $preheader
 * @property array<string, mixed>|null $doc
 * @property string|null $html
 * @property string $format design (the builder) | plain (a plain-text letter)
 * @property string|null $text_body
 * @property bool $letter_tracking
 * @property float|null $size_kb
 * @property list<array{name: string, status: string, detail: string}>|null $checks
 * @property bool $ab_enabled
 * @property int $ab_test_pct
 * @property string $ab_metric
 * @property int $ab_wait_hours
 * @property string|null $ab_winner
 * @property Carbon|null $ab_decided_at
 * @property string $send_mode
 * @property Carbon|null $scheduled_at
 * @property string|null $local_time
 * @property Carbon|null $local_date
 * @property string|null $timezone
 * @property int $recipients_count
 * @property string|null $share_token
 * @property string|null $created_by
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $paused_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Campaign extends Model
{
    use HasUlids;

    public const EDITABLE = ['draft', 'scheduled'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['doc' => 'array', 'checks' => 'array', 'audience_contacts' => 'array', 'exclude_dormant' => 'boolean', 'letter_tracking' => 'boolean', 'ab_enabled' => 'boolean', 'ab_test_pct' => 'integer', 'ab_wait_hours' => 'integer',
            'size_kb' => 'float', 'recipients_count' => 'integer', 'scheduled_at' => 'datetime', 'local_date' => 'date', 'ab_decided_at' => 'datetime',
            'started_at' => 'datetime', 'finished_at' => 'datetime', 'paused_at' => 'datetime'];
    }
}
