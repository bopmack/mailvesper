<?php

namespace App\Services\Deliverability;

use App\Models\Message;
use App\Services\Mailbox\ImapClient;
use App\Services\Mailbox\MailboxError;
use App\Services\Mailbox\PlatformMailbox;
use App\Services\Ses\EventProcessor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use ZBateson\MailMimeParser\Header\AddressHeader;
use ZBateson\MailMimeParser\IMessage;
use ZBateson\MailMimeParser\Message as MimeMessage;
use ZBateson\MailMimeParser\Message\IMessagePart;

/**
 * Spam complaints sent back by mailbox providers' feedback loops (Yahoo/AOL CFL, Microsoft JMRP, Comcast, …) as ARF
 * reports (RFC 5965). A report carries the original email, whose X-Sendline-Tracking header names the message even
 * when the provider redacts the recipient. The complaint is applied like an SES one: the address is suppressed,
 * journeys and webhooks hear about it. Gmail has no such loop (Feedback-ID totals in Postmaster Tools instead).
 *
 * Reports arrive in a workspace's own mailbox, or in the installation's complaint mailbox (Platform → Settings →
 * Complaint feedback loops), whose address customers give the providers when they enrol their domain or IP.
 */
class FeedbackLoop
{
    /** Feedback types that are complaints. "auth-failure" is a DMARC failure report; "not-spam" undoes nothing here. */
    public const COMPLAINTS = ['abuse', 'fraud'];

    /** Reports read per check; the rest wait. */
    public static int $batch = 200;

    public function __construct(private EventProcessor $events) {}

    public static function isReport(IMessage $mail): bool
    {
        return strtolower((string) $mail->getHeaderParameter('Content-Type', 'report-type')) === 'feedback-report' || self::part($mail, 'message/feedback-report') !== null;
    }

    /**
     * Applies one report. $workspaceId: only that workspace's emails (its own mailbox); null: any (the installation's
     * complaint mailbox, which also takes the older non-ARF style: a note with the original email attached).
     *
     * @return bool whether it was a complaint about one of our emails, applied now
     */
    public function handle(IMessage $mail, ?string $workspaceId): bool
    {
        $report = (string) self::part($mail, 'message/feedback-report')?->getContent();
        if ($report !== '') {
            $type = preg_match('/^Feedback-Type:\s*([A-Za-z-]+)/mi', $report, $m) ? strtolower($m[1]) : '';
            if (! in_array($type, self::COMPLAINTS, true)) {
                return false;
            }
        } elseif ($workspaceId !== null || ! self::part($mail, 'message/rfc822') || self::part($mail, 'message/delivery-status')) {
            return false; // a bounce also carries the original
        } else {
            $type = 'abuse';
        }
        $original = (string) (self::part($mail, 'message/rfc822')?->getContent() ?? self::part($mail, 'text/rfc822-headers')?->getContent() ?? '');
        $message = $this->find($original, $workspaceId);
        if (! $message || DB::table('message_events')->where('message_id', $message->id)->where('type', 'complained')->exists()) {
            return false; // not ours, or already reported (by SES, a provider webhook or an earlier copy)
        }
        $by = self::reporter($mail, $report);
        $this->events->apply($message->workspace_id, ['eventType' => 'Complaint', 'mail' => ['messageId' => $message->ses_message_id ?? '', 'tags' => ['sl_message' => [$message->id]]],
            'complaint' => ['complaintFeedbackType' => $type, 'userAgent' => $by, 'timestamp' => now()->toIso8601ZuluString(),
                'complainedRecipients' => [['emailAddress' => $message->to_email]]]]);

        return true;
    }

    /** The installation's complaint mailbox: reads what arrived since the last check. Returns complaints applied. */
    public function collect(float $budgetSeconds = 50): int
    {
        if (! PlatformMailbox::configured('fbl')) {
            return 0;
        }
        $until = microtime(true) + $budgetSeconds;
        $state = (array) Cache::get('fbl.mailbox', []);
        $done = 0;
        try {
            $conn = ImapClient::connect(PlatformMailbox::make('fbl'));
        } catch (MailboxError $e) {
            Cache::forever('fbl.status', ['checked_at' => now()->toIso8601ZuluString(), 'error' => mb_substr($e->getMessage(), 0, 300)] + (array) Cache::get('fbl.status', []));

            return 0;
        }
        try {
            ['validity' => $validity, 'next' => $next] = $conn->select('INBOX');
            if (($state['validity'] ?? null) !== $validity) {
                $state = ['validity' => $validity, 'last' => max(0, $next - 1)]; // first check: start from now
            } else {
                foreach (array_slice($conn->uidsFrom((int) $state['last'] + 1), 0, self::$batch) as $uid) {
                    if (microtime(true) > $until) {
                        break;
                    }
                    try {
                        $raw = $conn->fetch($uid);
                        $done += $raw !== null && $this->handle(MimeMessage::from($raw, false), null) ? 1 : 0;
                    } catch (\Throwable $e) {
                        Log::warning('Complaint report couldn’t be read', ['uid' => $uid, 'error' => $e->getMessage()]);
                    }
                    $state['last'] = $uid;
                    Cache::forever('fbl.mailbox', $state);
                }
            }
            Cache::forever('fbl.mailbox', $state);
        } finally {
            $conn->close();
        }
        $status = (array) Cache::get('fbl.status', []);
        Cache::forever('fbl.status', ['checked_at' => now()->toIso8601ZuluString(), 'error' => null, 'complaints' => (int) ($status['complaints'] ?? 0) + $done]);

        return $done;
    }

    /** By our tracking header, else the original Message-ID (ours over SMTP; SES's id@…amazonses.com). */
    private function find(string $original, ?string $workspaceId): ?Message
    {
        $scope = fn () => $workspaceId === null ? Message::query() : Message::where('workspace_id', $workspaceId);
        if (preg_match('/^X-Sendline-Tracking:\s*([A-Za-z0-9-]{6,20})/mi', $original, $t) && ($m = $scope()->where('tracking_code', $t[1])->first())) {
            return $m;
        }
        if (preg_match('/^Message-ID:\s*<([^<>\s]+)>/mi', $original, $id)) {
            $sesId = str_ends_with(strtolower($id[1]), 'amazonses.com') ? (string) strstr($id[1], '@', true) : $id[1];

            return $scope()->where('ses_message_id', $sesId)->first();
        }

        return null;
    }

    /** Who reported it: the ARF User-Agent's provider, else the report sender's domain. */
    private static function reporter(IMessage $mail, string $report): string
    {
        $ua = preg_match('/^User-Agent:\s*(.+)$/mi', $report, $m) ? trim($m[1]) : '';
        $from = $mail->getHeader('From');
        $domain = $from instanceof AddressHeader ? mb_strtolower(substr((string) strrchr((string) $from->getEmail(), '@'), 1)) : '';
        $hay = strtolower($ua.' '.$domain);

        return match (true) {
            str_contains($hay, 'yahoo') || str_contains($hay, 'aol') => 'Yahoo',
            (bool) preg_match('/hotmail|outlook|microsoft|msn\.com|live\.com/', $hay) => 'Microsoft',
            str_contains($hay, 'comcast') => 'Comcast',
            $domain !== '' => $domain,
            default => mb_substr($ua ?: 'Feedback loop', 0, 60),
        };
    }

    private static function part(IMessage $mail, string $type): ?IMessagePart
    {
        foreach ($mail->getAllParts() as $part) {
            if ($part !== $mail && strtolower((string) $part->getContentType('')) === $type) {
                return $part;
            }
        }

        return null;
    }
}
