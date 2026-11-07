<?php

namespace App\Services\Journeys;

use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\Contacts\SegmentController;
use App\Jobs\AdvanceJourneyRun;
use App\Models\Contact;
use App\Models\Journey;
use App\Models\JourneyEmail;
use App\Models\JourneyRun;
use App\Models\JourneyVersion;
use App\Models\User;
use App\Services\Design\DocValidator;
use App\Services\Design\EmailRenderer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class JourneyService
{
    public function __construct(private JourneyGraph $graph, private DocValidator $docs, private EmailRenderer $renderer) {}

    /**
     * Refuses to delete a segment, topic or form that a journey's trigger uses (draft or live), naming the journey.
     *
     * @param  'segment_id'|'topic_id'|'form_id'  $key
     */
    public static function guardTriggerUse(string $workspaceId, string $key, string $id, string $what): void
    {
        foreach (Journey::where('workspace_id', $workspaceId)->where('status', '!=', 'archived')->get(['id', 'name', 'graph']) as $j) {
            foreach ($j->graph['nodes'] ?? [] as $n) {
                if (($n['type'] ?? null) === 'trigger' && ($n['config'][$key] ?? null) === $id) {
                    throw new ApiException('in_use', "The journey “{$j->name}” starts from this $what. Change its trigger first.", 409);
                }
            }
        }
    }

    public function create(string $workspaceId, ?User $user, string $name, ?string $recipe = null): Journey
    {
        $r = $recipe ? (Recipes::get($recipe) ?? throw new ApiException('not_found', 'That recipe doesn’t exist.', 404)) : null;

        return DB::transaction(function () use ($workspaceId, $user, $name, $r) {
            $j = Journey::create([
                'workspace_id' => $workspaceId, 'name' => mb_substr(trim($name) ?: ($r['name'] ?? 'Untitled journey'), 0, 120), 'status' => 'draft',
                'graph' => $r ? ['nodes' => $r['nodes'], 'edges' => $r['edges']] : ['nodes' => [['id' => 't1', 'type' => 'trigger', 'x' => 0, 'y' => 120, 'title' => '', 'config' => []]], 'edges' => []],
                'settings' => ['reentry' => $r['reentry'] ?? 'once', 'cooldown_days' => 30, 'timezone' => 'UTC'],
                'hook_token' => Str::random(32), 'secret' => 'whsec_'.base64_encode(random_bytes(24)), 'created_by' => $user?->id,
            ]);
            foreach ($r['emails'] ?? [] as $node => $e) {
                JourneyEmail::create(['journey_id' => $j->id, 'node_id' => $node, 'subject' => $e['subject'], 'preheader' => $e['preheader'] ?? null]);
            }

            return $j;
        });
    }

    /** Saves the draft graph (shape-checked; steps may be incomplete) and drops email drafts of deleted steps. */
    public function saveGraph(Journey $j, mixed $graph): void
    {
        $g = JourneyGraph::sanitize($graph);
        $j->graph = $g;
        $j->draft_rev++;
        $j->save();
        $emailNodes = array_column(array_filter($g['nodes'], fn ($n) => $n['type'] === 'email'), 'id');
        JourneyEmail::where('journey_id', $j->id)->whereNotIn('node_id', $emailNodes ?: ['-'])->delete();
    }

    /**
     * Content of one "Send email" step. A design is rendered on the server, like templates and campaigns.
     *
     * @param  array<string, mixed>  $data
     */
    public function saveEmail(Journey $j, string $node, array $data): JourneyEmail
    {
        $n = collect($j->graph['nodes'])->first(fn ($x) => $x['id'] === $node && $x['type'] === 'email')
            ?? throw new ApiException('not_found', 'That email step isn’t in this journey. Save the journey first.', 404);
        $e = JourneyEmail::firstOrNew(['journey_id' => $j->id, 'node_id' => $n['id']]);
        $e->fill(array_intersect_key($data, array_flip(['subject', 'preheader', 'from_email', 'from_name', 'reply_to'])));
        $hasDoc = array_key_exists('doc', $data);
        if ($hasDoc && is_array($data['doc']) && ! array_key_exists('preheader', $data) && is_string($data['doc']['settings']['preheader'] ?? null) && trim($data['doc']['settings']['preheader']) !== '') {
            $e->preheader = mb_substr(trim($data['doc']['settings']['preheader']), 0, 300); // edited in the builder
        }
        if ($hasDoc && $data['doc'] === null) {
            $e->fill(['doc' => null, 'html' => null, 'size_kb' => null, 'checks' => null]);
        } else {
            // A new design, or a new subject/preview text for the existing one: render again.
            $doc = $hasDoc ? $data['doc'] : (array_key_exists('preheader', $data) || array_key_exists('subject', $data) ? $e->doc : null);
            if ($doc !== null) {
                $doc = $this->docs->validate($doc);
                if (is_string($e->preheader) && $e->preheader !== '') {
                    $doc['settings']['preheader'] = $e->preheader;
                }
                $r = $this->renderer->render($doc, (string) $e->subject);
                $e->fill(['doc' => $doc, 'html' => $r['html'], 'size_kb' => $r['kb'], 'checks' => $r['checks']]);
            }
        }
        $e->save();
        $j->increment('draft_rev');

        return $e;
    }

    /** Freezes the draft as a new version that new runs follow, and turns the journey on. */
    public function publish(Journey $j, ?User $user): Journey
    {
        try {
            $c = $this->graph->compile($j);
        } catch (JourneyProblems $e) {
            throw new ApiException('not_ready', $e->getMessage(), 422, details: ['problems' => $e->problems]);
        }

        return DB::transaction(function () use ($j, $user, $c) {
            $number = (int) JourneyVersion::where('journey_id', $j->id)->max('number') + 1;
            $v = JourneyVersion::create(['journey_id' => $j->id, 'number' => $number, 'graph' => $c['graph'], 'emails' => $c['emails'], 'triggers' => $c['triggers'],
                'published_by' => $user?->id, 'created_at' => now()]);
            $j->forceFill(['live_version_id' => $v->id, 'live_rev' => $j->draft_rev, 'status' => 'active', 'activated_at' => $j->activated_at ?? now()])->save();
            JourneyEvents::flush();

            return $j;
        });
    }

    public function setStatus(Journey $j, string $action): Journey
    {
        match ($action) {
            'pause' => $j->status === 'active' ? $j->forceFill(['status' => 'paused'])->save() : throw new ApiException('not_active', 'Only an active journey can be paused.', 422),
            'resume' => $j->status === 'paused' && $j->live_version_id ? $j->forceFill(['status' => 'active'])->save() : throw new ApiException('not_paused', 'Only a paused journey can be turned back on.', 422),
            'archive' => $this->archive($j),
            default => throw new ApiException('validation_failed', 'Unknown action.', 422),
        };
        JourneyEvents::flush();
        if ($action === 'resume') {
            // Anyone whose wait ended while paused continues now.
            JourneyRun::where('journey_id', $j->id)->where('status', 'waiting')->where('wake_at', '<=', now())->pluck('id')->each(fn ($id) => AdvanceJourneyRun::dispatchFor((string) $id));
        }

        return $j;
    }

    private function archive(Journey $j): void
    {
        $j->forceFill(['status' => 'archived'])->save();
        JourneyRun::where('journey_id', $j->id)->whereIn('status', JourneyRun::OPEN)->update(['status' => 'exited', 'exit_reason' => 'The journey was archived.', 'finished_at' => now(),
            'wake_at' => null, 'waiting_for' => null, 'armed' => null]);
    }

    public function duplicate(Journey $j, ?User $user): Journey
    {
        return DB::transaction(function () use ($j, $user) {
            $copy = Journey::create(['workspace_id' => $j->workspace_id, 'name' => mb_substr($j->name.' (copy)', 0, 120), 'status' => 'draft', 'graph' => $j->graph, 'settings' => $j->settings,
                'hook_token' => Str::random(32), 'secret' => 'whsec_'.base64_encode(random_bytes(24)), 'created_by' => $user?->id]);
            foreach (JourneyEmail::where('journey_id', $j->id)->get() as $e) {
                JourneyEmail::create(['journey_id' => $copy->id] + $e->only(['node_id', 'subject', 'preheader', 'from_email', 'from_name', 'reply_to', 'doc', 'html', 'size_kb', 'checks']));
            }

            return $copy;
        });
    }

    /** @return array<string, mixed> */
    public function stats(Journey $j): array
    {
        $runs = JourneyRun::where('journey_id', $j->id);
        $byStatus = (clone $runs)->selectRaw('status, count(*) n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all();
        $entered = array_sum($byStatus);
        $reached = DB::table('journey_steps')->where('journey_id', $j->id)->selectRaw('node_id, count(distinct run_id) n')->groupBy('node_id')->pluck('n', 'node_id')->map(fn ($n) => (int) $n)->all();
        $here = (clone $runs)->where('status', 'waiting')->whereNotNull('current_node')->selectRaw('current_node, count(*) n')->groupBy('current_node')->pluck('n', 'current_node')->map(fn ($n) => (int) $n)->all();
        $edges = DB::table('journey_steps')->where('journey_id', $j->id)->whereNotNull('next_node_id')->selectRaw('node_id, next_node_id, count(*) n')->groupBy('node_id', 'next_node_id')->get()
            ->map(fn ($r) => ['from' => $r->node_id, 'to' => $r->next_node_id, 'count' => (int) $r->n])->all();
        $outcomes = DB::table('journey_steps')->where('journey_id', $j->id)->whereNotNull('outcome')->selectRaw('node_id, outcome, count(*) n')->groupBy('node_id', 'outcome')->get()
            ->groupBy('node_id')->map(fn ($rows) => $rows->pluck('n', 'outcome')->map(fn ($n) => (int) $n))->all();
        $emails = DB::table('messages')->where('journey_id', $j->id)->selectRaw("journey_node, count(*) sent, sum(case when delivered_at is not null then 1 else 0 end) delivered,
            sum(case when opened_at is not null then 1 else 0 end) opened, sum(case when clicked_at is not null then 1 else 0 end) clicked,
            sum(case when status = 'bounced' then 1 else 0 end) bounced, sum(case when status = 'complained' then 1 else 0 end) complained")
            ->groupBy('journey_node')->get()->keyBy('journey_node')->map(fn ($r) => ['sent' => (int) $r->sent, 'delivered' => (int) $r->delivered, 'opened' => (int) $r->opened,
                'clicked' => (int) $r->clicked, 'bounced' => (int) $r->bounced, 'complained' => (int) $r->complained])->all();

        return [
            'entered' => $entered, 'entered_30d' => (clone $runs)->where('entered_at', '>=', now()->subDays(30))->count(),
            'inside' => ($byStatus['waiting'] ?? 0) + ($byStatus['running'] ?? 0), 'completed' => $byStatus['completed'] ?? 0, 'goal' => $byStatus['goal'] ?? 0,
            'exited' => $byStatus['exited'] ?? 0, 'failed' => $byStatus['failed'] ?? 0, 'goal_rate' => $entered ? round(($byStatus['goal'] ?? 0) / $entered, 4) : null,
            'nodes' => $reached, 'here' => $here, 'edges' => $edges, 'outcomes' => $outcomes, 'emails' => $emails,
        ];
    }

    /**
     * Dry run: the path this contact would take through the current draft, using their real data. Sends nothing.
     *
     * @return array<string, mixed>
     */
    public function test(Journey $j, Contact $contact): array
    {
        try {
            $c = $this->graph->compile($j);
        } catch (JourneyProblems $e) {
            return ['ready' => false, 'problems' => $e->problems, 'steps' => []];
        }
        $nodes = [];
        foreach ($c['graph']['nodes'] as $n) {
            $nodes[$n['id']] = $n;
        }
        $next = [];
        foreach ($c['graph']['edges'] as $e) {
            $next[$e['from']][$e['branch']] = $e['to'];
        }
        $types = SegmentController::customTypes($j->workspace_id);
        $trigger = collect($c['graph']['nodes'])->first(fn ($n) => $n['type'] === 'trigger');
        $steps = [];
        $filter = $trigger['config']['filter'] ?? null;
        $enters = ! $filter || Conditions::test($contact, $j->id, $filter, $types);
        $before = JourneyRun::where('journey_id', $j->id)->where('contact_id', $contact->id)->exists();
        $reentry = $j->opts()['reentry'];
        $steps[] = ['node' => $trigger['id'], 'text' => $enters ? 'Enters when: '.($trigger['title'] ?: $trigger['config']['trigger']) : 'Wouldn’t enter: doesn’t match the trigger’s filter', 'branch' => null];
        if ($enters && $before && $reentry === 'once') {
            $steps[] = ['node' => $trigger['id'], 'text' => 'Has been through this journey before, and it runs only once per contact.', 'branch' => null];
            $enters = false;
        }
        $node = $enters ? ($next[$trigger['id']][''] ?? null) : null;
        $tz = app(JourneyEngine::class)->timezone($contact, $j);
        for ($i = 0; $node && $i < JourneyEngine::MAX_STEPS; $i++) {
            $n = $nodes[$node];
            $cfg = $n['config'];
            $branch = '';
            $text = match ($n['type']) {
                'email' => 'Sends “'.$c['emails'][$n['id']]['subject'].'”'.(($cfg['skip_recent_hours'] ?? 0) ? ' (skipped if they got a marketing email in the last '.$cfg['skip_recent_hours'].' h)' : ''),
                'wait' => 'Waits '.$cfg['amount'].' '.$cfg['unit'],
                'window' => 'Waits for the send window ('.implode(',', array_map(fn ($d) => ['', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'][$d], $cfg['days'])).' '.$cfg['from'].'–'.$cfg['to'].' '.$tz.')',
                'waitfor' => 'Waits up to '.$cfg['amount'].' '.$cfg['unit'].' for '.$cfg['event'].'. Following “No” (it hasn’t happened yet)',
                'cond' => Conditions::test($contact, $j->id, $cfg['rules'], $types) ? 'Matches the conditions: goes down Yes' : 'Doesn’t match: goes down No',
                'split' => 'Random split: '.$cfg['percent_a'].'% A, '.(100 - $cfg['percent_a']).'% B. Following A',
                'goal' => 'Reaches the goal and leaves',
                'exit' => 'Leaves the journey',
                'jump' => 'Moves to another journey',
                'tag' => ($cfg['action'] === 'remove' ? 'Removes tag ' : 'Adds tag ').$cfg['tag'],
                'field' => 'Sets '.$cfg['key'].' to '.($cfg['value'] === '' ? '(empty)' : $cfg['value']),
                'list' => ($cfg['action'] === 'remove' ? 'Leaves a list' : 'Joins a list'),
                'notify' => 'Notifies your team ('.$cfg['channel'].')',
                'webhook' => 'Calls your webhook '.parse_url($cfg['url'], PHP_URL_HOST),
                default => ucfirst($n['type']),
            };
            if ($n['type'] === 'cond') {
                $branch = str_contains($text, 'Yes') ? 'yes' : 'no';
            } elseif ($n['type'] === 'waitfor') {
                $branch = 'no';
            } elseif ($n['type'] === 'split') {
                $branch = 'a';
            }
            $steps[] = ['node' => $n['id'], 'text' => $text, 'branch' => $branch ?: null];
            if (in_array($n['type'], ['goal', 'exit', 'jump'], true)) {
                break;
            }
            $node = $next[$n['id']][$branch] ?? null;
        }

        return ['ready' => true, 'enters' => $enters, 'steps' => $steps];
    }
}
