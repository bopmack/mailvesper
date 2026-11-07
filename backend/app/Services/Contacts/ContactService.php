<?php

namespace App\Services\Contacts;

use App\Exceptions\ApiException;
use App\Models\Contact;
use App\Models\ContactList;
use App\Models\Suppression;
use App\Models\Topic;
use App\Models\Workspace;
use App\Services\Journeys\JourneyEvents;
use App\Support\Edition;
use Illuminate\Support\Facades\DB;

/** Creates, updates, exports and erases contacts. A contact's status always follows the suppression list. */
class ContactService
{
    public const RESERVED_KEYS = ['email', 'first_name', 'last_name', 'status', 'tags', 'lists', 'source', 'id', 'unsubscribe_url', 'tracking_code'];

    public static function normaliseEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public static function validKey(string $key): bool
    {
        return (bool) preg_match('/^[a-z][a-z0-9_]{0,39}$/', $key) && ! in_array($key, self::RESERVED_KEYS, true);
    }

    /**
     * Fields first seen through the API or an integration become field definitions, typed from the value, so they
     * show in the segment builder and compare as numbers or dates there. Existing definitions are never changed.
     *
     * @param  array<string, mixed>  $fields
     */
    public static function registerFields(Workspace $ws, array $fields): void
    {
        $known = DB::table('contact_fields')->where('workspace_id', $ws->id)->pluck('key')->all();
        $rows = [];
        foreach ($fields as $key => $v) {
            if ($v === null || $v === '' || in_array($key, $known, true) || ! self::validKey((string) $key)) {
                continue;
            }
            $rows[] = ['workspace_id' => $ws->id, 'key' => (string) $key, 'label' => mb_substr(ucfirst(str_replace('_', ' ', (string) $key)), 0, 80),
                'type' => self::typeOf($v), 'created_at' => now()];
        }
        if ($rows) {
            DB::table('contact_fields')->insertOrIgnore($rows);
        }
    }

    public static function typeOf(mixed $v): string
    {
        return match (true) {
            is_bool($v) => 'boolean',
            is_int($v), is_float($v) => 'number',
            is_string($v) && (bool) preg_match('/^\d{4}-\d{2}-\d{2}([T ][0-9:.]+(Z|[+-]\d{2}:?\d{2})?)?$/', $v) && strtotime($v) !== false => 'date',
            default => 'text',
        };
    }

    /**
     * Creates or updates one contact. Never re-subscribes an address that unsubscribed, bounced or complained.
     *
     * @param  array<string, mixed>  $data  email, first_name, last_name, fields{}, tags[], lists[], remove_lists[], unsubscribed(bool),
     *                                      resubscribe(bool: only when the person asked for it themselves; never undoes a bounce or complaint)
     */
    public function upsert(Workspace $ws, array $data, string $source, bool $updateExisting = true): Contact
    {
        $errors = $this->errors($ws, $data);
        if ($errors) {
            throw new ApiException('validation_failed', reset($errors), 422, $errors);
        }
        if (! empty($data['fields']) && is_array($data['fields'])) {
            self::registerFields($ws, $data['fields']);
        }
        $email = self::normaliseEmail((string) $data['email']);

        $events = [];
        $c = DB::transaction(function () use ($ws, $data, $email, $source, $updateExisting, &$events) {
            $c = Contact::firstOrNew(['workspace_id' => $ws->id, 'email' => $email]);
            $new = ! $c->exists;
            $before = ['first_name' => $c->first_name, 'last_name' => $c->last_name] + ($c->fields ?? []);
            if ($new || $updateExisting) {
                foreach (['first_name', 'last_name'] as $k) {
                    if (array_key_exists($k, $data)) {
                        $c->{$k} = $data[$k] !== null && $data[$k] !== '' ? mb_substr(trim((string) $data[$k]), 0, 100) : null;
                    }
                }
                if (isset($data['fields']) && is_array($data['fields'])) {
                    $fields = $c->fields ?? [];
                    foreach ($data['fields'] as $k => $v) {
                        if ($v === null || $v === '') {
                            unset($fields[$k]);
                        } else {
                            $fields[$k] = is_string($v) ? mb_substr($v, 0, 1000) : $v;
                        }
                    }
                    $c->fields = $fields ?: null;
                }
            }
            if ($new) {
                app(Edition::class)->assertRoom($ws, 'contacts'); // plan limits (Pro SaaS); no limit otherwise
                $c->source = $source;
            }
            $c->status = $this->statusFor($ws->id, $email);
            $c->save();

            if ($new) {
                $events[] = ['contact.created', ['source' => $source]];
            } else {
                $after = ['first_name' => $c->first_name, 'last_name' => $c->last_name] + ($c->fields ?? []);
                foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $k) {
                    if (($before[$k] ?? null) != ($after[$k] ?? null)) {
                        $events[] = ['contact.field_changed', ['field' => $k, 'from' => $before[$k] ?? null, 'to' => $after[$k] ?? null]];
                    }
                }
            }
            if (! empty($data['tags'])) {
                foreach ($this->addTags($c, (array) $data['tags']) as $t) {
                    $events[] = ['contact.tag_added', ['tag' => $t]];
                }
            }
            if (! empty($data['lists'])) {
                foreach ($this->addLists($c, (array) $data['lists']) as $l) {
                    $events[] = ['contact.list_added', ['list_id' => $l]];
                }
            }
            if (! empty($data['remove_lists'])) {
                DB::table('contact_list')->where('contact_id', $c->id)->whereIn('list_id', (array) $data['remove_lists'])->delete();
            }
            if (($data['unsubscribed'] ?? false) === true) {
                Suppression::add($ws->id, $email, 'unsubscribe', 'Marked unsubscribed ('.$source.')');
                $c->refresh();
            } elseif (($data['resubscribe'] ?? false) === true && Suppression::remove($ws->id, $email, 'unsubscribe')) {
                $c->refresh();
            }

            return $c;
        });
        // Journeys hear about it only once it's committed.
        foreach ($events as [$name, $props]) {
            $this->journeys()->fire($c, $name, $props, 'contact');
        }

