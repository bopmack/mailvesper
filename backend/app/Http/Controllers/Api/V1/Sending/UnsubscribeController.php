<?php

namespace App\Http\Controllers\Api\V1\Sending;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Suppression;
use App\Models\Topic;
use App\Models\Workspace;
use App\Services\Activity\Timeline;
use App\Services\Contacts\ContactService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Unsubscribe links. POST is also the RFC 8058 one-click target that Gmail and Yahoo call
 * ("List-Unsubscribe=One-Click" in the body); it takes effect immediately.
 * When the workspace has topics, people can choose which kinds of email to keep instead.
 */
class UnsubscribeController extends Controller
{
    public function show(string $token): JsonResponse
    {
        $m = $this->message($token);

        return response()->json([
            'sender' => Workspace::find($m->workspace_id)?->name, 'email' => self::mask($m->to_email), 'unsubscribed' => $this->isUnsubscribed($m),
            'topic' => $m->topic_id ? Topic::where('id', $m->topic_id)->value('name') : null, 'topics' => $this->topics($m),
        ]);
    }

    /** Unsubscribe from everything, or (with topic_id) only from that topic. */
    public function unsubscribe(Request $request, string $token, Timeline $timeline): JsonResponse
    {
        $m = $this->message($token);
        $topicId = $request->input('topic_id');
        if (is_string($topicId) && $topicId !== '') {
            $this->choose($m, [$topicId => false]);
            $timeline->record($m->workspace_id, $m, 'unsubscribed', ['method' => 'page', 'topic' => Topic::where('id', $topicId)->value('name')]);

            return response()->json(['unsubscribed' => $this->isUnsubscribed($m), 'topics' => $this->topics($m)]);
        }
        if (! $this->isUnsubscribed($m)) {
            $timeline->record($m->workspace_id, $m, 'unsubscribed', ['method' => $request->input('List-Unsubscribe') === 'One-Click' ? 'one_click' : 'page']);
        }
        Suppression::add($m->workspace_id, $m->to_email, 'unsubscribe', 'Unsubscribe link in "'.mb_substr($m->subject, 0, 120).'"', $m->id);

        return response()->json(['unsubscribed' => true, 'topics' => $this->topics($m)]);
    }

    /** Saves topic choices: {"topics": {"<id>": true|false}}. */
    public function preferences(Request $request, string $token): JsonResponse
    {
        $m = $this->message($token);
        $choices = array_map(fn ($v) => (bool) $v, array_filter((array) $request->input('topics', []), 'is_scalar'));
        $this->choose($m, $choices);

        return response()->json(['unsubscribed' => $this->isUnsubscribed($m), 'topics' => $this->topics($m)]);
    }

    public function resubscribe(string $token): JsonResponse
    {
        $m = $this->message($token);
        Suppression::remove($m->workspace_id, $m->to_email, 'unsubscribe');

        return response()->json(['unsubscribed' => $this->isUnsubscribed($m), 'topics' => $this->topics($m)]);
    }

    /** @param array<string, bool> $choices */
    private function choose(Message $m, array $choices): void
    {
        if (! Topic::where('workspace_id', $m->workspace_id)->whereIn('id', array_map('strval', array_keys($choices)))->exists()) {
            throw new ApiException('validation_failed', 'Unknown topic.', 422);
        }
        $contacts = app(ContactService::class);
        $contact = Contact::where('workspace_id', $m->workspace_id)->where('email', mb_strtolower($m->to_email))->first()
            ?? $contacts->upsert(Workspace::findOrFail($m->workspace_id), ['email' => $m->to_email], 'form'); // remember the choice even for people who aren't contacts yet
        $contacts->setTopics($contact, $choices);
    }

    /** @return list<array{id: string, name: string, description: string|null, subscribed: bool}> */
    private function topics(Message $m): array
    {
        $topics = Topic::where('workspace_id', $m->workspace_id)->orderBy('name')->get();
        if ($topics->isEmpty()) {
            return [];
        }
        $contactId = DB::table('contacts')->where('workspace_id', $m->workspace_id)->where('email', mb_strtolower($m->to_email))->value('id');
        $mine = $contactId ? DB::table('contact_topic')->where('contact_id', $contactId)->pluck('subscribed', 'topic_id') : collect();

        return $topics->map(fn (Topic $t) => ['id' => $t->id, 'name' => $t->name, 'description' => $t->description, 'subscribed' => (bool) ($mine[$t->id] ?? $t->default_subscribed)])->values()->all();
    }

    private function message(string $token): Message
    {
        return Message::where('unsubscribe_token', $token)->first() ?? throw new ApiException('not_found', 'This unsubscribe link isn’t valid.', 404);
    }

    private function isUnsubscribed(Message $m): bool
    {
        return DB::table('suppressions')->where('workspace_id', $m->workspace_id)->where('email', mb_strtolower($m->to_email))->exists();
    }

    public static function mask(string $email): string
    {
        [$local, $domain] = explode('@', $email, 2);

        return mb_substr($local, 0, 2).str_repeat('•', max(1, min(6, mb_strlen($local) - 2))).'@'.$domain;
    }
}
