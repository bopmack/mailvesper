<?php

namespace App\Services\Contacts;

use App\Exceptions\ApiException;
use App\Models\Contact;
use App\Models\ContactList;
use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\Suppression;
use App\Models\Workspace;
use App\Services\Journeys\JourneyEvents;
use App\Services\Sending\Mailer;
use App\Services\Sending\Senders;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Signup forms. Without double opt-in a submission adds the contact at once; with it, the contact is added only
 * when they use the link in the confirmation email (so nobody can sign someone else up). A form never resubscribes
 * someone who unsubscribed unless they confirm by email.
 */
class Forms
{
    public const MAX_FIELDS = 20;

    /** Days a confirmation link works. */
    public const CONFIRM_DAYS = 7;

    public function __construct(private ContactService $contacts, private JourneyEvents $events, private Mailer $mailer) {}

    /**
     * Checks and normalises the settings an editor sends.
     *
     * @param  array<string, mixed>  $in
     * @return array<string, mixed>
     */
    public function settings(Workspace $ws, array $in, ?Form $form = null): array
    {
        $bad = fn (string $field, string $m) => new ApiException('validation_failed', $m, 422, [$field => $m]);
        $str = fn (string $k, int $max) => array_key_exists($k, $in) ? (is_string($in[$k]) && trim($in[$k]) !== '' ? mb_substr(trim($in[$k]), 0, $max) : null) : ($form?->{$k});
        $out = [];
        if ($form === null || array_key_exists('name', $in)) {
            $out['name'] = $str('name', 100) ?? throw $bad('name', 'Name the form.');
        }
        foreach (['heading' => 150, 'intro' => 500, 'button' => 40, 'success_message' => 500, 'from_name' => 100] as $k => $max) {
            if (array_key_exists($k, $in)) {
                $out[$k] = $str($k, $max);
            }
        }
        if (array_key_exists('list_id', $in)) {
            $out['list_id'] = is_string($in['list_id']) && $in['list_id'] !== '' ? $in['list_id'] : null;
            if ($out['list_id'] && ! ContactList::where('workspace_id', $ws->id)->whereKey($out['list_id'])->exists()) {
                throw $bad('list_id', 'That list doesn’t exist in this workspace.');
            }
        }
        if (array_key_exists('tags', $in)) {
            $tags = array_values(array_unique(array_map(fn ($t) => mb_strtolower(trim((string) $t)), array_filter((array) $in['tags'], 'is_string'))));
            if (count($tags) > 10 || array_filter($tags, fn ($t) => ! ContactService::validTag($t))) {
                throw $bad('tags', 'Up to 10 tags: letters, digits, spaces, - _ : . only.');
            }
            $out['tags'] = $tags ?: null;
        }
        if (array_key_exists('fields', $in)) {
            $out['fields'] = FormBuilder::fields($in['fields'], self::MAX_FIELDS) ?: null;
        }
        if (array_key_exists('design', $in)) {
            $out['design'] = FormBuilder::design($in['design']);
        }
        if (array_key_exists('display', $in)) {
            $out['display'] = FormBuilder::display($in['display']);
        }
        if (array_key_exists('redirect_url', $in)) {
            $url = $str('redirect_url', 500);
            if ($url !== null && (! filter_var($url, FILTER_VALIDATE_URL) || ! preg_match('#^https?://#i', $url))) {
                throw $bad('redirect_url', 'Enter a full web address, starting with https://.');
            }
            $out['redirect_url'] = $url;
        }
        foreach (['double_opt_in', 'enabled'] as $k) {
            if (array_key_exists($k, $in)) {
                $out[$k] = (bool) $in[$k];
            }
        }
        if (array_key_exists('from_email', $in)) {
            $out['from_email'] = is_string($in['from_email']) && $in['from_email'] !== '' ? mb_strtolower(trim($in['from_email'])) : null;
        }
        $doi = $out['double_opt_in'] ?? $form->double_opt_in ?? true;
        $from = array_key_exists('from_email', $out) ? $out['from_email'] : $form?->from_email;
        if ($doi && $from && ! Senders::allows($ws->id, $from)) {
            throw $bad('from_email', Senders::method($ws->id) === Senders::SMTP ? 'That sender isn’t verified (Sending → Senders).' : 'That address isn’t on a verified domain.');
        }

        return $out;
    }

