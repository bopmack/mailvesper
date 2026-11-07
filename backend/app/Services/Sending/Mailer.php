<?php

namespace App\Services\Sending;

use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\Sending\WebViewController;
use App\Jobs\SendMessage;
use App\Jobs\SendMessages;
use App\Models\ApiKey;
use App\Models\Contact;
use App\Models\Domain;
use App\Models\EmailTemplate;
use App\Models\Message;
use App\Models\Suppression;
use App\Models\Topic;
use App\Models\Workspace;
use App\Services\Activity\Signals;
use App\Services\Activity\Timeline;
use App\Services\Contacts\ContactService;
use App\Support\Edition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** Turns a send request into one message per recipient, skips suppressed addresses, and queues the rest. */
class Mailer
{
    public function __construct(private Timeline $timeline) {}

    public const MAX_RECIPIENTS = 50;

    /** Up to this many messages go out right after the HTTP response; more go through the queue. */
    public const INLINE_LIMIT = 10;

    /**
     * Validates one email payload. Throws ApiException(422) with field errors.
     *
     * @param  array<string, mixed>  $p
     * @return array<string, mixed> normalised payload
     */
    public function validate(Workspace $workspace, array $p, string $prefix = ''): array
    {
        $v = Validator::make($p, [
            'from' => ['required', 'string', 'max:400'],
            'to' => ['required'],
            'subject' => [empty($p['template']) ? 'required' : 'nullable', 'string', 'max:998'],
            'html' => ['nullable', 'string', 'max:2000000'],
            'text' => ['nullable', 'string', 'max:1000000'],
            'reply_to' => ['nullable'],
            'variables' => ['nullable', 'array', 'max:100'],
            'tags' => ['nullable', 'array', 'max:10'],
            'headers' => ['nullable', 'array'],
            'type' => ['nullable', 'in:transactional,marketing'],
            'unsubscribe' => ['nullable', 'boolean'],
            'topic' => ['nullable', 'string', 'size:26'],
            'template' => ['nullable', 'string', 'size:26'],
        ]);
        $errors = $v->fails() ? array_map(fn ($m) => $m[0], $v->errors()->toArray()) : [];

        $from = is_string($p['from'] ?? null) ? Compose::parseAddress($p['from']) : null;
        if (! isset($errors['from']) && ! $from) {
            $errors['from'] = 'Use an address like news@yourdomain.com or "Acme <news@yourdomain.com>".';
        }
        $to = $this->recipients($p['to'] ?? null, $errors, 'to', self::MAX_RECIPIENTS);
        $replyTo = isset($p['reply_to']) ? $this->recipients($p['reply_to'], $errors, 'reply_to', 5) : [];
        $template = null;
        if (! empty($p['template']) && is_string($p['template'])) {
            $template = EmailTemplate::where('workspace_id', $workspace->id)->find($p['template']);
            if (! $template) {
                $errors['template'] = 'That template doesn’t exist in this workspace.';
            } elseif (! empty($p['html'])) {
                $errors['html'] = 'Send either a template or html, not both.';
            }
        }
        if (! $template && empty($p['html']) && empty($p['text']) && ! isset($errors['html']) && ! isset($errors['template'])) {
            $errors['html'] = 'Send html, text, or both.';
        }
        if (is_array($p['headers'] ?? null) && ($e = Compose::headerError($p['headers']))) {
            $errors['headers'] = $e;
        }
        foreach ((array) ($p['tags'] ?? []) as $k => $val) {
            if (! is_string($k) || ! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $k) || ! is_scalar($val) || ! preg_match('/^[A-Za-z0-9_.-]{1,200}$/', (string) $val)) {
                $errors['tags'] = 'Tag names and values may use letters, digits, _ - and . only.';
                break;
            }
        }
        foreach ((array) ($p['variables'] ?? []) as $val) {
            if (! is_null($val) && ! is_scalar($val)) {
                $errors['variables'] = 'Variables must be text or numbers.';
                break;
            }
        }
        if ($errors) {
            $fields = [];
            foreach ($errors as $k => $msg) {
                $fields[$prefix.$k] = $msg;
            }
            throw new ApiException('validation_failed', reset($errors), 422, $fields);
        }

