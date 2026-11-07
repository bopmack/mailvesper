<?php

namespace App\Services\Smtp;

use App\Models\DeliveryHook;
use App\Models\Message;
use App\Services\Ses\EventProcessor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Delivery reports from an SMTP relay provider's event webhook. SMTP itself only says "accepted"; the provider knows
 * what happened next. Each provider's events become the SES events MailVesper already understands (Delivery,
 * DeliveryDelay, Bounce, Complaint, Reject), so suppression, journeys, webhooks and reports work as with SES.
 * Opens, clicks and unsubscribes are ignored: MailVesper tracks those itself.
 *
 * Every email over a known relay carries the provider's own per-message metadata header with our message id; it
 * comes back in each event. Fallbacks: the Message-ID (when the provider keeps ours), then the recipient's latest
 * email. No provider reports Gmail complaints one by one.
 */
class RelayEvents
{
    public const PROVIDERS = ['sendgrid' => 'SendGrid', 'mailgun' => 'Mailgun', 'postmark' => 'Postmark', 'brevo' => 'Brevo', 'sparkpost' => 'SparkPost'];

    /** Providers that sign their webhooks (the rest rely on the secret URL). */
    public const SIGNED = ['sendgrid', 'mailgun'];

    /** Recent emails the recipient fallback looks at. */
    public const MATCH_DAYS = 30;

    /** SparkPost bounce classes that are permanent (hard, admin, unsubscribe). */
    private const SPARKPOST_HARD = [10, 25, 30, 80, 90];

    private const POSTMARK_HARD = ['HardBounce', 'BadEmailAddress', 'ManuallyDeactivated'];

    private const POSTMARK_SOFT = ['SoftBounce', 'DnsError', 'Blocked', 'Undeliverable', 'ContentRelated', 'SMTPApiError'];

    /** The SES event type each kind is recorded as. */
    private const RECORDED = ['Delivery' => 'delivered', 'DeliveryDelay' => 'delayed', 'Bounce' => 'bounced', 'Complaint' => 'complained', 'Reject' => 'rejected'];

    public function __construct(private EventProcessor $events) {}

    /** The relay provider behind an SMTP host, if it's one we read webhooks from. */
    public static function guess(?string $host): ?string
    {
        $h = strtolower((string) $host);

        return match (true) {
            str_ends_with($h, 'sendgrid.net') => 'sendgrid',
            str_ends_with($h, 'mailgun.org') => 'mailgun',
            str_ends_with($h, 'postmarkapp.com') => 'postmark',
            str_ends_with($h, 'brevo.com'), str_ends_with($h, 'sendinblue.com') => 'brevo',
            str_ends_with($h, 'sparkpostmail.com') => 'sparkpost',
            default => null,
        };
    }

    /**
     * Headers that carry our message id through the provider into its events.
     *
     * @return array<string, string>
     */
    public static function headers(?string $provider, string $messageId): array
    {
        return match ($provider) {
            'sendgrid' => ['X-SMTPAPI' => json_encode(['unique_args' => ['sl_message' => $messageId]], JSON_THROW_ON_ERROR)],
            'mailgun' => ['X-Mailgun-Variables' => json_encode(['sl_message' => $messageId], JSON_THROW_ON_ERROR)],
            'postmark' => ['X-PM-Metadata-sl_message' => $messageId, 'X-PM-KeepID' => 'true'], // KeepID: replies still find the email
            'brevo' => ['X-Mailin-custom' => 'sl_message:'.$messageId],
            'sparkpost' => ['X-MSYS-API' => json_encode(['metadata' => ['sl_message' => $messageId]], JSON_THROW_ON_ERROR)],
            default => [],
        };
    }

