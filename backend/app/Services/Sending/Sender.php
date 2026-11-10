<?php

namespace App\Services\Sending;

use App\Jobs\SendMessage;
use App\Models\Message;
use App\Models\SesConnection;
use App\Models\SmtpConnection;
use App\Models\Suppression;
use App\Services\Activity\Timeline;
use App\Services\Platform\Brand;
use App\Services\Ses\AwsFactory;
use App\Services\Ses\EventProcessor;
use App\Services\Smtp\RelayEvents;
use App\Services\Smtp\SmtpClient;
use App\Services\Smtp\Tracking;
use Aws\Exception\AwsException;
use GuzzleHttp\Promise\Each;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Sends queued messages through the workspace's SES (or its own SMTP server). Retries throttling and outages with
 * backoff; everything else fails with a reason. SES sends from one call go out in parallel on one reused connection,
 * never faster than the account's send rate (Pacer counts across every worker).
 */
class Sender
{
    public const MAX_ATTEMPTS = 6;

    /** SES requests in flight at once per worker. */
    public const IN_FLIGHT = 10;

    public const RETRYABLE = ['Throttling', 'ThrottlingException', 'TooManyRequestsException', 'ServiceUnavailable', 'InternalFailure', 'InternalServiceError', 'RequestTimeout', ''];

    public function __construct(private AwsFactory $aws, private Pacer $pacer) {}

    /** @param  list<string>  $ids */
    public function send(array $ids): void
    {
        $claimed = [];
        foreach ($ids as $id) {
            if ($this->withdrawIfHeld($id)) {
                continue;
            }
            // The workspace was deleted: drop the email instead of sending it (PurgeWorkspace removes the rest).
            if (DB::table('messages')->join('workspaces', 'workspaces.id', '=', 'messages.workspace_id')
                ->where('messages.id', $id)->whereNotNull('workspaces.deleting_at')->exists()) {
                DB::table('messages')->where('id', $id)->where('status', 'queued')->delete();

                continue;
            }
            // Claim the message so two workers never send it twice.
            if (DB::table('messages')->where('id', $id)->where('status', 'queued')
                ->update(['status' => 'sending', 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()])) {
                $claimed[] = $id;
            }
        }
        if ($claimed === []) {
            return;
        }

        foreach (Message::whereIn('id', $claimed)->get()->groupBy('workspace_id') as $wsId => $group) {
            $wsId = (string) $wsId;
            $reasons = Suppression::check($wsId, $group->pluck('to_email')->all());
            $send = [];
            foreach ($group as $m) {
                $reason = $reasons[mb_strtolower($m->to_email)] ?? null;
                if (Suppression::blocks($reason, (string) $m->type, $m->topic_id !== null)) {
                    $this->finish($m, 'suppressed', 'Not sent: this address is on your suppression list ('.str_replace('_', ' ', (string) $reason).').');
                } else {
                    $send[] = $m;
                }
            }
            if ($send === []) {
                continue;
            }
            if (Senders::method($wsId) === Senders::SMTP) {
                foreach ($send as $m) {
                    $this->viaSmtp($m);
                }

                continue;
            }
            $conn = SesConnection::where('workspace_id', $wsId)->first();
            if (! $conn) {
                foreach ($send as $m) {
                    $this->finish($m, 'failed', 'Amazon SES isn’t connected any more.');
                }

                continue;
            }
            $this->viaSes($conn, $send);
        }
    }

    /**
     * Amazon SES: each round takes as many slots as the send rate allows this second (shared with every other
     * worker), then sends that many at once, at most IN_FLIGHT requests in flight.
     *
     * @param  list<Message>  $messages
     */
    private function viaSes(SesConnection $conn, array $messages): void
    {
        $client = $this->aws->sender($conn);
        $issued = 0;
        // Slots are reserved a few at a time as emails are handed to SES, and a reservation is only used within its
        // own second: a slow answer from SES can't push one second's emails into the next second's allowance.
        $calls = function () use ($messages, $client, $conn, &$issued) {
            [$left, $second] = [0, 0];
            foreach ($messages as $m) {
                if ($left === 0 || time() !== $second) {
                    [$left, $second] = $this->pacer->reserve($conn, min(self::IN_FLIGHT, count($messages) - $issued));
                    if ($left === 0) {
                        return; // no slot within the wait: the rest go back to the queue below
                    }
                }
                $left--;
                $issued++;
                yield $client->sendEmailAsync($this->request($m, $conn))->then(
                    fn ($r) => $m->forceFill(['status' => 'sent', 'ses_message_id' => (string) $r['MessageId'], 'sent_at' => now(), 'error' => null])->save(),
                    fn ($e) => $this->sesFailed($m, $conn, $e),
                );
            }
        };
        Each::ofLimit($calls(), self::IN_FLIGHT)->wait();
        foreach (array_slice($messages, $issued) as $m) {
            $this->retry($m, 2, 'Waiting for a free slot under your SES send rate.');
        }
    }