    /**
     * A sign-up from the public form.
     *
     * @param  array<string, mixed>  $in
     * @return array{status: 'added'|'pending', message: string, redirect: string|null}
     */
    public function submit(Form $form, array $in, ?string $ip): array
    {
        $ws = Workspace::find($form->workspace_id);
        if (! $form->enabled || ! $ws || $ws->deleting_at || $ws->suspended_at) {
            throw new ApiException('form_closed', 'This form isn’t taking sign-ups right now.', 410);
        }
        $email = ContactService::normaliseEmail(is_string($in['email'] ?? null) ? $in['email'] : '');
        $errors = [];
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
            $errors['email'] = 'Enter a valid email address.';
        }
        $data = [];
        foreach ($form->fields ?? [] as $f) {
            [$v, $error] = FormBuilder::value($f + ['type' => 'text', 'param' => null, 'value' => null, 'options' => []], $in[$f['key']] ?? null);
            if ($error) {
                $errors[$f['key']] = $error;
            }
            if ($v !== null) {
                $data[$f['key']] = $v;
            }
            if (($f['type'] ?? '') === 'consent' && $v === 'yes') {
                $data['_consent'] = ['text' => $f['label'], 'at' => now()->toIso8601ZuluString()]; // what they agreed to, kept with the submission
            }
        }
        if ($errors) {
            throw new ApiException('validation_failed', reset($errors), 422, $errors);
        }
        $form->increment('submissions');

        if (! $form->double_opt_in) {
            $this->add($form, $ws, $email, $data, false);
            FormSubmission::create(['form_id' => $form->id, 'workspace_id' => $ws->id, 'email' => $email, 'data' => $data ?: null, 'status' => 'added', 'ip' => $ip, 'created_at' => now()]);

            return ['status' => 'added', 'message' => $form->success_message ?: 'Thanks for signing up!', 'redirect' => $form->redirect_url];
        }

        // One confirmation email per address per form every 10 minutes: resubmitting doesn't flood their inbox.
        $recent = FormSubmission::where('form_id', $form->id)->where('email', $email)->where('status', 'pending')->where('created_at', '>', now()->subMinutes(10))->exists();
        $blocked = Suppression::check($ws->id, [$email])[$email] ?? null; // an unsubscribe is fine: confirming brings them back
        if (! $recent && ! in_array($blocked, ['hard_bounce', 'complaint', 'manual'], true)) {
            $token = Str::random(48);
            FormSubmission::create(['form_id' => $form->id, 'workspace_id' => $ws->id, 'email' => $email, 'data' => $data ?: null, 'token_hash' => hash('sha256', $token),
                'status' => 'pending', 'ip' => $ip, 'created_at' => now()]);
            $this->sendConfirmation($form, $ws, $email, $token);
        }