    /**
     * Whether the request is signed by the provider with the hook's key. Hooks without a key, and providers that
     * don't sign, rely on the secret URL alone.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function verify(DeliveryHook $hook, string $body, array $payload, ?string $sgSignature, ?string $sgTimestamp): bool
    {
        $key = (string) $hook->signing_key;
        if ($key === '' || ! in_array($hook->provider, self::SIGNED, true)) {
            return true;
        }
        if ($hook->provider === 'mailgun') {
            $s = (array) ($payload['signature'] ?? []);

            return isset($s['timestamp'], $s['token'], $s['signature'])
                && hash_equals(hash_hmac('sha256', $s['timestamp'].$s['token'], $key), (string) $s['signature']);
        }
        // SendGrid: ECDSA (P-256, SHA-256) over timestamp + raw body; the key is the base64 public key it shows.
        if (! $sgSignature || ! $sgTimestamp || ($sig = base64_decode($sgSignature, true)) === false) {
            return false;
        }
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split((string) preg_replace('/\s+|-----[A-Z ]+-----/', '', $key), 64, "\n").'-----END PUBLIC KEY-----';
        $pub = @openssl_pkey_get_public($pem);

        return $pub !== false && openssl_verify($sgTimestamp.$body, $sig, $pub, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * The provider's payload as SES-style events.
     *
     * @param  array<mixed>  $payload
     * @return list<array{type: string, ref: string|null, message_id: string|null, email: string|null, at: Carbon, section: array<string, mixed>}>
     */
    public static function parse(string $provider, array $payload): array
    {
        $out = [];
        foreach (self::items($provider, $payload) as $e) {
            if ($ev = self::one($provider, $e)) {
                $out[] = $ev;
            }
        }

        return $out;
    }

    /**
     * Applies the payload to the hook's workspace. Repeats (providers retry) are recorded once.
     *
     * @param  array<mixed>  $payload
     * @return int events applied
     */
    public function apply(DeliveryHook $hook, array $payload): int
    {
        $n = 0;
        foreach (self::parse($hook->provider, $payload) as $ev) {
            $m = $this->find($hook->workspace_id, $ev);
            if (! $m || $this->seen($m, $ev)) {
                continue;
            }
            $section = $ev['section'] + ['timestamp' => $ev['at']->toIso8601ZuluString()];
            $email = $ev['email'] ?: $m->to_email;
            $section += match ($ev['type']) {
                'Bounce' => ['bouncedRecipients' => [['emailAddress' => $email, 'diagnosticCode' => $section['diagnostic'] ?? null]]],
                'Complaint' => ['complainedRecipients' => [['emailAddress' => $email]], 'via' => self::PROVIDERS[$hook->provider]],
                'DeliveryDelay' => ['delayedRecipients' => [['emailAddress' => $email, 'diagnosticCode' => $section['diagnostic'] ?? null]]],
                default => [],
            };
            unset($section['diagnostic']);
            $this->events->apply($hook->workspace_id, ['eventType' => $ev['type'], 'mail' => ['messageId' => $m->ses_message_id ?? '', 'tags' => ['sl_message' => [$m->id]]],
                lcfirst($ev['type']) => $section]);
            $n++;
        }

        return $n;
    }

