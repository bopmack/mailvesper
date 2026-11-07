<?php

namespace App\Http\Controllers\Api\V1\Contacts;

use App\Exceptions\ApiException;
use App\Http\Controllers\Concerns\Paginates;
use App\Http\Controllers\Concerns\WorkspaceAccess;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\ContactList;
use App\Models\Form;
use App\Models\Segment;
use App\Models\SiteConnection;
use App\Models\Topic;
use App\Models\Workspace;
use App\Services\Connect\SiteAudience;
use App\Services\Contacts\SegmentQuery;
use App\Services\Journeys\JourneyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Segments: saved rules with live counts. */
class SegmentController extends Controller
{
    use Paginates;
    use WorkspaceAccess;

    /** @return array<string, string> */
    public static function customTypes(string $workspaceId): array
    {
        return DB::table('contact_fields')->where('workspace_id', $workspaceId)->pluck('type', 'key')->all();
    }

    /** Everything the rule builder needs: fields with their types and operators, lists, tags. ?site=<connection>: a live site's. */
    public function catalog(Workspace $workspace): JsonResponse
    {
        $site = (string) request()->query('site', ''); // also called directly (the Pro agent), without a site
        $sites = SiteConnection::where('workspace_id', $workspace->id)->whereNull('revoked_at')->whereNotNull('connected_at')->whereNotNull('audience_url')->orderBy('name')->get();
        if ($site !== '') {
            $c = $sites->firstWhere('id', $site) ?? throw new ApiException('not_found', 'That site isn’t connected.', 404);

            return response()->json([
                'site' => ['id' => $c->id, 'name' => $c->name],
                'fields' => collect(SiteAudience::BUILT_IN)->map(fn ($t, $k) => ['field' => $k, 'type' => $t])->values()
                    ->concat(collect($c->fields ?? [])->map(fn ($f) => ['field' => 'field:'.$f['key'], 'type' => $f['type'], 'label' => $f['label']])),
                'ops' => SegmentQuery::OPS, 'statuses' => ['subscribed', 'unsubscribed'], 'sources' => [], 'lists' => [], 'tags' => [], 'segments' => [], 'forms' => [], 'topics' => [], 'events' => [],
            ]);
        }

        return response()->json([
            'sites' => $sites->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->values(),
            'fields' => collect(SegmentQuery::FIELDS)->map(fn ($t, $k) => ['field' => $k, 'type' => $t])->values()
                ->concat(DB::table('contact_fields')->where('workspace_id', $workspace->id)->orderBy('label')->get()->map(fn ($f) => ['field' => 'field:'.$f->key, 'type' => $f->type, 'label' => $f->label])),
            'ops' => SegmentQuery::OPS, 'statuses' => Contact::STATUSES, 'sources' => ['manual', 'import', 'api', 'form', 'webhook', 'stripe', 'shopify'],
            'lists' => ContactList::where('workspace_id', $workspace->id)->orderBy('name')->get(['id', 'name']),
            'tags' => DB::table('contact_tags')->where('workspace_id', $workspace->id)->distinct()->orderBy('tag')->limit(200)->pluck('tag'),
            'segments' => Segment::where('workspace_id', $workspace->id)->whereNull('site_connection_id')->orderBy('name')->get(['id', 'name']),
            'forms' => Form::where('workspace_id', $workspace->id)->orderBy('name')->get(['id', 'name']),
            'topics' => Topic::where('workspace_id', $workspace->id)->orderBy('name')->get(['id', 'name']),
            'events' => DB::table('contact_events')->where('workspace_id', $workspace->id)->where('name', 'not like', 'contact.%')->distinct()->orderBy('name')->limit(100)->pluck('name'),
        ]);
    }

    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        $q = new SegmentQuery(self::customTypes($workspace->id));
        $search = trim((string) $request->input('q', ''));
        $query = Segment::where('workspace_id', $workspace->id)->when($search !== '', fn ($q) => $q->where('name', 'like', '%'.addcslashes($search, '%_\\').'%'))->orderByRaw('`group` is not null')->orderBy('group')->orderBy('name');
        [$rows, $meta] = $this->paged($request) ? $this->page($request, $query) : [$query->get(), null]; // only the rows shown are counted

        $live = self::countLive($rows->filter(fn (Segment $s) => $s->site_connection_id && (! $s->counted_at || $s->counted_at->lt(now()->subMinutes(10)))));

