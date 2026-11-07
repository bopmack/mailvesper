<?php

namespace App\Services\Journeys;

use App\Http\Controllers\Api\V1\Contacts\SegmentController;
use App\Jobs\AdvanceJourneyRun;
use App\Models\Contact;
use App\Models\Journey;
use App\Models\JourneyRun;
use App\Models\JourneyVersion;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The event bus between contacts and journeys. Every event is stored on the contact's timeline, then:
 * contacts enter journeys whose trigger matches, runs waiting for it continue, and goals end runs early.
 * Cheap when nothing matches (one indexed query per workspace, memoised briefly), so imports stay fast.
 */
class JourneyEvents
{
    /** @var array<string, array{at: float, rows: list<array{journey: Journey, version: JourneyVersion}>}> */
    private static array $memo = [];

    public static function flush(): void
    {
        self::$memo = [];
    }

    /**
     * @param  array<string, mixed>  $props
     * @param  string|null  $onlyJourney  incoming-webhook triggers start only their own journey
     */
    public function fire(Contact $contact, string $name, array $props = [], string $source = 'system', ?Carbon $at = null, ?string $onlyJourney = null, ?string $dedupe = null): void
    {
        $at ??= now();
        DB::table('contact_events')->insert([
            'workspace_id' => $contact->workspace_id, 'contact_id' => $contact->id, 'name' => mb_substr($name, 0, 100),
            'properties' => $props ? json_encode($props, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null, 'source' => $source,
            'occurred_at' => $at->copy()->utc()->toDateTimeString(),
        ]);
        $this->resume($contact, $name, $props);
        $this->goals($contact, $name);
        foreach ($this->live($contact->workspace_id) as ['journey' => $j, 'version' => $v]) {
            if ($onlyJourney && $j->id !== $onlyJourney) {
                continue;
            }
            foreach ($v->triggers as $t) {
                if ($this->matches($t, $name, $props, $j)) {
                    $this->enroll($j, $v, $contact, (string) $t['node'], ['event' => $name, 'properties' => $props], $dedupe ? "{$t['node']}:$dedupe" : null, $t['filter'] ?? null);
                }
            }
        }
    }

    /**
     * Starts a run if the trigger's filter and the journey's re-entry rule allow it.
     *
     * @param  array<string, mixed>  $context
     * @param  array{match: string, conditions: list<array<string, mixed>>}|null  $filter
     */
    public function enroll(Journey $j, JourneyVersion $v, Contact $contact, string $triggerNode, array $context, ?string $dedupe = null, ?array $filter = null): ?JourneyRun
    {
        if ($filter && ! Conditions::test($contact, $j->id, $filter, SegmentController::customTypes($j->workspace_id), JourneyData::input($contact, $context, null))) {
            return null;
        }
        $opts = $j->opts();
        $runs = JourneyRun::where('journey_id', $j->id)->where('contact_id', $contact->id);
        if ($opts['reentry'] === 'once' && (clone $runs)->exists()) {
            return null;
        }
        if ($opts['reentry'] === 'always' && (clone $runs)->where('entered_at', '>', now()->subHour())->count() >= 20) {
            return null; // loop guard: journeys that trigger each other can't spin forever
        }
        if ($opts['reentry'] === 'cooldown' && ((clone $runs)->whereIn('status', JourneyRun::OPEN)->exists()
            || (clone $runs)->where('finished_at', '>', now()->subDays($opts['cooldown_days']))->exists())) {
            return null;
        }
        try {
            $run = JourneyRun::create([
                'journey_id' => $j->id, 'version_id' => $v->id, 'workspace_id' => $j->workspace_id, 'contact_id' => $contact->id,
                'status' => 'waiting', 'current_node' => null, 'trigger_node' => $triggerNode, 'wake_at' => now(), 'dedupe_key' => $dedupe,
                'context' => $context, 'entered_at' => now(),
            ]);
        } catch (QueryException $e) {
            if (str_contains(strtolower($e->getMessage()), 'unique')) {
                return null; // this exact trigger occurrence already started a run
            }
            throw $e;
        }
        $first = collect($v->graph['edges'])->first(fn ($e) => $e['from'] === $triggerNode && $e['branch'] === '');
        DB::table('journey_steps')->insert(['journey_id' => $j->id, 'run_id' => $run->id, 'node_id' => $triggerNode, 'next_node_id' => $first['to'] ?? null, 'outcome' => 'entered', 'created_at' => now()]);
        AdvanceJourneyRun::dispatchFor($run->id);

        return $run;
    }

    /** @return list<array{journey: Journey, version: JourneyVersion}> */
    public function live(string $workspaceId): array
    {
        $hit = self::$memo[$workspaceId] ?? null;
        if ($hit && microtime(true) - $hit['at'] < 10) {
            return $hit['rows'];
        }
        $journeys = Journey::where('workspace_id', $workspaceId)->where('status', 'active')->whereNotNull('live_version_id')->get();
        $versions = JourneyVersion::whereIn('id', $journeys->pluck('live_version_id'))->get()->keyBy('id');
        $rows = [];
        foreach ($journeys as $j) {
            if (isset($versions[$j->live_version_id])) {
                $rows[] = ['journey' => $j, 'version' => $versions[$j->live_version_id]];
            }
        }
        self::$memo[$workspaceId] = ['at' => microtime(true), 'rows' => $rows];

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $t
     * @param  array<string, mixed>  $props
     */
    private function matches(array $t, string $name, array $props, Journey $j): bool
    {
        if ($t['trigger'] === 'webhook') {
            return $name === 'webhook:'.$j->id;
        }
        if (in_array($t['trigger'], JourneyGraph::SCANNED, true) || $t['event'] !== $name) {
            return false;
        }

        return match ($t['trigger']) {
            'list_added' => ($props['list_id'] ?? null) === $t['list_id'],
            'segment_joined', 'segment_left' => ($props['segment_id'] ?? null) === $t['segment_id'],
            'form_submitted', 'optin_confirmed' => $t['form_id'] === null || ($props['form_id'] ?? null) === $t['form_id'],
            'price_drop' => (int) ($props['drop_percent'] ?? 0) >= (int) ($t['min_percent'] ?? 0),
            'topic_unsubscribed' => $t['topic_id'] === null || ($props['topic_id'] ?? null) === $t['topic_id'],
            'tag_added' => ($props['tag'] ?? null) === $t['tag'],
            'field_changed' => ($props['field'] ?? null) === $t['field'] && ($t['to'] === null || (string) ($props['to'] ?? '') === (string) $t['to']),
            'email_clicked' => empty($t['url_contains']) || str_contains((string) ($props['url'] ?? ''), (string) $t['url_contains']),
            default => true,
        };
    }

    /**
     * Runs paused at "wait for event" continue down the Yes branch.
     *
     * @param  array<string, mixed>  $props
     */
    private function resume(Contact $contact, string $name, array $props): void
    {
        $keys = [$name];
        if (! empty($props['journey_id']) && ! empty($props['journey_node'])) {
            $keys[] = $name.'#'.$props['journey_id'].':'.$props['journey_node'];
        }
        $runs = JourneyRun::where('contact_id', $contact->id)->where('status', 'waiting')->whereIn('waiting_for', $keys)->pluck('id');
        foreach ($runs as $id) {
            // Flip the wait to "happened"; the advance job then takes the Yes branch.
            if (JourneyRun::whereKey($id)->where('status', 'waiting')->whereIn('waiting_for', $keys)->update(['waiting_for' => null, 'wake_at' => now(), 'signal' => 'yes'])) {
                AdvanceJourneyRun::dispatchFor((string) $id);
            }
        }
    }

    /** A goal event ends the contact's open runs in journeys that have that goal, wherever they are. */
    private function goals(Contact $contact, string $name): void
    {
        $open = JourneyRun::where('contact_id', $contact->id)->where('status', 'waiting')->get();
        if ($open->isEmpty()) {
            return;
        }
        $versions = JourneyVersion::whereIn('id', $open->pluck('version_id')->unique())->get()->keyBy('id');
        foreach ($open as $run) {
            $goal = collect($versions[$run->version_id]->graph['nodes'] ?? [])->first(fn ($n) => $n['type'] === 'goal' && ($n['config']['event'] ?? null) === $name);
            if ($goal && JourneyRun::whereKey($run->id)->where('status', 'waiting')->update(['status' => 'goal', 'current_node' => $goal['id'], 'armed' => null, 'wake_at' => null, 'waiting_for' => null, 'finished_at' => now()])) {
                DB::table('journey_steps')->insert(['journey_id' => $run->journey_id, 'run_id' => $run->id, 'node_id' => $goal['id'], 'outcome' => 'goal', 'detail' => 'Reached the goal: '.$name, 'created_at' => now()]);
            }
        }
    }

    public static function newDedupe(): string
    {
        return (string) Str::ulid();
    }
}
