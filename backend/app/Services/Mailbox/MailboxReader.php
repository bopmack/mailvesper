<?php

namespace App\Services\Mailbox;

use App\Models\Mailbox;
use Illuminate\Support\Facades\Log;

/**
 * Reads the mail that arrived since the last check (by IMAP UID, never changing the mailbox). The first check,
 * or a mailbox whose UIDs were renumbered, starts from "now": old mail isn't replayed.
 */
class MailboxReader
{
    /** Messages read per mailbox per check; the rest wait for the next check. */
    public static int $batch = 200;

    public function __construct(private Inbound $inbound) {}

    /** @return array{read: int, replies: int, bounces: int, complaints: int, reports: int} */
    public function check(Mailbox $box, float $budgetSeconds = 50): array
    {
        $out = ['read' => 0, 'replies' => 0, 'bounces' => 0, 'complaints' => 0, 'reports' => 0];
        $until = microtime(true) + $budgetSeconds;
        try {
            $conn = ImapClient::connect($box);
            try {
                ['validity' => $validity, 'next' => $next] = $conn->select($box->folder);
                if ($box->last_uid === null || $box->uid_validity !== $validity) {
                    $box->forceFill(['uid_validity' => $validity, 'last_uid' => max(0, $next - 1)])->save();
                } else {
                    foreach (array_slice($conn->uidsFrom($box->last_uid + 1), 0, self::$batch) as $uid) {
                        if (microtime(true) > $until) {
                            break;
                        }
                        $raw = $conn->fetch($uid);
                        $kind = null;
                        try {
                            $kind = $raw === null ? null : $this->inbound->handle($box, $raw);
                        } catch (\Throwable $e) {
                            Log::warning('Mailbox message couldn’t be read', ['mailbox' => $box->id, 'uid' => $uid, 'error' => $e->getMessage()]);
                        }
                        $out['read']++;
                        if ($kind) {
                            $out[match ($kind) {
                                'reply' => 'replies', 'bounce' => 'bounces', 'complaint' => 'complaints', default => 'reports'
                            }]++;
                        }
                        $box->last_uid = $uid; // saved below, and after every message so a crash never re-reads much
                        $box->save();
                    }
                }
            } finally {
                $conn->close();
            }
            $box->forceFill(['last_checked_at' => now(), 'last_error' => null, 'replies' => $box->replies + $out['replies'],
                'bounces' => $box->bounces + $out['bounces'], 'complaints' => $box->complaints + $out['complaints'], 'reports' => $box->reports + $out['reports']])->save();
        } catch (MailboxError $e) {
            $box->forceFill(['last_checked_at' => now(), 'last_error' => mb_substr($e->getMessage(), 0, 300)])->save();
            throw $e;
        }

        return $out;
    }
}