        $topicId = null;
        if (! empty($p['topic'])) {
            $topicId = Topic::where('workspace_id', $workspace->id)->where('id', $p['topic'])->value('id')
                ?? throw new ApiException('validation_failed', 'That topic doesn’t exist in this workspace.', 422, [$prefix.'topic' => 'Unknown topic.']);
        }
        /** @var array{0: string, 1: string|null} $from */
        $this->assertSenderDomain($workspace, $from[0], $prefix);
        $type = $p['type'] ?? 'transactional';

        $subject = trim((string) ($p['subject'] ?? ''));
        if ($template) {
            $template->forceFill(['last_used_at' => now()])->saveQuietly();
            $subject = $subject !== '' ? $subject : ($template->subject ?: $template->name);
        }

        return [
            'from_email' => $from[0], 'from_name' => $from[1], 'to' => $to, 'reply_to' => $replyTo,
            'subject' => $subject,
            'html' => $template ? $template->html : ($p['html'] ?? null), 'text' => $p['text'] ?? null, 'variables' => (array) ($p['variables'] ?? []),
            'tags' => (array) ($p['tags'] ?? []), 'headers' => (array) ($p['headers'] ?? []), 'type' => $type,
            'unsubscribe' => (bool) ($p['unsubscribe'] ?? ($type === 'marketing' || $topicId !== null)), 'topic_id' => $topicId,
        ];
    }

    /**
     * Creates the messages for validated payloads (all or nothing) and queues them.
     *
     * @param  list<array<string, mixed>>  $payloads  from validate()
     * @return list<Message>
     */
    public function submit(Workspace $workspace, array $payloads, string $source, ?ApiKey $key = null): array
    {
        if ($workspace->suspended_at) {
            throw new ApiException('workspace_suspended', 'Sending is paused for this workspace by the platform'.($workspace->suspended_reason ? ': '.$workspace->suspended_reason : '').'. Contact the platform’s support.', 403);
        }
        if (Senders::method($workspace) === null) {
            throw new ApiException('ses_not_connected', 'Connect Amazon SES or your own SMTP server before sending.', 422);
        }
        $batch = ! isset($payloads[0]['campaign_id']) && ! isset($payloads[0]['journey_id']) && (count($payloads) > 1 || count($payloads[0]['to'] ?? []) > 1) ? (string) Str::ulid() : null;
        $all = array_merge(...array_map(fn ($p) => $p['to'], $payloads));
        $suppressed = Suppression::check($workspace->id, $all);
        // Contacts' fields fill merge tags automatically; a topic opt-out skips the recipient.
        $contacts = Contact::where('workspace_id', $workspace->id)->whereIn('email', array_map('mb_strtolower', $all))->get()->keyBy('email');
        $optOuts = $this->topicOptOuts($workspace->id, $payloads, $contacts->pluck('id', 'email')->all());
        // Why a recipient is skipped, if they are (see Suppression::blocks: unsubscribes don't stop transactional email).
        $skip = function (array $p, string $lower) use ($suppressed, $optOuts): ?string {
            $reason = $suppressed[$lower] ?? null;
            if (! Suppression::blocks($reason, $p['type'] ?? 'transactional', ! empty($p['topic_id']))) {
                $reason = null;
            }

            return $reason ?? (isset($optOuts[$p['topic_id'] ?? ''][$lower]) ? 'topic' : null);
        };
        // Plan limits (Pro SaaS): emails that will really go out this month (tests and skipped recipients don't count).
        if ($source !== 'test') {
            $going = 0;
            foreach ($payloads as $p) {
                foreach ($p['to'] as $to) {
                    $going += $skip($p, mb_strtolower($to)) ? 0 : 1;
                }
            }
            app(Edition::class)->assertRoom($workspace, 'emails', $going);
        }

        $messages = DB::transaction(function () use ($workspace, $payloads, $source, $key, $batch, $contacts, $skip) {
            $out = [];
            foreach ($payloads as $p) {
                foreach ($p['to'] as $to) {
                    $lower = mb_strtolower($to);
                    $reason = $skip($p, $lower);
                    $out[] = $this->build($workspace, $p, $to, $source, $key, $batch, $reason, $contacts[$lower] ?? null);
                }
            }

            return $out;
        });

        $queued = array_values(array_filter($messages, fn (Message $m) => $m->status === 'queued'));
        // Small sends from a web request go out right after the response (no wait for the cron worker).
        // Anything bigger, or anything outside a web request (scheduled jobs, console), uses the queue.
        $inline = count($queued) <= self::INLINE_LIMIT && ! app()->runningInConsole();
        if ($inline) {
            foreach ($queued as $m) {
                SendMessage::dispatchAfterResponse($m->id);
            }
        } else {
            foreach (array_chunk(array_map(fn (Message $m) => $m->id, $queued), SendMessages::SIZE) as $ids) {
                SendMessages::dispatch($ids); // sent in parallel within the account's send rate
            }
        }

        return $messages;
    }

    /** @param array<string, mixed> $p */
    private function build(Workspace $workspace, array $p, string $to, string $source, ?ApiKey $key, ?string $batch, ?string $suppressedReason, ?Contact $contact = null): Message
    {
        $m = new Message;
        $m->id = strtolower((string) Str::ulid());
        $m->workspace_id = $workspace->id;
        $m->tracking_code = Message::newTrackingCode();
        // A design with an unsubscribe or preferences link always gets a working one, even for transactional email.
        $linksIn = (string) $p['html'].(string) $p['text'];
        $needsToken = $p['unsubscribe'] || preg_match('/\{\{\s*(unsubscribe_url|preferences_url)/', $linksIn);
        $m->unsubscribe_token = $needsToken ? Message::newUnsubscribeToken() : null;
        $unsub = $m->unsubscribe_token ? self::unsubscribeUrl($m->unsubscribe_token) : '';
        $vars = $p['variables'] + ((array) ($p['recipient_vars'][mb_strtolower($to)] ?? [])) + ($contact ? ContactService::mergeVars($contact) : []) + ['email' => $to, 'tracking_code' => $m->tracking_code,
            'unsubscribe_url' => $unsub, 'preferences_url' => $unsub, 'web_version_url' => WebViewController::url($m->id)];
        $html = $p['html'] !== null ? Compose::merge((string) $p['html'], $vars, true) : null;
        $text = $p['text'] !== null ? Compose::merge((string) $p['text'], $vars, false) : ($html !== null ? Compose::htmlToText($html) : null);

        $m->fill([
            'batch_id' => $batch, 'source' => $source, 'api_key_id' => $key?->id, 'type' => $p['type'], 'topic_id' => $p['topic_id'] ?? null,
            'campaign_id' => $p['campaign_id'] ?? null, 'variant' => $p['variant'] ?? null, 'journey_id' => $p['journey_id'] ?? null, 'journey_node' => $p['journey_node'] ?? null,
            'from_email' => $p['from_email'], 'from_name' => $p['from_name'], 'to_email' => $to, 'provider' => Signals::providerFromDomain($to), 'reply_to' => $p['reply_to'] ?: null,
            'subject' => mb_substr(Compose::merge($p['subject'], $vars, false), 0, 998), 'html' => $html, 'text' => $text,
            'headers' => $p['headers'] ?: null, 'tags' => $p['tags'] ?: null,
            'status' => $suppressedReason ? 'suppressed' : 'queued',
            'error' => $suppressedReason === 'topic' ? 'Not sent: this person opted out of this topic.'
                : ($suppressedReason ? 'Not sent: this address is on your suppression list ('.str_replace('_', ' ', $suppressedReason).').' : null),
        ]);
        $m->save();
        $suppressedReason
            ? $this->timeline->record($workspace->id, $m, 'suppressed', ['reason' => $suppressedReason])
            : $this->timeline->record($workspace->id, $m, 'accepted', ['source' => $source]);

        return $m;
    }

    /**
     * Topic id → set of lowercased emails that opted out (explicitly, or by the topic's default).
     *
     * @param  list<array<string, mixed>>  $payloads
     * @param  array<string, string>  $contactIds  email → contact id
     * @return array<string, array<string, true>>
     */
    private function topicOptOuts(string $workspaceId, array $payloads, array $contactIds): array
    {
        $out = [];
        foreach (array_unique(array_filter(array_map(fn ($p) => $p['topic_id'] ?? null, $payloads))) as $topicId) {
            $topic = Topic::find($topicId);
            $choices = DB::table('contact_topic')->where('topic_id', $topicId)->whereIn('contact_id', array_values($contactIds))->pluck('subscribed', 'contact_id');
            foreach ($payloads as $p) {
                if (($p['topic_id'] ?? null) !== $topicId) {
                    continue;
                }
                foreach ($p['to'] as $to) {
                    $email = mb_strtolower($to);
                    $id = $contactIds[$email] ?? null;
                    $subscribed = $id !== null && isset($choices[$id]) ? (bool) $choices[$id] : (bool) $topic?->default_subscribed;
                    if (! $subscribed) {
                        $out[$topicId][$email] = true;
                    }
                }
            }
        }

        return $out;
    }

    public static function unsubscribeUrl(string $token): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/u/?t='.$token;
    }

    public static function oneClickUrl(string $token): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/api/v1/unsubscribe/'.$token;
    }

    /**
     * @param  array<string, string>  $errors
     * @return list<string>
     */
    private function recipients(mixed $value, array &$errors, string $field, int $max): array
    {
        $list = is_string($value) ? [$value] : (is_array($value) ? array_values($value) : null);
        if ($list === null || ! $list) {
            $errors[$field] ??= 'Add at least one address.';

            return [];
        }
        if (count($list) > $max) {
            $errors[$field] = "Send to at most $max addresses per email. Use the batch endpoint for more.";

            return [];
        }
        $out = [];
        foreach ($list as $raw) {
            $a = is_string($raw) ? Compose::parseAddress($raw) : null;
            if (! $a) {
                $errors[$field] = 'Not a valid email address: '.mb_substr(is_scalar($raw) ? (string) $raw : '?', 0, 80);

                return [];
            }
            $out[mb_strtolower($a[0])] = $a[0];
        }

        return array_values($out);
    }

    private function assertSenderDomain(Workspace $workspace, string $email, string $prefix): void
    {
        if (Senders::method($workspace) === Senders::SMTP) {
            if (Senders::allows($workspace, $email)) {
                return;
            }
            $list = Senders::describe($workspace);
            $msg = $list === ''
                ? 'You haven’t verified a sender yet. Verify your domain or address under Sending → Senders first.'
                : mb_strtolower($email).' isn’t a verified sender. Send from '.$list.' or verify it under Sending → Senders.';
            throw new ApiException('from_domain_not_verified', $msg, 422, [$prefix.'from' => $msg]);
        }
        $domain = Compose::domainOf($email);
        $verified = Domain::where('workspace_id', $workspace->id)->where('status', 'verified')->pluck('name');
        foreach ($verified as $d) {
            if ($domain === $d || str_ends_with($domain, '.'.$d)) {
                return;
            }
        }
        $msg = $verified->isEmpty()
            ? 'You don’t have a verified domain yet. Add one under Domains first.'
            : "$domain isn’t a verified domain. Send from ".$verified->implode(', ').' or verify it under Domains.';
        throw new ApiException('from_domain_not_verified', $msg, 422, [$prefix.'from' => $msg]);
    }
}
