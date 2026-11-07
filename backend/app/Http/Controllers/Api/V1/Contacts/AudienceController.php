<?php

namespace App\Http\Controllers\Api\V1\Contacts;

use App\Exceptions\ApiException;
use App\Http\Controllers\Concerns\Paginates;
use App\Http\Controllers\Concerns\WorkspaceAccess;
use App\Http\Controllers\Controller;
use App\Models\ContactList;
use App\Models\Topic;
use App\Models\Workspace;
use App\Services\Contacts\ContactService;
use App\Services\Journeys\JourneyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Lists, custom fields and topics. */
class AudienceController extends Controller
{
    use Paginates;
    use WorkspaceAccess;

    public function lists(Request $request, Workspace $workspace): JsonResponse
    {
        $search = trim((string) $request->input('q', ''));
        $query = ContactList::where('workspace_id', $workspace->id)->when($search !== '', fn ($q) => $q->where('name', 'like', '%'.addcslashes($search, '%_\\').'%'))->orderBy('name');
        [$rows, $meta] = $this->paged($request) ? $this->page($request, $query) : [$query->get(), null];
        $counts = DB::table('contact_list')->join('contacts', 'contacts.id', '=', 'contact_list.contact_id')->where('contacts.workspace_id', $workspace->id)->whereIn('contact_list.list_id', $rows->pluck('id'))
            ->selectRaw("list_id, count(*) as total, sum(case when contacts.status = 'subscribed' then 1 else 0 end) as subscribed")->groupBy('list_id')->get()->keyBy('list_id');

        return response()->json(['lists' => $rows->map(fn (ContactList $l) => [
            'id' => $l->id, 'name' => $l->name, 'description' => $l->description, 'total' => (int) ($counts[$l->id]->total ?? 0), 'subscribed' => (int) ($counts[$l->id]->subscribed ?? 0),
        ])] + ($meta ? ['meta' => $meta] : []));
    }

    public function storeList(Request $request, Workspace $workspace): JsonResponse
    {
        $this->requireEditor($request, 'create lists');
        $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'description' => ['nullable', 'string', 'max:255']]);
        if (ContactList::where('workspace_id', $workspace->id)->where('name', trim($data['name']))->exists()) {
            throw new ApiException('validation_failed', 'You already have a list with that name.', 422, ['name' => 'You already have a list with that name.']);
        }
        $l = ContactList::create(['workspace_id' => $workspace->id, 'name' => trim($data['name']), 'description' => $data['description'] ?? null]);

        return response()->json(['list' => ['id' => $l->id, 'name' => $l->name, 'description' => $l->description, 'total' => 0, 'subscribed' => 0]], 201);
    }

    public function updateList(Request $request, Workspace $workspace, string $id): JsonResponse
    {
        $this->requireEditor($request, 'edit lists');
        $l = ContactList::where('workspace_id', $workspace->id)->find($id) ?? throw new ApiException('not_found', 'Not found.', 404);
        $l->fill($request->validate(['name' => ['sometimes', 'string', 'max:100'], 'description' => ['nullable', 'string', 'max:255']]))->save();

        return response()->json(['list' => ['id' => $l->id, 'name' => $l->name, 'description' => $l->description]]);
    }

    public function destroyList(Request $request, Workspace $workspace, string $id): JsonResponse
    {
        $this->requireManager($request, 'delete lists');
        ContactList::where('workspace_id', $workspace->id)->where('id', $id)->delete(); // contacts stay

        return response()->json(['deleted' => true]);
    }

    public function fields(Workspace $workspace): JsonResponse
    {
        return response()->json(['fields' => DB::table('contact_fields')->where('workspace_id', $workspace->id)->orderBy('label')->get(['id', 'key', 'label', 'type'])]);
    }

    public function storeField(Request $request, Workspace $workspace): JsonResponse
    {
        $this->requireEditor($request, 'add fields');
        $data = $request->validate(['key' => ['required', 'string'], 'label' => ['required', 'string', 'max:80'], 'type' => ['required', Rule::in(['text', 'number', 'date', 'boolean'])]]);
        if (! ContactService::validKey($data['key'])) {
            throw new ApiException('validation_failed', 'Use lowercase letters, digits and _ (e.g. plan, signup_date), not a built-in name.', 422, ['key' => 'Use lowercase letters, digits and _ (e.g. plan, signup_date).']);
        }
        $created = DB::table('contact_fields')->insertOrIgnore(['workspace_id' => $workspace->id] + $data + ['created_at' => now()]);
        if (! $created) {
            throw new ApiException('validation_failed', 'That field already exists.', 422, ['key' => 'That field already exists.']);
        }

        return response()->json(['field' => $data], 201);
    }

    public function destroyField(Request $request, Workspace $workspace, int $id): JsonResponse
    {
        $this->requireManager($request, 'delete fields');
        DB::table('contact_fields')->where('workspace_id', $workspace->id)->where('id', $id)->delete(); // values on contacts are kept

        return response()->json(['deleted' => true]);
    }

    public function topics(Workspace $workspace): JsonResponse
    {
        $out = DB::table('contact_topic')->join('topics', 'topics.id', '=', 'contact_topic.topic_id')->where('topics.workspace_id', $workspace->id)->where('contact_topic.subscribed', false)
            ->selectRaw('topic_id, count(*) as n')->groupBy('topic_id')->pluck('n', 'topic_id');

        return response()->json(['topics' => Topic::where('workspace_id', $workspace->id)->orderBy('name')->get()->map(fn (Topic $t) => [
            'id' => $t->id, 'name' => $t->name, 'description' => $t->description, 'default_subscribed' => $t->default_subscribed, 'opted_out' => (int) ($out[$t->id] ?? 0),
        ])]);
    }

    public function storeTopic(Request $request, Workspace $workspace): JsonResponse
    {
        $this->requireManager($request, 'create topics');
        $data = $request->validate(['name' => ['required', 'string', 'max:80'], 'description' => ['nullable', 'string', 'max:255'], 'default_subscribed' => ['sometimes', 'boolean']]);
        if (Topic::where('workspace_id', $workspace->id)->count() >= 30) {
            throw new ApiException('topic_limit', 'You can have up to 30 topics.', 422);
        }
        $t = Topic::create(['workspace_id' => $workspace->id] + $data);

        return response()->json(['topic' => $t->only(['id', 'name', 'description', 'default_subscribed'])], 201);
    }

    public function updateTopic(Request $request, Workspace $workspace, string $id): JsonResponse
    {
        $this->requireManager($request, 'edit topics');
        $t = Topic::where('workspace_id', $workspace->id)->find($id) ?? throw new ApiException('not_found', 'Not found.', 404);
        $t->fill($request->validate(['name' => ['sometimes', 'string', 'max:80'], 'description' => ['nullable', 'string', 'max:255'], 'default_subscribed' => ['sometimes', 'boolean']]))->save();

        return response()->json(['topic' => $t->only(['id', 'name', 'description', 'default_subscribed'])]);
    }

    public function destroyTopic(Request $request, Workspace $workspace, string $id): JsonResponse
    {
        $this->requireManager($request, 'delete topics');
        JourneyService::guardTriggerUse($workspace->id, 'topic_id', $id, 'topic');
        Topic::where('workspace_id', $workspace->id)->where('id', $id)->delete();

        return response()->json(['deleted' => true]);
    }
}