    private function sesFailed(Message $m, SesConnection $conn, mixed $e): void
    {
        if ($e instanceof AwsException) {
            $code = (string) $e->getAwsErrorCode();
            if ($code === 'LimitExceededException' && $m->attempts < self::MAX_ATTEMPTS) {
                $this->retry($m, 1800, 'Your SES daily sending quota is used up. Retrying in 30 minutes.');
            } elseif (in_array($code, self::RETRYABLE, true) && $m->attempts < self::MAX_ATTEMPTS) {
                $this->retry($m, min(600, 15 * 2 ** ($m->attempts - 1)), 'AWS asked us to slow down. Retrying.');
            } else {
                $this->finish($m, $code === 'MessageRejected' ? 'rejected' : 'failed', self::explain($code, (string) $e->getAwsErrorMessage(), $conn));
            }

            return;
        }
        report($e instanceof Throwable ? $e : new RuntimeException('SES send failed: '.(is_scalar($e) ? (string) $e : get_debug_type($e))));
        $m->attempts < self::MAX_ATTEMPTS ? $this->retry($m, 60, 'Temporary error. Retrying.') : $this->finish($m, 'failed', 'Couldn’t send after several attempts.');
    }

    /**
     * The workspace's own SMTP server. Its answer is turned into the same events SES would send, so suppression,
     * webhooks, journeys and reports work the same way. SMTP can't confirm delivery: "sent" means the server took it.
     */
    private function viaSmtp(Message $m): void
    {
        $conn = SmtpConnection::where('workspace_id', $m->workspace_id)->first();
        if (! $conn) {
            $this->finish($m, 'failed', 'Your SMTP server isn’t connected any more.');

            return;
        }
        if ($conn->max_per_day && Message::where('workspace_id', $m->workspace_id)->where('sent_at', '>=', now()->startOfDay())->count() >= $conn->max_per_day) {
            $m->attempts < self::MAX_ATTEMPTS + 6
                ? $this->retry($m, 1800, 'Your daily SMTP limit ('.number_format($conn->max_per_day).') is used up. Retrying in 30 minutes.')
                : $this->finish($m, 'failed', 'Your daily SMTP limit was used up for too long.');

            return;
        }
        if (! $this->pacer->waitSmtp($conn)) {
            $this->retry($m, 2, 'Waiting for a free slot under your SMTP send rate.');

            return;
        }

        $email = (new Email)->from(new Address($m->from_email, (string) $m->from_name))->to($m->to_email)->subject($m->subject);
        foreach ($m->reply_to ?: [] as $r) {
            $email->addReplyTo($r);
        }
        if ($m->html !== null) {
            $email->html(Tracking::apply($m, $m->html));
        }
        if ($m->text !== null) {
            $email->text($m->text);
        }
        $h = $email->getHeaders();
        foreach ($m->headers ?? [] as $name => $value) {
            $h->addTextHeader((string) $name, (string) $value);
        }
        if ($m->unsubscribe_token && ! self::isLetter($m)) {
            $h->addTextHeader('List-Unsubscribe', '<'.Mailer::oneClickUrl($m->unsubscribe_token).'>');
            $h->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
        }
        $h->addTextHeader('X-Sendline-Tracking', $m->tracking_code);
        // The relay provider's own metadata header carries our id into its delivery webhooks (Delivery reports).
        foreach (RelayEvents::headers(RelayEvents::guess($conn->host), $m->id) as $name => $value) {
            if (! $h->has($name)) {
                $h->addTextHeader($name, $value);
            }
        }
        if (! self::isLetter($m) && $fid = Compose::feedbackId($m->campaign_id, $m->journey_id, (string) $m->type, $m->workspace_id, $m->headers)) {
            $h->addTextHeader('Feedback-ID', $fid); // a bulk-mail label: letters go without it
        }
        // Our own Message-ID, stored, so a reply's In-Reply-To finds this email (the server's queue id wouldn't).
        $messageId = $m->id.'@'.substr((string) strrchr($m->from_email, '@'), 1);
        $h->addIdHeader('Message-ID', $messageId);

        try {
            $sent = app(SmtpClient::class)->send($conn, $email, new Envelope(new Address($m->from_email), [new Address($m->to_email)]));
        } catch (Throwable $e) {
            $why = SmtpClient::explain($e, $conn);
            match (SmtpClient::kind($e)) {
                'bounce' => $this->smtpEvent($m, 'Bounce', ['bounceType' => 'Permanent', 'bounceSubType' => 'General', 'bouncedRecipients' => [['emailAddress' => $m->to_email, 'diagnosticCode' => $why]]]),
                'rejected' => $this->finish($m, 'rejected', 'Your SMTP server refused this email: '.$why),
                'auth' => $this->smtpAuthFailed($m, $conn, $why),
                default => $m->attempts < self::MAX_ATTEMPTS ? $this->retry($m, min(600, 15 * 2 ** ($m->attempts - 1)), 'Your SMTP server didn’t take it yet ('.$why.'). Retrying.')
                    : $this->finish($m, 'failed', 'Couldn’t send after several attempts: '.$why),
            };

            return;
        }

        // SMTP never reports delivery. Acceptance by the customer's server stands in for it in rates (opens per
        // delivered, and so on), so reports work; the status stays "sent" and no "delivered" event or webhook is made.
        $m->forceFill(['status' => 'sent', 'ses_message_id' => $messageId, 'sent_at' => now(), 'delivered_at' => now(), 'error' => null])->save();
        // The server's own answer ("250 OK id=…"): the last proof of what happened to an SMTP email.
        preg_match_all('/^< (250[ -].*)$/m', (string) $sent?->getDebug(), $replies);
        $this->smtpEvent($m->refresh(), 'Send', array_filter(['via' => 'smtp', 'host' => $conn->host, 'smtpResponse' => $replies[1] ? mb_substr(trim((string) end($replies[1])), 0, 300) : null]));
    }