        // The same answer whatever happened, so the form can't be used to learn who is on the list.
        return ['status' => 'pending', 'message' => 'Almost done: check your inbox and confirm your email address.', 'redirect' => null];
    }

    /** @return array{message: string, redirect: string|null} */
    public function confirm(string $token): array
    {
        $s = FormSubmission::where('token_hash', hash('sha256', $token))->first();
        $form = $s ? Form::find($s->form_id) : null;
        $ws = $form ? Workspace::find($form->workspace_id) : null;
        if (! $s || ! $form || ! $ws || $ws->deleting_at) {
            throw new ApiException('not_found', 'This confirmation link isn’t valid.', 404);
        }
        $done = ['message' => $form->success_message ?: 'You’re subscribed. Thanks for confirming!', 'redirect' => $form->redirect_url];
        if ($s->status === 'confirmed') {
            return $done; // clicked twice
        }
        if ($s->created_at->lt(now()->subDays(self::CONFIRM_DAYS))) {
            throw new ApiException('expired', 'This confirmation link has expired. Sign up again to get a new one.', 410);
        }
        // Claim it first, so two clicks at once can't both add.
        if (! FormSubmission::whereKey($s->id)->where('status', 'pending')->update(['status' => 'confirmed', 'confirmed_at' => now()])) {
            return $done;
        }
        $this->add($form, $ws, $s->email, $s->data ?? [], true);
        $form->increment('confirmed');

        return $done;
    }

    /** @param array<string, string> $data */
    private function add(Form $form, Workspace $ws, string $email, array $data, bool $confirmed): Contact
    {
        $answers = array_diff_key($data, ['_consent' => 1]); // the consent record stays with the submission
        $fields = array_diff_key($answers, ['first_name' => 1, 'last_name' => 1]);
        $payload = ['email' => $email] + array_intersect_key($data, ['first_name' => 1, 'last_name' => 1])
            + ($fields ? ['fields' => $fields] : []) + ($form->list_id ? ['lists' => [$form->list_id]] : []) + ($form->tags ? ['tags' => $form->tags] : [])
            + ($confirmed ? ['resubscribe' => true] : []); // only a confirmed email undoes an unsubscribe
        try {
            $c = $this->contacts->upsert($ws, $payload, 'form');
        } catch (ApiException $e) {
            if ($e->errorCode === 'plan_limit') {
                throw new ApiException('form_closed', 'This form isn’t taking sign-ups right now.', 410);
            }
            throw $e;
        }
        $props = ['form_id' => $form->id, 'form' => $form->name, 'answers' => $answers ?: null]; // journeys can use {{ trigger.properties.answers.… }}
        $this->events->fire($c, 'contact.form_submitted', $props, 'contact');
        if ($confirmed) {
            $this->events->fire($c, 'contact.optin_confirmed', $props, 'contact');
        }

        return $c;
    }

    private function sendConfirmation(Form $form, Workspace $ws, string $email, string $token): void
    {
        $from = $form->from_email;
        if (! $from || ! Senders::allows($ws->id, $from)) {
            report(new \RuntimeException("Form {$form->id} has no verified sender for its confirmation email"));

            return;
        }
        $url = rtrim((string) config('app.frontend_url'), '/').'/f/?c='.$token;
        $name = e($ws->name);
        $html = '<div style="font-family:-apple-system,Segoe UI,Inter,Arial,sans-serif;max-width:480px;margin:0 auto;padding:32px 20px;color:#18181B">'
            .'<h1 style="font-size:21px;margin:0 0 12px">Confirm your subscription</h1>'
            .'<p style="font-size:15px;line-height:1.6;color:#52525B;margin:0 0 24px">Please confirm that you want emails from '.$name.'. If you didn’t sign up, ignore this email and nothing happens.</p>'
            .'<p style="margin:0 0 24px"><a href="'.e($url).'" style="display:inline-block;background:#5B5BF7;color:#fff;text-decoration:none;font-weight:600;padding:12px 22px;border-radius:10px">Yes, subscribe me</a></p>'
            .'<p style="font-size:12.5px;color:#A1A1AA;margin:0">The link works for '.self::CONFIRM_DAYS.' days.</p></div>';
        $p = $this->mailer->validate($ws, ['from' => $form->from_name ? sprintf('"%s" <%s>', str_replace('"', '', $form->from_name), $from) : $from, 'to' => $email,
            'subject' => 'Confirm your subscription to '.$ws->name, 'html' => $html, 'text' => "Confirm your subscription to {$ws->name}:\n$url\n\nIf you didn’t sign up, ignore this email.",
            'type' => 'transactional', 'tags' => ['sendline' => 'form_confirmation']]);
        try {
            $this->mailer->submit($ws, [$p], 'form');
        } catch (ApiException $e) {
            report($e); // no sending set up, or over the plan: the person sees the usual answer; the owner sees the error log
        }
    }

    public static function newKey(): string
    {
        do {
            $k = Str::lower(Str::random(20));
        } while (DB::table('forms')->where('public_key', $k)->exists());

        return $k;
    }
}
