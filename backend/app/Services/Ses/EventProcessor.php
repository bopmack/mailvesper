<?php

namespace App\Services\Ses;

use App\Models\Contact;
use App\Models\Message;
use App\Models\Suppression;
use App\Services\Activity\Signals;
use App\Services\Activity\Timeline;
use App\Services\Journeys\JourneyEvents;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Applies one SES event: records it on the message's timeline, moves the message forward,
 * counts real vs machine opens and clicks, and suppresses hard bounces and complaints.
 */
class EventProcessor
{
    public function __construct(private Timeline $timeline) {}

    /** @param array<string, mixed> $event */
    public function apply(string $workspaceId, array $event, ?int $sesEventId = null): void
    {
        $type = (string) ($event['eventType'] ?? $event['notificationType'] ?? '');
        $mail = (array) ($event['mail'] ?? []);
        $message = $this->findMessage($workspaceId, $mail);
        $section = (array) ($event[lcfirst($type === 'DeliveryDelay' ? 'deliveryDelay' : ($type === 'RenderingFailure' ? 'failure' : $type))] ?? []);
        $at = $this->time($section['timestamp'] ?? $mail['timestamp'] ?? null);

        $record = function (string $kind, array $data = [], bool $machine = false) use ($workspaceId, $message, $at, $sesEventId) {
            if ($message) {
                $this->timeline->record($workspaceId, $message, $kind, array_filter($data, fn ($v) => $v !== null && $v !== ''), $machine, $at, $sesEventId);
            }
        };

        $reason = null;
        $bot = false;
        switch ($type) {
            case 'Send':
                if ($message) {
                    $message->moveTo('sent');
                    $message->sent_at ??= $at;
                }
                $record('sent', array_filter(['via' => $section['via'] ?? null, 'host' => $section['host'] ?? null, 'smtp_response' => $section['smtpResponse'] ?? null]));
                break;

            case 'Delivery':
                if ($message) {
                    if ($message->moveTo('delivered') || ! $message->delivered_at) {
                        $message->delivered_at = $at;
                    }
                    $message->provider = Signals::providerFromDelivery((string) ($section['smtpResponse'] ?? ''), $message->to_email);
                }
                $record('delivered', ['smtp_response' => $section['smtpResponse'] ?? null, 'remote_mta_ip' => $section['remoteMtaIp'] ?? null, 'processing_ms' => $section['processingTimeMillis'] ?? null]);
                break;

            case 'DeliveryDelay':
                $message?->moveTo('delayed');
                $r = $section['delayedRecipients'][0] ?? [];
                $record('delayed', ['delay_type' => $section['delayType'] ?? null, 'diagnostic' => $r['diagnosticCode'] ?? null, 'retrying_until' => $section['expirationTime'] ?? null]);
                break;

            case 'Reject':
                $message?->moveTo('rejected');
                $record('rejected', ['reason' => $section['reason'] ?? null]);
                break;

            case 'Bounce':
                $hard = ($section['bounceType'] ?? '') === 'Permanent';
                $first = $section['bouncedRecipients'][0] ?? [];
                $message?->moveTo('bounced'); // SES only reports a soft bounce once it has stopped retrying
                if ($hard) {
                    foreach ((array) ($section['bouncedRecipients'] ?? []) as $r) {
                        if (! empty($r['emailAddress'])) {
                            Suppression::add($workspaceId, (string) $r['emailAddress'], 'hard_bounce', trim(($section['bounceSubType'] ?? '').' '.($r['diagnosticCode'] ?? '')), $message?->id);
                        }
                    }
                }
                $record('bounced', ['bounce_type' => $hard ? 'hard' : 'soft', 'subtype' => $section['bounceSubType'] ?? null, 'diagnostic' => $first['diagnosticCode'] ?? null, 'suppressed' => $hard]);
                break;

            case 'Complaint':
                $message?->moveTo('complained');
                foreach ((array) ($section['complainedRecipients'] ?? []) as $r) {
                    if (! empty($r['emailAddress'])) {
                        Suppression::add($workspaceId, (string) $r['emailAddress'], 'complaint', (string) ($section['complaintFeedbackType'] ?? 'Marked as spam'), $message?->id);
                    }
                }
                $record('complained', ['feedback_type' => $section['complaintFeedbackType'] ?? null, 'reported_by' => $section['userAgent'] ?? null, 'via' => $section['via'] ?? null]);
                break;

            case 'Open':
                $ua = (string) ($section['userAgent'] ?? '');
                $reason = Signals::machineOpen($ua, $this->secondsAfterDelivery($message, $at), isset($section['ipAddress']) ? (string) $section['ipAddress'] : null);
                if ($message) {
                    if ($reason) {
                        $message->machine_open_count++;
                    } else {
                        $message->open_count++;
                        $message->opened_at ??= $at;
                    }
                }
                $record('opened', ['user_agent' => $ua, 'ip' => $section['ipAddress'] ?? null, 'machine_reason' => $reason], (bool) $reason);
                break;

            case 'Click':
                $ua = (string) ($section['userAgent'] ?? '');
                $sameSecond = $message ? DB::table('message_events')->where('message_id', $message->id)->where('type', 'clicked')
                    ->whereBetween('occurred_at', [$at->copy()->subSecond()->toDateTimeString(), $at->copy()->addSecond()->toDateTimeString()])->count() : 0;
                $bot = Signals::botClick($ua, $this->secondsAfterDelivery($message, $at), $sameSecond);
                if ($message && ! $bot) {
                    $message->click_count++;
                    $message->clicked_at ??= $at;
                    $message->opened_at ??= $at; // a real click means it was opened, even if images were blocked
                }
                $record('clicked', ['url' => $section['link'] ?? null, 'user_agent' => $ua, 'ip' => $section['ipAddress'] ?? null], $bot);
                break;

            case 'RenderingFailure':
                $record('rendering_failed', ['error' => $section['errorMessage'] ?? null, 'template' => $section['templateName'] ?? null]);
                break;
        }

        if ($message) {
            $message->last_event_at = now();
            $message->save();
            $this->touchContact($workspaceId, $message->to_email, $type, $at, $message);
            $human = ($type === 'Open' && ! $reason) || ($type === 'Click' && ! $bot);
            if ($human && ($contact = Contact::where('workspace_id', $workspaceId)->where('email', mb_strtolower($message->to_email))->first())) {
                // Real opens and clicks only: Apple's machine opens and link scanners never move a journey.
                app(JourneyEvents::class)->fire($contact, $type === 'Open' ? 'email.opened' : 'email.clicked', array_filter([
                    'message_id' => $message->id, 'campaign_id' => $message->campaign_id, 'journey_id' => $message->journey_id, 'journey_node' => $message->journey_node,
                    'url' => $type === 'Click' ? ($section['link'] ?? null) : null, 'subject' => $message->subject,
                ], fn ($v) => $v !== null), 'email', $at);
            }
        }
    }