    /** @param array<string, mixed> $section */
    private function smtpEvent(Message $m, string $type, array $section): void
    {
        app(EventProcessor::class)->apply($m->workspace_id, ['eventType' => $type, 'mail' => ['messageId' => $m->ses_message_id ?? '', 'tags' => ['sl_message' => [$m->id]]],
            lcfirst($type) => $section + ['timestamp' => now()->toIso8601ZuluString()]]);
        if ($type === 'Bounce' && $m->refresh()->status !== 'bounced') {
            $this->finish($m, 'bounced', (string) ($section['bouncedRecipients'][0]['diagnosticCode'] ?? 'Bounced'));
        }
    }

    private function smtpAuthFailed(Message $m, SmtpConnection $conn, string $why): void
    {
        $conn->forceFill(['last_error' => $why, 'last_checked_at' => now()])->save();
        $m->attempts < self::MAX_ATTEMPTS
            ? $this->retry($m, min(1800, 60 * 2 ** ($m->attempts - 1)), $why.' Fix it under Sending; we keep retrying.')
            : $this->finish($m, 'failed', $why);
    }

    /** The tag a campaign's plain-text letters carry (see CampaignService::content). */
    public const LETTER_TAG = 'style';

    /** A letter that counts opens and clicks: the normal events set, so SES adds its tracking. */
    public const TRACKED_LETTER = 'tracked-letter';

    /** A plain-text letter: no unsubscribe header (its opt-out is in the words), no SES tracking, no bulk labels (Feedback-ID). */
    public static function isLetter(Message $m): bool
    {
        return in_array(($m->tags ?? [])[self::LETTER_TAG] ?? null, ['letter', self::TRACKED_LETTER], true);
    }