    /**
     * @param  array<mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private static function items(string $provider, array $payload): array
    {
        return match ($provider) {
            'sendgrid' => array_values(array_filter($payload, 'is_array')),
            'mailgun' => is_array($payload['event-data'] ?? null) ? [$payload['event-data']] : [],
            'sparkpost' => array_values(array_filter(array_map(function ($row) {
                $msys = is_array($row) ? (array) ($row['msys'] ?? []) : [];

                return is_array($msys['message_event'] ?? null) ? $msys['message_event'] : null;
            }, array_is_list($payload) ? $payload : [$payload]))),
            default => [$payload], // postmark, brevo: one event per request
        };
    }

    /**
     * @param  array<string, mixed>  $e
     * @return array{type: string, ref: string|null, message_id: string|null, email: string|null, at: Carbon, section: array<string, mixed>}|null
     */
    private static function one(string $provider, array $e): ?array
    {
        $str = fn ($v) => is_scalar($v) && (string) $v !== '' ? mb_substr(trim((string) $v), 0, 500) : null;
        [$type, $section, $ref, $mid, $email, $at] = [null, [], null, null, null, null];
        switch ($provider) {
            case 'sendgrid':
                $ref = $str($e['sl_message'] ?? null);
                $mid = $str($e['smtp-id'] ?? null);
                $email = $str($e['email'] ?? null);
                $at = $e['timestamp'] ?? null;
                $why = $str($e['reason'] ?? $e['response'] ?? null);
                [$type, $section] = match ($e['event'] ?? null) {
                    'delivered' => ['Delivery', ['smtpResponse' => $str($e['response'] ?? null)]],
                    'deferred' => ['DeliveryDelay', ['delayType' => 'General', 'diagnostic' => $why]],
                    'bounce' => ['Bounce', ['bounceType' => ($e['type'] ?? '') === 'blocked' ? 'Transient' : 'Permanent', 'bounceSubType' => 'General', 'diagnostic' => trim(($str($e['status'] ?? null) ?? '').' '.($why ?? '')) ?: null]],
                    'dropped' => ['Reject', ['reason' => 'SendGrid didn’t send it: '.($why ?? 'dropped')]],
                    'spamreport' => ['Complaint', ['complaintFeedbackType' => 'abuse']],
                    default => [null, []],
                };
                break;

            case 'mailgun':
                $vars = (array) ($e['user-variables'] ?? []);
                $ref = $str($vars['sl_message'] ?? null);
                $mid = $str($e['message']['headers']['message-id'] ?? null);
                $email = $str($e['recipient'] ?? null);
                $at = $e['timestamp'] ?? null;
                $ds = (array) ($e['delivery-status'] ?? []);
                $why = trim(($str($ds['code'] ?? null) ?? '').' '.($str($ds['message'] ?? null) ?? $str($ds['description'] ?? null) ?? '')) ?: $str($e['reason'] ?? null);
                $reason = (string) ($e['reason'] ?? '');
                [$type, $section] = match (true) {
                    ($e['event'] ?? null) === 'delivered' => ['Delivery', ['smtpResponse' => $why]],
                    ($e['event'] ?? null) === 'complained' => ['Complaint', ['complaintFeedbackType' => 'abuse']],
                    ($e['event'] ?? null) === 'failed' && str_starts_with($reason, 'suppress') => ['Reject', ['reason' => 'Mailgun didn’t send it: '.str_replace('-', ' ', $reason)]],
                    ($e['event'] ?? null) === 'failed' && ($e['severity'] ?? null) === 'permanent' => ['Bounce', ['bounceType' => 'Permanent', 'bounceSubType' => 'General', 'diagnostic' => $why]],
                    ($e['event'] ?? null) === 'failed' => ['DeliveryDelay', ['delayType' => 'General', 'diagnostic' => $why]],
                    default => [null, []],
                };
                break;

            case 'postmark':
                $ref = $str($e['Metadata']['sl_message'] ?? null);
                $email = $str($e['Recipient'] ?? $e['Email'] ?? null);
                $at = $e['DeliveredAt'] ?? $e['BouncedAt'] ?? $e['ReceivedAt'] ?? null;
                $bt = (string) ($e['Type'] ?? '');
                $why = trim(($str($e['Description'] ?? null) ?? '').' '.($str($e['Details'] ?? null) ?? '')) ?: null;
                [$type, $section] = match (true) {
                    ($e['RecordType'] ?? null) === 'Delivery' => ['Delivery', ['smtpResponse' => $str($e['Details'] ?? null)]],
                    ($e['RecordType'] ?? null) === 'SpamComplaint' || $bt === 'SpamComplaint' => ['Complaint', ['complaintFeedbackType' => 'abuse']],
                    ($e['RecordType'] ?? null) !== 'Bounce' => [null, []],
                    in_array($bt, self::POSTMARK_HARD, true) || ($e['Inactive'] ?? false) === true => ['Bounce', ['bounceType' => 'Permanent', 'bounceSubType' => 'General', 'diagnostic' => $why]],
                    $bt === 'Transient' => ['DeliveryDelay', ['delayType' => 'General', 'diagnostic' => $why]],
                    in_array($bt, self::POSTMARK_SOFT, true) => ['Bounce', ['bounceType' => 'Transient', 'bounceSubType' => 'General', 'diagnostic' => $why]],
                    default => [null, []], // auto-responders, subscribe/unsubscribe notices, …
                };
                break;

            case 'brevo':
                $ref = preg_match('/(?:^|\|)sl_message:([0-9A-Za-z]{26})(?:\||$)/', (string) ($e['X-Mailin-custom'] ?? ''), $x) ? $x[1] : null;
                $mid = $str($e['message-id'] ?? null);
                $email = $str($e['email'] ?? null);
                $at = $e['ts_event'] ?? $e['ts'] ?? null;
                $why = $str($e['reason'] ?? null);
                [$type, $section] = match ($e['event'] ?? null) {
                    'delivered' => ['Delivery', []],
                    'deferred' => ['DeliveryDelay', ['delayType' => 'General', 'diagnostic' => $why]],
                    'hard_bounce', 'invalid_email' => ['Bounce', ['bounceType' => 'Permanent', 'bounceSubType' => 'General', 'diagnostic' => $why]],
                    'soft_bounce' => ['Bounce', ['bounceType' => 'Transient', 'bounceSubType' => 'General', 'diagnostic' => $why]],
                    'blocked', 'error' => ['Reject', ['reason' => 'Brevo didn’t send it: '.($why ?? (string) $e['event'])]],
                    'spam' => ['Complaint', ['complaintFeedbackType' => 'abuse']],
                    default => [null, []],
                };
                break;

            case 'sparkpost':
                $meta = (array) ($e['rcpt_meta'] ?? []) + (array) ($e['metadata'] ?? []);
                $ref = $str($meta['sl_message'] ?? null);
                $email = $str($e['rcpt_to'] ?? $e['raw_rcpt_to'] ?? null);
                $at = $e['timestamp'] ?? null;
                $why = trim(($str($e['error_code'] ?? null) ?? '').' '.($str($e['raw_reason'] ?? $e['reason'] ?? null) ?? '')) ?: null;
                [$type, $section] = match ($e['type'] ?? null) {
                    'delivery' => ['Delivery', ['smtpResponse' => $str($e['raw_reason'] ?? null)]],
                    'delay' => ['DeliveryDelay', ['delayType' => 'General', 'diagnostic' => $why]],
                    'bounce', 'out_of_band' => ['Bounce', ['bounceType' => in_array((int) ($e['bounce_class'] ?? 0), self::SPARKPOST_HARD, true) ? 'Permanent' : 'Transient', 'bounceSubType' => 'General', 'diagnostic' => $why]],
                    'policy_rejection' => ['Reject', ['reason' => 'SparkPost didn’t send it: '.($why ?? 'policy rejection')]],
                    'spam_complaint' => ['Complaint', ['complaintFeedbackType' => $str($e['fbtype'] ?? null) ?? 'abuse']],
                    default => [null, []],
                };
                break;
        }
        if (! $type) {
            return null;
        }

        return ['type' => $type, 'ref' => $ref && preg_match('/^[0-9A-Za-z]{26}$/', $ref) ? $ref : null, 'message_id' => $mid ? trim($mid, '<> ') : null,
            'email' => $email ? mb_strtolower($email) : null, 'at' => self::time($at), 'section' => array_filter($section, fn ($v) => $v !== null && $v !== '')];
    }

