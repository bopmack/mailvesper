<?php

namespace App\Services\Mailbox;

use App\Exceptions\ApiException;
use App\Models\Contact;
use App\Models\Mailbox;
use App\Models\Message;
use App\Services\Activity\Timeline;
use App\Services\Copilot\DmarcReports;
use App\Services\Deliverability\FeedbackLoop;
use App\Services\Journeys\JourneyEvents;
use App\Services\Ses\EventProcessor;
use ZBateson\MailMimeParser\Header\AddressHeader;
use ZBateson\MailMimeParser\IMessage;
use ZBateson\MailMimeParser\Message as MimeMessage;
use ZBateson\MailMimeParser\Message\IMessagePart;

/**
 * One email that arrived in a workspace's mailbox, sorted into:
 * - a spam complaint from a provider's feedback loop (an ARF report about one of the workspace's emails);
 * - a late bounce (a delivery-status report from the receiving side, after the SMTP server accepted the email):
 *   applied like any bounce, so a permanent failure suppresses the address;
 * - a DMARC aggregate report: added to Copilot's DMARC view;
 * - a reply to one of the workspace's emails: shown on the email's timeline and sent to journeys as email.replied.
 * Out-of-office and other automatic replies are not replies. Anything else is left alone.
 */
class Inbound
{
    /** How far back a bounce or reply is matched to a sent email. */
    public const MATCH_DAYS = 60;

    public function __construct(private EventProcessor $events, private DmarcReports $dmarc, private Timeline $timeline, private JourneyEvents $journeys, private FeedbackLoop $complaints) {}

    /** @return 'bounce'|'complaint'|'report'|'reply'|null what it was */
    public function handle(Mailbox $box, string $raw): ?string
    {
        $mail = MimeMessage::from($raw, false);
        if (FeedbackLoop::isReport($mail)) { // also DMARC failure reports: never a reply
            return $box->read_bounces && $this->complaints->handle($mail, $box->workspace_id) ? 'complaint' : null;
        }
        if ($box->read_bounces && ($this->isBounce($mail))) {
            return $this->bounce($box, $mail) ? 'bounce' : null;
        }
        if ($box->read_dmarc && ($xml = $this->dmarcAttachment($mail)) !== null) {
            try {
                $this->dmarc->ingest($box->workspace_id, $xml);

                return 'report';
            } catch (ApiException) {
                return null; // not a report, or for a domain that isn't the workspace's
            }
        }
        if ($box->read_replies && ! $this->isAutomatic($mail)) {
            return $this->reply($box, $mail) ? 'reply' : null;
        }

        return null;
    }

    private function isBounce(IMessage $mail): bool
    {
        $ct = strtolower((string) $mail->getHeaderValue('Content-Type'));
        $type = strtolower((string) $mail->getHeaderParameter('Content-Type', 'report-type'));

        return ($ct === 'multipart/report' && $type === 'delivery-status') || $this->part($mail, ['message/delivery-status']) !== null;
    }

    private function bounce(Mailbox $box, IMessage $mail): bool
    {
        $status = (string) $this->part($mail, ['message/delivery-status'])?->getContent();
        // Per-recipient blocks: Final-Recipient, Action, Status, Diagnostic-Code.
        $recipient = preg_match('/^Final-Recipient:\s*[^;]*;\s*<?([^\s>]+)>?/mi', $status, $m) ? mb_strtolower($m[1]) : null;
        $action = preg_match('/^Action:\s*(\S+)/mi', $status, $m) ? strtolower($m[1]) : null;
        $code = preg_match('/^Status:\s*([245])\.(\d{1,3})\.(\d{1,3})/mi', $status, $m) ? "$m[1].$m[2].$m[3]" : null;
        $diagnostic = preg_match('/^Diagnostic-Code:\s*(?:[^;]*;\s*)?(.+(?:\r?\n[ \t].+)*)/mi', $status, $m) ? trim((string) preg_replace('/\s+/', ' ', $m[1])) : null;
        if ($action !== 'failed' || ! $code) {
            return false; // "delayed" and "delivered" notices aren't bounces
        }
        // Our own header comes back in the returned headers; otherwise the recipient's latest email.
        $original = (string) ($this->part($mail, ['text/rfc822-headers', 'message/rfc822-headers'])?->getContent() ?? $this->part($mail, ['message/rfc822'])?->getContent() ?? '');
        $message = preg_match('/^X-Sendline-Tracking:\s*([A-Za-z0-9-]{6,20})/mi', $original, $t)
            ? Message::where('workspace_id', $box->workspace_id)->where('tracking_code', $t[1])->first() : null;
        if (! $message && $recipient) {
            $message = Message::where('workspace_id', $box->workspace_id)->where('to_email', $recipient)->whereNotNull('sent_at')
                ->where('sent_at', '>', now()->subDays(self::MATCH_DAYS))->orderByDesc('sent_at')->first();
        }
        if (! $message || $message->status === 'bounced' || $message->sent_at === null || $message->sent_at->lt(now()->subDays(self::MATCH_DAYS))) {
            return false;
        }
        $this->events->apply($box->workspace_id, ['eventType' => 'Bounce', 'mail' => ['messageId' => $message->ses_message_id ?? '', 'tags' => ['sl_message' => [$message->id]]],
            'bounce' => ['bounceType' => $code[0] === '5' ? 'Permanent' : 'Transient', 'bounceSubType' => 'General', 'timestamp' => now()->toIso8601ZuluString(),
                'bouncedRecipients' => [['emailAddress' => $recipient ?: $message->to_email, 'status' => $code, 'diagnosticCode' => mb_substr($diagnostic ?? "Status $code (reported later by email)", 0, 500)]]]]);

        return true;
    }