    /** @return array<string, mixed> */
    private function request(Message $m, SesConnection $conn): array
    {
        $headers = [];
        foreach ($m->headers ?? [] as $name => $value) {
            $headers[] = ['Name' => (string) $name, 'Value' => (string) $value];
        }
        if ($m->unsubscribe_token && ! self::isLetter($m)) {
            // RFC 8058 one-click unsubscribe, required by Gmail and Yahoo for bulk mail.
            $headers[] = ['Name' => 'List-Unsubscribe', 'Value' => '<'.Mailer::oneClickUrl($m->unsubscribe_token).'>'];
            $headers[] = ['Name' => 'List-Unsubscribe-Post', 'Value' => 'List-Unsubscribe=One-Click'];
        }
        // A letter goes without our labels: SES reports its bounces and complaints by message id anyway.
        if (! self::isLetter($m)) {
            $headers[] = ['Name' => 'X-Sendline-Tracking', 'Value' => $m->tracking_code];
        }
        if (! self::isLetter($m) && $fid = Compose::feedbackId($m->campaign_id, $m->journey_id, (string) $m->type, $m->workspace_id, $m->headers)) {
            $headers[] = ['Name' => 'Feedback-ID', 'Value' => $fid];
        }

        // With the workspace's own tracking domain, MailVesper tracks (on that domain) and SES doesn't.
        $own = $m->html !== null && Tracking::ownForSes($m->workspace_id);
        $body = [];
        if ($m->html !== null) {
            $body['Html'] = ['Data' => $own ? Tracking::apply($m, $m->html) : $m->html, 'Charset' => 'UTF-8'];
        }
        if ($m->text !== null) {
            $body['Text'] = ['Data' => $m->text, 'Charset' => 'UTF-8'];
        }
        $tags = [['Name' => 'sl_message', 'Value' => $m->id], ['Name' => 'sl_workspace', 'Value' => $m->workspace_id]];
        if ($m->campaign_id) {
            $tags[] = ['Name' => 'sl_campaign', 'Value' => $m->campaign_id];
        }
        foreach ($m->tags ?? [] as $k => $v) {
            $tags[] = ['Name' => (string) $k, 'Value' => (string) $v];
        }

        return array_filter([
            'FromEmailAddress' => Compose::formatAddress($m->from_email, $m->from_name),
            'Destination' => ['ToAddresses' => [$m->to_email]],
            'ReplyToAddresses' => $m->reply_to ?: null,
            'Content' => ['Simple' => ['Subject' => ['Data' => $m->subject, 'Charset' => 'UTF-8'], 'Body' => $body, 'Headers' => $headers]],
            'ConfigurationSetName' => $own || (self::isLetter($m) && ($m->tags ?? [])[self::LETTER_TAG] !== self::TRACKED_LETTER) ? SesConnection::CONFIG_SET_OWN_TRACKING : ($conn->configuration_set ?: SesConnection::CONFIG_SET),
            'EmailTags' => $tags,
        ], fn ($v) => $v !== null);
    }

    private function retry(Message $m, int $seconds, string $why): void
    {
        $m->forceFill(['status' => 'queued', 'error' => $why])->save();
        app(Timeline::class)->record($m->workspace_id, $m, 'retrying', ['reason' => $why, 'attempt' => $m->attempts, 'next_attempt_at' => now()->addSeconds($seconds)->toIso8601ZuluString()]);
        SendMessage::dispatch($m->id)->onConnection('database')->delay(now()->addSeconds($seconds));
    }

    private function finish(Message $m, string $status, string $error): void
    {
        $m->forceFill(['status' => $status, 'error' => mb_substr($error, 0, 500)])->save();
        app(Timeline::class)->record($m->workspace_id, $m, $status, ['reason' => $error]);
    }

    public static function explain(string $code, string $awsMessage, SesConnection $conn): string
    {
        return match (true) {
            $code === 'MessageRejected' && str_contains($awsMessage, 'not verified') && ! $conn->production_access => 'Your SES account is in the sandbox, so it can only send to verified addresses. Request production access on the Amazon SES page.',
            $code === 'MessageRejected' => 'SES rejected this email: '.$awsMessage,
            $code === 'MailFromDomainNotVerifiedException' => 'The sending domain’s MAIL FROM setup isn’t verified in SES yet.',
            $code === 'AccountSuspendedException' => 'AWS has paused sending for your SES account. Check the Amazon SES page.',
            $code === 'SendingPausedException' => 'Sending is paused for this SES account or configuration set.',
            $code === 'NotFoundException' => 'The sendline-events configuration set is missing in SES. Run setup again on the Amazon SES page.',
            in_array($code, ['AccessDenied', 'AccessDeniedException'], true) => Brand::name().'’s AWS user isn’t allowed to send email. Check its policy in IAM.',
            default => 'SES error'.($code ? " ($code)" : '').': '.$awsMessage,
        };
    }

    /**
     * A campaign email whose campaign was paused or cancelled after it was queued is taken back instead of sent:
     * the row is removed and its recipient waits again (paused) or is closed (cancelled). Pause then holds within
     * one email, even for messages queued by a batch that was mid-flight when the pause landed.
     */
    private function withdrawIfHeld(string $id): bool
    {
        $m = DB::table('messages')->where('id', $id)->first(['campaign_id', 'status', 'attempts']);
        if (! $m || ! $m->campaign_id || $m->status !== 'queued') {
            return false;
        }
        $status = DB::table('campaigns')->where('id', $m->campaign_id)->value('status');
        if (! in_array($status, ['paused', 'cancelled'], true)) {
            return false;
        }

        return DB::transaction(function () use ($id, $m, $status) {
            if (! DB::table('messages')->where('id', $id)->where('status', 'queued')->delete()) {
                return false; // claimed by another worker a moment ago
            }
            DB::table('message_events')->where('message_id', $id)->delete();
            DB::table('campaign_recipients')->where('campaign_id', $m->campaign_id)->where('message_id', $id)
                ->update(['status' => $status === 'paused' ? 'pending' : 'cancelled', 'message_id' => null]);

            return true;
        });
    }
}