    /** @param array{ref: string|null, message_id: string|null, email: string|null} $ev */
    private function find(string $workspaceId, array $ev): ?Message
    {
        $q = fn () => Message::where('workspace_id', $workspaceId);
        if ($ev['ref'] && ($m = $q()->find($ev['ref']))) {
            return $m;
        }
        if ($ev['message_id'] && ($m = $q()->where('ses_message_id', $ev['message_id'])->first())) {
            return $m;
        }

        return $ev['email'] ? $q()->where('to_email', $ev['email'])->whereNotNull('sent_at')->where('sent_at', '>', now()->subDays(self::MATCH_DAYS))->orderByDesc('sent_at')->first() : null;
    }

    /**
     * A delivery, bounce, complaint or refusal happens once per email; a delay can repeat (once per moment).
     *
     * @param  array{type: string, at: Carbon}  $ev
     */
    private function seen(Message $m, array $ev): bool
    {
        $q = DB::table('message_events')->where('message_id', $m->id)->where('type', self::RECORDED[$ev['type']]);
        if ($ev['type'] === 'DeliveryDelay') {
            $q->where('occurred_at', $ev['at']->toDateTimeString());
        }

        return $q->exists();
    }

    private static function time(mixed $v): Carbon
    {
        try {
            return match (true) {
                is_int($v), is_float($v), is_string($v) && is_numeric($v) => Carbon::createFromTimestamp((int) ((float) $v > 1e11 ? (float) $v / 1000 : (float) $v), 'UTC'),
                is_string($v) && $v !== '' => Carbon::parse($v)->utc(),
                default => now()->utc(),
            };
        } catch (Throwable) {
            return now()->utc();
        }
    }
}