    /** The first zip, gzip or XML attachment, as raw bytes (DmarcReports unpacks it). */
    private function dmarcAttachment(IMessage $mail): ?string
    {
        foreach ($mail->getAllParts() as $part) {
            $ct = strtolower((string) $part->getContentType(''));
            $name = strtolower((string) $part->getFilename());
            $isReport = in_array($ct, ['application/zip', 'application/x-zip-compressed', 'application/gzip', 'application/x-gzip', 'application/xml', 'text/xml'], true)
                || preg_match('/\.(xml|xml\.gz|zip|gz)$/', $name);
            if ($isReport && $part !== $mail) {
                $bytes = $part->getBinaryContentStream()?->getContents();
                if (is_string($bytes) && $bytes !== '' && strlen($bytes) < 5_000_000) {
                    return $bytes;
                }
            }
        }

        return null;
    }

    /** Out-of-office, vacation and other automatic answers (RFC 3834 and the common non-standard headers). */
    private function isAutomatic(IMessage $mail): bool
    {
        $auto = strtolower((string) $mail->getHeaderValue('Auto-Submitted', 'no'));
        $precedence = strtolower((string) $mail->getHeaderValue('Precedence', ''));

        return ($auto !== '' && $auto !== 'no') || in_array($precedence, ['bulk', 'junk', 'list', 'auto_reply'], true)
            || $mail->getHeader('X-Autoreply') !== null || $mail->getHeader('X-Autorespond') !== null || $mail->getHeader('X-Auto-Response-Suppress') !== null
            || (bool) preg_match('/^(auto(matic)?[ -]?reply|out of (the )?office|abwesenheit|absence|réponse automatique)/i', (string) $mail->getHeaderValue('Subject'));
    }

    private function reply(Mailbox $box, IMessage $mail): bool
    {
        $from = $mail->getHeader('From');
        $email = $from instanceof AddressHeader ? mb_strtolower((string) $from->getEmail()) : '';
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strtolower($box->username) === $email) {
            return false;
        }
        $message = $this->original($box->workspace_id, $mail, $email);
        if (! $message) {
            return false;
        }
        $subject = mb_substr((string) $mail->getHeaderValue('Subject'), 0, 200);
        $this->timeline->record($box->workspace_id, $message, 'replied', ['from' => $email, 'subject' => $subject, 'snippet' => $this->snippet((string) $mail->getTextContent())]);
        if ($contact = Contact::where('workspace_id', $box->workspace_id)->where('email', $email)->first()) {
            $this->journeys->fire($contact, 'email.replied', array_filter(['message_id' => $message->id, 'campaign_id' => $message->campaign_id,
                'journey_id' => $message->journey_id, 'journey_node' => $message->journey_node, 'subject' => $message->subject], fn ($v) => $v !== null), 'email');
        }

        return true;
    }

    /** The email this answers: by In-Reply-To / References, else the sender's latest email with the same subject. */
    private function original(string $workspaceId, IMessage $mail, string $from): ?Message
    {
        $ids = [];
        foreach (['In-Reply-To', 'References'] as $h) {
            preg_match_all('/<([^<>\s]+)>/', (string) $mail->getHeader($h)?->getRawValue(), $m); // raw: the parsed value drops the <>
            $ids = [...$ids, ...$m[1]];
        }
        $recent = fn () => Message::where('workspace_id', $workspaceId)->where('sent_at', '>', now()->subDays(self::MATCH_DAYS));
        foreach (array_reverse(array_unique($ids)) as $id) { // the newest reference first
            // SMTP: our Message-ID as sent. SES: <its message id>@<region>.amazonses.com.
            $candidates = str_ends_with(strtolower($id), 'amazonses.com') ? [strstr($id, '@', true)] : [$id];
            if ($m = $recent()->whereIn('ses_message_id', array_filter($candidates))->first()) {
                return $m;
            }
        }
        $subject = trim((string) preg_replace('/^((re|aw|sv|antw|r|fw|fwd|wg)\s*(\[\d+\])?\s*:\s*)+/i', '', (string) $mail->getHeaderValue('Subject')));
        if ($subject === '') {
            return null;
        }

        return $recent()->where('to_email', $from)->where('subject', $subject)->orderByDesc('sent_at')->first();
    }

    /** The new text of a reply, without the quoted original. */
    private function snippet(string $text): string
    {
        $keep = [];
        foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
            if (preg_match('/^\s*>/', $line) || preg_match('/^(On .+wrote:|Le .+a écrit|Am .+schrieb|-----Original Message-----|From: )/i', trim($line))) {
                break;
            }
            $keep[] = $line;
        }

        return mb_substr(trim((string) preg_replace('/\s+/', ' ', implode(' ', $keep))), 0, 300);
    }

    /**
     * @param  list<string>  $types
     */
    private function part(IMessage $mail, array $types): ?IMessagePart
    {
        foreach ($mail->getAllParts() as $part) {
            if (in_array(strtolower((string) $part->getContentType('')), $types, true)) {
                return $part;
            }
        }

        return null;
    }
}
