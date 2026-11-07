<?php

namespace App\Http\Controllers\Api\V1\Journeys;

use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\Sending\EmailController;
use App\Http\Controllers\Concerns\Paginates;
use App\Http\Controllers\Concerns\WorkspaceAccess;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\EmailTemplate;
use App\Models\Journey;
use App\Models\JourneyEmail;
use App\Models\JourneyRun;
use App\Models\JourneyVersion;
use App\Models\Workspace;
use App\Services\Ai\AiClient;
use App\Services\Audit;
use App\Services\Contacts\ContactService;
use App\Services\Journeys\JourneyAi;
use App\Services\Journeys\JourneyGraph;
use App\Services\Journeys\JourneyService;
use App\Services\Journeys\Recipes;
use App\Services\Platform\Settings;
use App\Services\Sending\Mailer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Automations: journey drafts, publishing, live stats, test runs. */
class JourneyController extends Controller
{
    use Paginates;
    use WorkspaceAccess;

    public function __construct(private JourneyService $service, private JourneyGraph $graph) {}

    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        $search = trim((string) $request->input('q', ''));
        $query = Journey::where('workspace_id', $workspace->id)->where('status', '!=', 'archived')->when($search !== '', fn ($q) => $q->where('name', 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'paused' THEN 1 ELSE 2 END")->orderByDesc('updated_at')->orderByDesc('id');
        [$rows, $meta] = $this->paged($request) ? $this->page($request, $query) : [$query->limit(200)->get(), null];
        $ids = $rows->pluck('id');
        $entered = JourneyRun::whereIn('journey_id', $ids)->where('entered_at', '>=', now()->subDays(30))->selectRaw('journey_id, count(*) n')->groupBy('journey_id')->pluck('n', 'journey_id');
        $inside = JourneyRun::whereIn('journey_id', $ids)->whereIn('status', JourneyRun::OPEN)->selectRaw('journey_id, count(*) n')->groupBy('journey_id')->pluck('n', 'journey_id');
        $totals = DB::table('journey_runs')->whereIn('journey_id', $ids)->selectRaw("journey_id, count(*) n, sum(case when status = 'goal' then 1 else 0 end) g")->groupBy('journey_id')->get()->keyBy('journey_id');

        return response()->json(['journeys' => $rows->map(fn (Journey $j) => $this->summary($j) + [
            'map' => ['nodes' => array_map(fn ($n) => ['id' => $n['id'], 'type' => $n['type'], 'x' => $n['x'], 'y' => $n['y'], 'title' => $n['title']], $j->graph['nodes']), 'edges' => $j->graph['edges']],
            'stats' => ['entered_30d' => (int) ($entered[$j->id] ?? 0), 'inside' => (int) ($inside[$j->id] ?? 0),
                'goal_rate' => isset($totals[$j->id]) && $totals[$j->id]->n ? round($totals[$j->id]->g / $totals[$j->id]->n, 4) : null],
        ])->values(), 'recipes' => Recipes::catalog()] + ($meta ? ['meta' => $meta, 'summary' => [ // the whole workspace, not just this page
            'running' => Journey::where('workspace_id', $workspace->id)->where('status', 'active')->count(),
            'inside' => JourneyRun::whereIn('journey_id', Journey::where('workspace_id', $workspace->id)->select('id'))->whereIn('status', JourneyRun::OPEN)->count()]] : []));
    }

    public function store(Request $request, Workspace $workspace): JsonResponse
    {
        $this->requireEditor($request, 'create journeys');
        $data = $request->validate(['name' => ['nullable', 'string', 'max:120'], 'recipe' => ['nullable', 'string', Rule::in(Recipes::KEYS)]]);
        $j = $this->service->create($workspace->id, $this->currentUser($request), (string) ($data['name'] ?? ''), $data['recipe'] ?? null);
        Audit::log('journey.created', $this->currentUser($request), $workspace->id, ['name' => $j->name]);

        return response()->json(['journey' => $this->full($j)], 201);
    }

    /** The key that signs this journey's "Send webhook" steps, so the receiving app can verify them. */
    public function secret(Request $request, Workspace $workspace, string $id): JsonResponse
    {
        $this->requireManager($request, 'see journey signing secrets');

        return response()->json(['secret' => $this->find($workspace, $id)->secret]);
    }

    public function show(Workspace $workspace, string $id): JsonResponse
    {
        return response()->json(['journey' => $this->full($this->find($workspace, $id))]);
    }

    public function update(Request $request, Workspace $workspace, string $id): JsonResponse
    {
        $this->requireEditor($request, 'edit journeys');
        $j = $this->find($workspace, $id);
        if ($j->status === 'archived') {
            throw new ApiException('archived', 'This journey is archived. Duplicate it to make changes.', 422);
        }
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'], 'graph' => ['sometimes', 'array'], 'base_rev' => ['sometimes', 'integer'],
            'settings' => ['sometimes', 'array'], 'settings.reentry' => ['sometimes', Rule::in(['once', 'cooldown', 'always'])],
            'settings.cooldown_days' => ['sometimes', 'integer', 'min:1', 'max:365'], 'settings.timezone' => ['sometimes', 'timezone:all'],
        ]);
        // Two tabs editing the same journey: the older one must reload instead of overwriting.
        if (isset($data['base_rev'], $data['graph']) && (int) $data['base_rev'] !== $j->draft_rev) {
            throw new ApiException('conflict', 'Someone else changed this journey. Reload to see their changes.', 409);
        }
        if (isset($data['name'])) {
            $j->name = trim($data['name']) ?: $j->name;
        }
        if (isset($data['settings'])) {
            $j->settings = array_merge($j->settings ?? [], array_intersect_key($data['settings'], array_flip(['reentry', 'cooldown_days', 'timezone'])));
            $j->draft_rev++;
        }
        if (array_key_exists('graph', $data)) {
            $this->service->saveGraph($j, $data['graph']);
        } else {
            $j->save();
        }