        return response()->json(['segments' => $rows->map(function (Segment $s) use ($q, $workspace, $live) {
            if (! $s->site_connection_id && (! $s->counted_at || $s->counted_at->lt(now()->subMinutes(10)))) {
                $s->forceFill(['cached_count' => $this->count($q, $workspace, $s->rules), 'counted_at' => now()])->save();
            }

            return self::present($s) + (isset($live[$s->id]) ? ['live_error' => $live[$s->id]] : []);
        })] + ($meta ? ['meta' => $meta] : []));
    }

    /** Live count while the rules are being edited. */
    public function preview(Request $request, Workspace $workspace): JsonResponse
    {
        $rules = (array) $request->input('rules', []);
        if ($request->filled('site_connection_id')) {
            $c = self::site($workspace, (string) $request->input('site_connection_id'));
            SiteAudience::validate($c, $rules);
            $all = SiteAudience::counts($c, ['all' => $rules], false)['all'] ?? 0;
            $agreed = SiteAudience::counts($c, ['yes' => $rules], true)['yes'] ?? 0;
            $page = SiteAudience::page($c, $rules, '', 1, 5);

            return response()->json(['count' => $all, 'subscribed' => $agreed, 'live' => true,
                'sample' => array_map(fn ($p) => ['id' => null, 'email' => $p['email'], 'name' => trim(($p['first_name'] ?? '').' '.($p['last_name'] ?? '')) ?: null], $page['people'])]);
        }
        $q = new SegmentQuery(self::customTypes($workspace->id));
        $base = $q->apply(Contact::where('workspace_id', $workspace->id), $rules);

        return response()->json([
            'count' => (clone $base)->count(), 'subscribed' => (clone $base)->where('status', 'subscribed')->count(),
            'sample' => $base->orderByDesc('id')->limit(5)->get()->map(fn (Contact $c) => ['id' => $c->id, 'email' => $c->email, 'name' => $c->name()]),
        ]);
    }

    public function store(Request $request, Workspace $workspace): JsonResponse
    {
        $this->requireEditor($request, 'create segments');
        $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'rules' => ['required', 'array'], 'site_connection_id' => ['nullable', 'string', 'size:26']]);
        if (Segment::where('workspace_id', $workspace->id)->count() >= 200) {
            throw new ApiException('segment_limit', 'You can have up to 200 segments.', 422);
        }
        if (! empty($data['site_connection_id'])) {
            $c = self::site($workspace, $data['site_connection_id']);
            SiteAudience::validate($c, $data['rules']);
            $s = Segment::create(['workspace_id' => $workspace->id, 'site_connection_id' => $c->id, 'group' => $c->name, 'name' => trim($data['name']), 'rules' => $data['rules'],
                'cached_count' => SiteAudience::counts($c, ['s' => $data['rules']], false)['s'] ?? 0, 'counted_at' => now()]);

            return response()->json(['segment' => self::present($s)], 201);
        }
        $q = new SegmentQuery(self::customTypes($workspace->id));
        $q->validate($data['rules']);
        $s = Segment::create(['workspace_id' => $workspace->id, 'name' => trim($data['name']), 'rules' => $data['rules'], 'cached_count' => $this->count($q, $workspace, $data['rules']), 'counted_at' => now()]);

        return response()->json(['segment' => self::present($s)], 201);
    }

    public function update(Request $request, Workspace $workspace, string $id): JsonResponse
    {
        $this->requireEditor($request, 'edit segments');
        $s = Segment::where('workspace_id', $workspace->id)->find($id) ?? throw new ApiException('not_found', 'Not found.', 404);
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:100'], 'rules' => ['sometimes', 'array']]);
        if ($c = SiteAudience::forSegment($s)) {
            if (isset($data['rules'])) {
                SiteAudience::validate($c, $data['rules']);
            }
            $s->fill($data);
            $s->forceFill(['cached_count' => SiteAudience::counts($c, ['s' => $s->rules], false)['s'] ?? 0, 'counted_at' => now()])->save();

            return response()->json(['segment' => self::present($s)]);
        }
        $q = new SegmentQuery(self::customTypes($workspace->id));
        if (isset($data['rules'])) {
            $q->validate($data['rules']);
        }
        $s->fill($data);
        $s->forceFill(['cached_count' => $this->count($q, $workspace, $s->rules), 'counted_at' => now()])->save();

        return response()->json(['segment' => self::present($s)]);
    }

    public function destroy(Request $request, Workspace $workspace, string $id): JsonResponse
    {
        $this->requireEditor($request, 'delete segments');
        JourneyService::guardTriggerUse($workspace->id, 'segment_id', $id, 'segment');
        Segment::where('workspace_id', $workspace->id)->where('id', $id)->delete();

        return response()->json(['deleted' => true]);
    }

    /** @param array<string, mixed> $rules */
    private function count(SegmentQuery $q, Workspace $workspace, array $rules): int
    {
        return $q->apply(Contact::where('workspace_id', $workspace->id), $rules)->count();
    }

    /** @return array<string, mixed> */
    public static function present(Segment $s): array
    {
        return ['id' => $s->id, 'name' => $s->name, 'group' => $s->group, 'preset' => $s->preset_key !== null, 'site_connection_id' => $s->site_connection_id, 'live' => $s->site_connection_id !== null,
            'rules' => $s->rules, 'count' => $s->cached_count, 'counted_at' => $s->counted_at?->toIso8601ZuluString()];
    }

    /** A live site of this workspace. */
    public static function site(Workspace $workspace, string $id): SiteConnection
    {
        $c = SiteConnection::where('workspace_id', $workspace->id)->find($id);

        return SiteAudience::live($c) ? $c : throw new ApiException('not_found', 'That site isn’t connected for live audiences.', 422);
    }

    /**
     * Refreshes live segments' counts, one request per site. A site that can't be reached keeps its last count.
     *
     * @param  Collection<int, Segment>  $segments
     * @return array<string, string> segment id → why it couldn't be counted
     */
    public static function countLive(Collection $segments): array
    {
        $errors = [];
        foreach ($segments->groupBy('site_connection_id') as $siteId => $group) {
            try {
                $c = SiteAudience::forSegment($group->first());
                $counts = SiteAudience::counts($c, $group->mapWithKeys(fn (Segment $s) => [$s->id => $s->rules])->all(), false);
                foreach ($group as $s) {
                    $s->forceFill(['cached_count' => $counts[$s->id] ?? 0, 'counted_at' => now()])->save();
                }
            } catch (ApiException $e) {
                foreach ($group as $s) {
                    $errors[$s->id] = $e->getMessage();
                }
            }
        }

        return $errors;
    }
}
