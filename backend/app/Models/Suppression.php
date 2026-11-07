<?php

namespace App\Models;

use App\Services\Contacts\ContactService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property string $workspace_id
 * @property string $email
 * @property string $reason
 * @property string|null $detail
 * @property string|null $message_id
 * @property Carbon|null $created_at
 */
class Suppression extends Model
{
    public const UPDATED_AT = null;

    public const REASONS = ['hard_bounce', 'complaint', 'unsubscribe', 'manual'];

    protected $guarded = ['id'];

    /** Adds (or keeps) an address. A complaint or bounce outranks an unsubscribe or manual entry. */
    public static function add(string $workspaceId, string $email, string $reason, ?string $detail = null, ?string $messageId = null): void
    {
        $email = mb_strtolower(trim($email));
        DB::table('suppressions')->insertOrIgnore(['workspace_id' => $workspaceId, 'email' => $email, 'reason' => $reason, 'detail' => $detail ? mb_substr($detail, 0, 255) : null, 'message_id' => $messageId, 'created_at' => now()]);
        if (in_array($reason, ['hard_bounce', 'complaint'], true)) {
            DB::table('suppressions')->where('workspace_id', $workspaceId)->where('email', $email)->whereIn('reason', ['unsubscribe', 'manual'])
                ->update(['reason' => $reason, 'detail' => $detail ? mb_substr($detail, 0, 255) : null, 'message_id' => $messageId]);
        }
        ContactService::syncStatus($workspaceId, $email);
    }

    /** Removes an address and lets its contact receive email again. */
    public static function remove(string $workspaceId, string $email, ?string $onlyReason = null): int
    {
        $email = mb_strtolower(trim($email));
        $n = DB::table('suppressions')->where('workspace_id', $workspaceId)->where('email', $email)->when($onlyReason, fn ($q, $r) => $q->where('reason', $r))->delete();
        ContactService::syncStatus($workspaceId, $email);

        return $n;
    }

    /**
     * Whether a suppression stops this email. An unsubscribe stops marketing (and anything sent under a topic) only:
     * password resets, receipts and other transactional email still arrive. Bounces, complaints and manual blocks
     * stop everything.
     */
    public static function blocks(?string $reason, string $type, bool $topic = false): bool
    {
        return $reason !== null && ! ($reason === 'unsubscribe' && $type === 'transactional' && ! $topic);
    }

    /**
     * @param  list<string>  $emails
     * @return array<string, string> email => reason, for the ones suppressed
     */
    public static function check(string $workspaceId, array $emails): array
    {
        if (! $emails) {
            return [];
        }

        return DB::table('suppressions')->where('workspace_id', $workspaceId)->whereIn('email', array_map(fn ($e) => mb_strtolower($e), $emails))->pluck('reason', 'email')->all();
    }
}