        return response()->json(['journey' => $this->full($j->refresh())]);
    }

    public function destroy(Request $request, Workspace $workspace, string $id): JsonResponse
    {
        $this->requireEditor($request, 'delete journeys');
        $j = $this->find($workspace, $id);
        if (JourneyRun::where('journey_id', $j->id)->exists()) {
            // Keep the history of journeys that ran: archive instead.
            $this->service->setStatus($j, 'archive');
        } else {
            $j->delete();
        }
        Audit::log('journey.deleted', $this->currentUser($request), $workspace->id, ['name' => $j->name]);

        return response()->json(['deleted' => true]);
    }

    public function duplicate(Request $request, Workspace $workspace, string $id): JsonResponse
    {
        $this->requireEditor($request, 'create journeys');

        return response()->json(['journey' => $this->full($this->service->duplicate($this->find($workspace, $id), $this->currentUser($request)))], 201);
    }

    public function publish(Request $request, Workspace $workspace, string $id): JsonResponse
    {
        $this->requireEditor($request, 'turn journeys on');
        $j = $this->find($workspace, $id);
        if ($j->status === 'archived') {
            throw new ApiException('archived', 'This journey is archived. Duplicate it to use it again.', 422);
        }
        $first = ! $j->live_version_id;
        $this->service->publish($j, $this->currentUser($request));
        Audit::log($first ? 'journey.activated' : 'journey.published', $this->currentUser($request), $workspace->id, ['name' => $j->name]);

        return response()->json(['journey' => $this->full($j->refresh())]);
    }

    public function action(Request $request, Workspace $workspace, string $id, string $action): JsonResponse
    {
        $this->requireEditor($request, 'change journeys');
        $j = $this->service->setStatus($this->find($workspace, $id), $action);
        Audit::log('journey.'.$action, $this->currentUser($request), $workspace->id, ['name' => $j->name]);

        return response()->json(['journey' => $this->full($j->refresh())]);
    }

    public function stats(Workspace $workspace, string $id): JsonResponse
    {
        return response()->json(['stats' => $this->service->stats($this->find($workspace, $id))]);
    }

    /** People in the journey: everyone, or those at one step. */
    public function runs(Request $request, Workspace $workspace, string $id): JsonResponse
    {
        $j = $this->find($workspace, $id);
        $f = $request->validate(['node' => ['nullable', 'string', 'max:24'], 'status' => ['nullable', Rule::in(['open', 'waiting', 'completed', 'goal', 'exited', 'failed'])]]);
        $runs = JourneyRun::where('journey_id', $j->id)
            ->when($f['node'] ?? null, fn ($q, $n) => $q->where(fn ($w) => $w->where('current_node', $n)->orWhereIn('id', DB::table('journey_steps')->where('journey_id', $j->id)->where('node_id', $n)->select('run_id'))))
            ->when($f['status'] ?? null, fn ($q, $s) => $s === 'open' ? $q->whereIn('status', JourneyRun::OPEN) : $q->where('status', $s))
            ->orderByDesc('updated_at')->limit(50)->get();
        $contacts = Contact::whereIn('id', $runs->pluck('contact_id'))->get(['id', 'email', 'first_name', 'last_name'])->keyBy('id');

        return response()->json(['runs' => $runs->map(fn (JourneyRun $r) => [
            'id' => $r->id, 'status' => $r->status, 'node' => $r->current_node, 'wake_at' => $r->wake_at?->toIso8601ZuluString(), 'waiting_for' => $r->waiting_for ? explode('#', $r->waiting_for)[0] : null,
            'entered_at' => $r->entered_at->toIso8601ZuluString(), 'finished_at' => $r->finished_at?->toIso8601ZuluString(), 'exit_reason' => $r->exit_reason,
            'contact' => isset($contacts[$r->contact_id]) ? ['id' => $r->contact_id, 'email' => $contacts[$r->contact_id]->email, 'name' => $contacts[$r->contact_id]->name()] : null,
        ])->values()]);
    }

    /** Every step one contact took (the run's timeline). */
    public function run(Workspace $workspace, string $id, string $runId): JsonResponse
    {
        $j = $this->find($workspace, $id);
        $r = JourneyRun::where('journey_id', $j->id)->findOrFail($runId);
        $steps = DB::table('journey_steps')->where('run_id', $r->id)->orderBy('id')->get(['node_id', 'next_node_id', 'outcome', 'detail', 'output', 'ms', 'message_id', 'created_at']);
        $contact = Contact::find($r->contact_id);

        // With each step's output, and the trigger data: the editor can show what came in and went out (and debug with it).
        return response()->json(['run' => ['id' => $r->id, 'status' => $r->status, 'node' => $r->current_node, 'wake_at' => $r->wake_at?->toIso8601ZuluString(), 'exit_reason' => $r->exit_reason,
            'trigger_node' => $r->trigger_node, 'entered_at' => $r->entered_at->toIso8601ZuluString(), 'trigger' => ['event' => $r->context['event'] ?? null, 'properties' => (array) ($r->context['properties'] ?? [])],
            'contact' => $contact ? ContactService::present($contact) : null,
            'steps' => $steps->map(fn ($s) => ['node' => $s->node_id, 'next' => $s->next_node_id, 'outcome' => $s->outcome, 'detail' => $s->detail, 'message_id' => $s->message_id,
                'output' => $s->output !== null ? json_decode((string) $s->output, true) : null, 'ms' => $s->ms !== null ? (int) $s->ms : null,
                'at' => Carbon::parse((string) $s->created_at, 'UTC')->toIso8601ZuluString()])]]);
    }

    public function exitRun(Request $request, Workspace $workspace, string $id, string $runId): JsonResponse
    {
        $this->requireEditor($request, 'remove contacts from journeys');
        $j = $this->find($workspace, $id);
        $n = JourneyRun::where('journey_id', $j->id)->whereKey($runId)->where('status', 'waiting')
            ->update(['status' => 'exited', 'exit_reason' => 'Removed by '.$this->currentUser($request)->name, 'finished_at' => now(), 'wake_at' => null, 'waiting_for' => null, 'armed' => null]);
        if (! $n) {
            throw new ApiException('not_waiting', 'This contact isn’t waiting in the journey any more.', 422);
        }

        return response()->json(['exited' => true]);
    }

    public function test(Request $request, Workspace $workspace, string $id): JsonResponse
    {
        $j = $this->find($workspace, $id);
        $email = ContactService::normaliseEmail((string) $request->input('email', ''));
        $contact = Contact::where('workspace_id', $workspace->id)->where('email', $email)->first()
            ?? throw new ApiException('not_found', 'No contact with that email in this workspace.', 404, ['email' => 'Not found.']);

        return response()->json(['test' => $this->service->test($j, $contact)]);
    }

    public function email(Workspace $workspace, string $id, string $node): JsonResponse
    {
        $j = $this->find($workspace, $id);
        $e = JourneyEmail::where('journey_id', $j->id)->where('node_id', $node)->first();

        return response()->json(['email' => $this->presentEmail($e, $node)]);
    }

    public function saveEmail(Request $request, Workspace $workspace, string $id, string $node): JsonResponse
    {
        $this->requireEditor($request, 'edit journeys');
        $j = $this->find($workspace, $id);
        $data = $request->validate([
            'subject' => ['sometimes', 'nullable', 'string', 'max:255'], 'preheader' => ['sometimes', 'nullable', 'string', 'max:300'],
            'from_email' => ['sometimes', 'nullable', 'email', 'max:254'], 'from_name' => ['sometimes', 'nullable', 'string', 'max:120'], 'reply_to' => ['sometimes', 'nullable', 'email', 'max:254'],
            'doc' => ['sometimes', 'nullable'], 'template_id' => ['sometimes', 'string', 'size:26'],
        ]);
        if (isset($data['template_id'])) {
            $t = EmailTemplate::where('workspace_id', $workspace->id)->find($data['template_id']) ?? throw new ApiException('not_found', 'Template not found.', 404);
            $data['doc'] = $t->doc;
            $data['subject'] ??= JourneyEmail::where('journey_id', $j->id)->where('node_id', $node)->value('subject') ?: $t->subject;
            unset($data['template_id']);
        }

        return response()->json(['email' => $this->presentEmail($this->service->saveEmail($j, $node, $data), $node)]);
    }

    public function testEmail(Request $request, Workspace $workspace, string $id, string $node, Mailer $mailer): JsonResponse
    {
        $this->requireEditor($request, 'send test emails');
        $j = $this->find($workspace, $id);
        $e = JourneyEmail::where('journey_id', $j->id)->where('node_id', $node)->first();
        if (! $e || ! $e->html) {
            throw new ApiException('not_ready', 'Design the email first.', 422);
        }
        $from = $e->from_email ? ($e->from_name ? "{$e->from_name} <{$e->from_email}>" : $e->from_email) : $request->input('from');
        $p = $mailer->validate($workspace, ['from' => $from, 'to' => $request->input('to'), 'subject' => '[Test] '.($e->subject ?: $j->name), 'html' => $e->html, 'type' => 'marketing']);
        if (count($p['to']) !== 1) {
            throw new ApiException('validation_failed', 'Send a test to one address at a time.', 422, ['to' => 'One address only.']);
        }

        return response()->json(EmailController::present($mailer->submit($workspace, [$p], 'test')[0]), 202);
    }

    /** @return array<string, mixed> */
    private function summary(Journey $j): array
    {
        return ['id' => $j->id, 'name' => $j->name, 'status' => $j->status, 'draft_rev' => $j->draft_rev, 'live_rev' => $j->live_rev,
            'unpublished' => $j->live_version_id !== null && $j->live_rev !== $j->draft_rev,
            'triggers' => array_values(array_map(fn ($n) => $n['title'] ?: 'Trigger', array_filter($j->graph['nodes'], fn ($n) => $n['type'] === 'trigger'))),
            'activated_at' => $j->activated_at?->toIso8601ZuluString(), 'updated_at' => $j->updated_at->toIso8601ZuluString()];
    }

    /** @return array<string, mixed> */
    private function full(Journey $j): array
    {
        $emails = JourneyEmail::where('journey_id', $j->id)->get()->keyBy('node_id');
        $version = $j->live_version_id ? JourneyVersion::find($j->live_version_id) : null;

        return $this->summary($j) + [
            'graph' => $j->graph, 'settings' => $j->opts(), 'version' => $version?->number, 'published_at' => $version?->created_at->toIso8601ZuluString(),
            'hook_url' => rtrim((string) config('app.url'), '/').'/v1/hooks/j/'.$j->hook_token,
            'emails' => $emails->map(fn (JourneyEmail $e) => $this->presentEmail($e, $e->node_id, false))->all(),
            'problems' => $this->graph->problems($j), 'test_data' => $j->test_data,
            'ai' => ['available' => ($why = app(JourneyAi::class)->unavailable()) === null, 'reason' => $why, 'per_day' => (int) Settings::get('ai.journey_steps_per_day'), 'models' => app(AiClient::class)->models()],
        ];
    }

    /** @return array<string, mixed> */
    private function presentEmail(?JourneyEmail $e, string $node, bool $withDoc = true): array
    {
        $out = ['node' => $node, 'subject' => $e?->subject, 'preheader' => $e?->preheader, 'from_email' => $e?->from_email, 'from_name' => $e?->from_name, 'reply_to' => $e?->reply_to,
            'has_design' => (bool) $e?->html, 'size_kb' => $e?->size_kb, 'checks' => $e?->checks];

        return $withDoc ? $out + ['doc' => $e?->doc, 'html' => $e?->html] : $out;
    }

    private function find(Workspace $workspace, string $id): Journey
    {
        return Journey::where('workspace_id', $workspace->id)->find($id) ?? throw new ApiException('not_found', 'Journey not found.', 404);
    }
}