    /** Keeps the contact's last sent / real open / real click dates current for engagement and segments. */
    private function touchContact(string $workspaceId, string $email, string $type, Carbon $at, Message $m): void
    {
        $col = match (true) {
            $type === 'Send' => 'last_sent_at',
            $type === 'Open' && $m->opened_at !== null && $m->opened_at->equalTo($at) => 'last_opened_at',
            $type === 'Click' && $m->clicked_at !== null && $m->clicked_at->equalTo($at) => 'last_clicked_at',
            default => null,
        };
        if ($col) {
            DB::table('contacts')->where('workspace_id', $workspaceId)->where('email', mb_strtolower($email))
                ->where(fn ($q) => $q->whereNull($col)->orWhere($col, '<', $at->toDateTimeString()))->update([$col => $at->toDateTimeString()]);
        }
    }

    /** @param array<string, mixed> $mail */
    private function findMessage(string $workspaceId, array $mail): ?Message
    {
        $sesId = (string) ($mail['messageId'] ?? '');
        $message = $sesId !== '' ? Message::where('workspace_id', $workspaceId)->where('ses_message_id', $sesId)->first() : null;
        if (! $message) {
            // The Send event can beat our own "sent" write; every email carries its id as an SES tag.
            $tagged = $mail['tags']['sl_message'][0] ?? null;
            $message = is_string($tagged) ? Message::where('workspace_id', $workspaceId)->find($tagged) : null;
            if ($message && ! $message->ses_message_id && $sesId !== '') {
                $message->ses_message_id = $sesId;
            }
        }

        return $message;
    }

    private function secondsAfterDelivery(?Message $m, Carbon $at): ?int
    {
        $from = $m ? ($m->delivered_at ?? $m->sent_at) : null;

        return $from ? max(0, $at->getTimestamp() - $from->getTimestamp()) : null;
    }

    private function time(mixed $value): Carbon
    {
        try {
            return $value ? Carbon::parse((string) $value)->utc() : now()->utc();
        } catch (Throwable) {
            return now()->utc();
        }
    }
}