        return $c;
    }

    private function journeys(): JourneyEvents
    {
        return app(JourneyEvents::class);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string> field => message
     */
    public function errors(Workspace $ws, array $data): array
    {
        $e = [];
        $email = is_string($data['email'] ?? null) ? self::normaliseEmail($data['email']) : '';
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
            $e['email'] = 'Enter a valid email address.';
        }
        foreach (['first_name', 'last_name'] as $k) {
            if (isset($data[$k]) && (! is_scalar($data[$k]) || mb_strlen((string) $data[$k]) > 100)) {
                $e[$k] = 'Names can be up to 100 characters.';
            }
        }
        if (isset($data['fields'])) {
            if (! is_array($data['fields']) || count($data['fields']) > 100) {
                $e['fields'] = 'Send fields as an object with up to 100 keys.';
            } else {
                foreach ($data['fields'] as $k => $v) {
                    if (! is_string($k) || ! self::validKey($k)) {
                        $e['fields'] = 'Field keys use lowercase letters, digits and _ (e.g. plan, signup_date), and can’t be a built-in name like email.';
                        break;
                    }
                    if (! is_null($v) && ! is_scalar($v)) {
                        $e['fields'] = "The $k field must be text, a number or true/false.";
                        break;
                    }
                }
            }
        }
        if (isset($data['tags']) && (! is_array($data['tags']) || count($data['tags']) > 50 || array_filter($data['tags'], fn ($t) => ! is_string($t) || ! self::validTag($t)))) {
            $e['tags'] = 'Tags are up to 60 characters: letters, digits, spaces, - _ : . only.';
        }
        foreach (['lists', 'remove_lists'] as $k) {
            if (isset($data[$k])) {
                $ids = is_array($data[$k]) ? array_values(array_filter($data[$k], 'is_string')) : [];
                if (! $ids || ContactList::where('workspace_id', $ws->id)->whereIn('id', $ids)->count() !== count(array_unique($ids))) {
                    $e[$k] = 'One of the lists doesn’t exist in this workspace.';
                }
            }
        }
        if (($data['unsubscribed'] ?? false) === true && ($data['resubscribe'] ?? false) === true) {
            $e['resubscribe'] = 'Send unsubscribed or resubscribe, not both.';
        }

        return $e;
    }

    public static function validTag(string $t): bool
    {
        return (bool) preg_match('/^[\pL\pN _:.\-]{1,60}$/u', trim($t));
    }

    /** @param list<string> $tags */
    public function tag(Contact $c, array $tags): void
    {
        foreach ($this->addTags($c, $tags) as $t) {
            $this->journeys()->fire($c, 'contact.tag_added', ['tag' => $t], 'contact');
        }
    }

    /** @param list<string> $tags */
    public function untag(Contact $c, array $tags): void
    {
        DB::table('contact_tags')->where('contact_id', $c->id)->whereIn('tag', array_map(fn ($t) => mb_strtolower(trim($t)), $tags))->delete();
    }

    /** @param list<string> $listIds */
    public function addToLists(Contact $c, array $listIds): void
    {
        foreach ($this->addLists($c, $listIds) as $l) {
            $this->journeys()->fire($c, 'contact.list_added', ['list_id' => $l], 'contact');
        }
    }

    /**
     * Saves topic choices ({topic id: subscribed?}); unknown topics are ignored. Turning a topic off that was on
     * (its saved choice, or the topic's default) is a journey event.
     *
     * @param  array<string, bool>  $choices
     * @return list<string> the topics that were saved
     */
    public function setTopics(Contact $c, array $choices): array
    {
        $topics = Topic::where('workspace_id', $c->workspace_id)->whereIn('id', array_map('strval', array_keys($choices)))->get()->keyBy('id');
        $before = DB::table('contact_topic')->where('contact_id', $c->id)->whereIn('topic_id', $topics->keys())->pluck('subscribed', 'topic_id');
        foreach ($topics as $id => $topic) {
            $on = (bool) $choices[$id];
            DB::table('contact_topic')->updateOrInsert(['topic_id' => $id, 'contact_id' => $c->id], ['subscribed' => $on, 'updated_at' => now()]);
            if (! $on && (bool) ($before[$id] ?? $topic->default_subscribed)) {
                $this->journeys()->fire($c, 'contact.topic_unsubscribed', ['topic_id' => $id, 'topic' => $topic->name], 'contact');
            }
        }

        return $topics->keys()->all();
    }

    /** Sets first_name, last_name or a custom field; a real change is a journey event. */
    public function setField(Contact $c, string $key, ?string $value): void
    {
        $old = in_array($key, ['first_name', 'last_name'], true) ? $c->{$key} : ($c->fields[$key] ?? null);
        if ($old == $value) {
            return;
        }
        if (in_array($key, ['first_name', 'last_name'], true)) {
            $c->{$key} = $value !== null ? mb_substr($value, 0, 100) : null;
        } else {
            $fields = $c->fields ?? [];
            if ($value === null) {
                unset($fields[$key]);
            } else {
                $fields[$key] = mb_substr($value, 0, 1000);
            }
            $c->fields = $fields ?: null;
        }
        $c->save();
        $this->journeys()->fire($c, 'contact.field_changed', ['field' => $key, 'from' => $old, 'to' => $value], 'contact');
    }

    /**
     * @param  list<string>  $tags
     * @return list<string> the tags that are new on this contact
     */
    private function addTags(Contact $c, array $tags): array
    {
        $tags = array_values(array_unique(array_map(fn ($t) => mb_strtolower(trim($t)), $tags)));
        $had = DB::table('contact_tags')->where('contact_id', $c->id)->whereIn('tag', $tags)->pluck('tag')->all();
        DB::table('contact_tags')->insertOrIgnore(array_map(fn ($t) => ['workspace_id' => $c->workspace_id, 'contact_id' => $c->id, 'tag' => $t], $tags));

        return array_values(array_diff($tags, $had));
    }

    /**
     * @param  list<string>  $listIds
     * @return list<string> the lists the contact just joined
     */
    private function addLists(Contact $c, array $listIds): array
    {
        $listIds = array_values(array_unique($listIds));
        $had = DB::table('contact_list')->where('contact_id', $c->id)->whereIn('list_id', $listIds)->pluck('list_id')->all();
        DB::table('contact_list')->insertOrIgnore(array_map(fn ($id) => ['list_id' => $id, 'contact_id' => $c->id, 'created_at' => now()], $listIds));

        return array_values(array_diff($listIds, $had));
    }

    public function statusFor(string $workspaceId, string $email): string
    {
        $reason = Suppression::check($workspaceId, [$email])[$email] ?? null;

        return $reason ? Contact::FROM_SUPPRESSION[$reason] : 'subscribed';
    }

    /** Called whenever the suppression list changes for an address. */
    public static function syncStatus(string $workspaceId, string $email): void
    {
        $email = self::normaliseEmail($email);
        $reason = DB::table('suppressions')->where('workspace_id', $workspaceId)->where('email', $email)->value('reason');
        DB::table('contacts')->where('workspace_id', $workspaceId)->where('email', $email)
            ->update(['status' => is_string($reason) ? Contact::FROM_SUPPRESSION[$reason] : 'subscribed', 'updated_at' => now()]);
    }

    /**
     * Everything MailVesper holds about a contact (GDPR access request).
     *
     * @return array<string, mixed>
     */
    public function export(Contact $c): array
    {
        return [
            'exported_at' => now()->toIso8601ZuluString(),
            'contact' => self::present($c) + ['fields' => $c->fields ?? (object) []],
            'topics' => DB::table('contact_topic')->join('topics', 'topics.id', '=', 'contact_topic.topic_id')->where('contact_id', $c->id)->get(['topics.name', 'contact_topic.subscribed']),
            'suppression' => DB::table('suppressions')->where('workspace_id', $c->workspace_id)->where('email', $c->email)->first(['reason', 'detail', 'created_at']),
            'emails' => DB::table('messages')->where('workspace_id', $c->workspace_id)->where('to_email', $c->email)->orderBy('created_at')
                ->get(['tracking_code', 'subject', 'status', 'created_at', 'delivered_at', 'opened_at', 'clicked_at']),
            'events' => DB::table('message_events')->join('messages', 'messages.id', '=', 'message_events.message_id')
                ->where('messages.workspace_id', $c->workspace_id)->where('messages.to_email', $c->email)->orderBy('message_events.occurred_at')
                ->get(['messages.tracking_code', 'message_events.type', 'message_events.occurred_at', 'message_events.data']),
            'activity' => DB::table('contact_events')->where('contact_id', $c->id)->orderBy('occurred_at')->get(['name', 'properties', 'source', 'occurred_at']),
            'journeys' => DB::table('journey_runs')->join('journeys', 'journeys.id', '=', 'journey_runs.journey_id')->where('journey_runs.contact_id', $c->id)
                ->orderBy('journey_runs.entered_at')->get(['journeys.name', 'journey_runs.status', 'journey_runs.entered_at', 'journey_runs.finished_at']),
        ];
    }

    /**
     * Erases a contact (GDPR): deletes the record and strips personal data from their emails and events.
     * The address stays on the suppression list so it is never imported or emailed again by accident.
     */
    public function erase(Contact $c): void
    {
        DB::transaction(function () use ($c) {
            $ws = $c->workspace_id;
            $email = $c->email;
            $alias = 'erased-'.substr(hash('sha256', $ws.$email), 0, 16).'@erased.invalid';
            $ids = DB::table('messages')->where('workspace_id', $ws)->where('to_email', $email)->pluck('id');
            foreach ($ids->chunk(500) as $chunk) {
                DB::table('message_events')->whereIn('message_id', $chunk->all())->update(['data' => null]);
                DB::table('messages')->whereIn('id', $chunk->all())->update(['to_email' => $alias, 'subject' => '[erased]', 'html' => null, 'text' => null, 'headers' => null, 'unsubscribe_token' => null]);
            }
            $c->delete();
            Suppression::add($ws, $email, 'manual', 'Contact erased on request (GDPR)');
        });
    }

    /** @return array<string, mixed> */
    public static function present(Contact $c): array
    {
        return [
            'id' => $c->id, 'email' => $c->email, 'first_name' => $c->first_name, 'last_name' => $c->last_name, 'name' => $c->name(),
            'status' => $c->status, 'source' => $c->source, 'engagement' => $c->engagement, 'dormant' => $c->dormant, 'fields' => $c->fields ?? (object) [],
            'last_sent_at' => $c->last_sent_at?->toIso8601ZuluString(), 'last_opened_at' => $c->last_opened_at?->toIso8601ZuluString(),
            'last_clicked_at' => $c->last_clicked_at?->toIso8601ZuluString(), 'created_at' => $c->created_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * Merge-tag values for a contact: names, email and every custom field.
     *
     * @return array<string, scalar>
     */
    public static function mergeVars(Contact $c): array
    {
        return array_filter(['first_name' => $c->first_name, 'last_name' => $c->last_name, 'email' => $c->email] + ($c->fields ?? []), fn ($v) => is_scalar($v));
    }
}
