<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $tracking_code
 * @property string|null $batch_id
 * @property string|null $campaign_id
 * @property string|null $variant
 * @property string $source
 * @property string|null $api_key_id
 * @property string $type
 * @property string $from_email
 * @property string|null $from_name
 * @property string $to_email
 * @property string|null $provider
 * @property Carbon|null $opened_at
 * @property Carbon|null $clicked_at
 * @property int $open_count
 * @property int $machine_open_count
 * @property int $click_count
 * @property list<string>|null $reply_to
 * @property string $subject
 * @property string|null $html
 * @property string|null $text
 * @property array<string, string>|null $headers
 * @property array<string, string>|null $tags
 * @property string $status
 * @property string|null $ses_message_id
 * @property string|null $unsubscribe_token
 * @property int $attempts
 * @property string|null $error
 * @property Carbon|null $sent_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $last_event_at
 * @property Carbon|null $created_at
 */
class Message extends Model
{
    use HasUlids;

    /** Later events never move a message back to an earlier state. */
    public const RANK = ['queued' => 0, 'sending' => 1, 'failed' => 2, 'suppressed' => 2, 'sent' => 3, 'delayed' => 4, 'rejected' => 5, 'delivered' => 6, 'bounced' => 7, 'complained' => 8];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['reply_to' => 'array', 'headers' => 'array', 'tags' => 'array', 'attempts' => 'integer',
            'sent_at' => 'datetime', 'delivered_at' => 'datetime', 'last_event_at' => 'datetime',
            'opened_at' => 'datetime', 'clicked_at' => 'datetime', 'open_count' => 'integer', 'machine_open_count' => 'integer', 'click_count' => 'integer'];
    }

    /** SL-XXXX-XXXX, unambiguous characters only. */
    public static function newTrackingCode(): string
    {
        $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        do {
            $c = '';
            for ($i = 0; $i < 8; $i++) {
                $c .= $alphabet[random_int(0, 31)];
            }
            $code = 'SL-'.substr($c, 0, 4).'-'.substr($c, 4);
        } while (self::where('tracking_code', $code)->exists());

        return $code;
    }

    public static function newUnsubscribeToken(): string
    {
        return Str::random(40);
    }

    public function moveTo(string $status): bool
    {
        if ((self::RANK[$status] ?? 0) <= (self::RANK[$this->status] ?? 0)) {
            return false;
        }
        $this->status = $status;

        return true;
    }
}
