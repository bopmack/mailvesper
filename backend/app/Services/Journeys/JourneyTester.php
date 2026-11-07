<?php

namespace App\Services\Journeys;

use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\Contacts\SegmentController;
use App\Models\Contact;
use App\Models\ContactList;
use App\Models\Journey;
use App\Models\JourneyEmail;
use App\Models\JourneyRun;
use App\Services\Contacts\ContactService;
use App\Services\Sending\Compose;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The editor's "Test step", as in n8n: one step of the draft runs on test data and shows what it would output.
 * Nothing changes for the contact and nothing is emailed (the email step shows the email it would send); the
 * webhook step does call your endpoint, marked "test": true, because its answer is the output. The trigger's test
 * data is the latest real event that would have started the journey, an example, or a test call to its URL.
 */
class JourneyTester
{
    /** Seconds the journey URL takes one test call after "Listen for test event". */
    public const LISTEN_SECONDS = 120;

    /** Scanned triggers: the event and properties the scheduler gives a run (TriggerScan, journeys:dates). */
    private const SCANNED = ['date' => 'sendline.date', 'schedule' => 'sendline.schedule', 'went_quiet' => 'sendline.went_quiet', 'inactive' => 'sendline.inactive'];

    public function __construct(private JourneyGraph $graph, private JourneyEngine $engine, private JourneyAi $ai) {}

    /**
     * Test data for the trigger: {data: {contact, trigger, steps}, source: event|example|webhook, note, at}.
     *
     * @return array<string, mixed>
     */
    public function sample(Journey $j, ?Contact $contact = null): array
    {
        $trigger = collect(JourneyGraph::sanitize($j->graph)['nodes'])->first(fn ($n) => $n['type'] === 'trigger' && ! empty($n['config']['trigger']));
        if (! $trigger) {
            throw new ApiException('incomplete', 'Choose a trigger first.', 422);
        }
        $c = (array) $trigger['config'];
        $t = (string) $c['trigger'];
        $name = $t === 'webhook' ? 'webhook:'.$j->id : JourneyGraph::eventFor($t, $c);
        if (! isset(self::SCANNED[$t]) && $name !== '') {
            $row = DB::table('contact_events')->where('workspace_id', $j->workspace_id)->where('name', $name)
                ->when($contact, fn ($q) => $q->where('contact_id', $contact->id))
                ->where('occurred_at', '>=', now()->subDays(90)->toDateTimeString())->orderByDesc('occurred_at')->orderByDesc('id')->first();
            $who = $row ? Contact::where('workspace_id', $j->workspace_id)->find($row->contact_id) : null;
            if ($row && $who) {
                return ['data' => JourneyData::input($who, ['event' => $name, 'properties' => (array) json_decode((string) $row->properties, true)], null),
                    'source' => 'event', 'at' => Carbon::parse($row->occurred_at, 'UTC')->toIso8601ZuluString(),
                    'note' => 'The latest real “'.$name.'” event'.($contact ? ' for this contact' : '').'.'];
            }
        }
        $who = $contact ?? Contact::where('workspace_id', $j->workspace_id)->where('status', 'subscribed')->orderByDesc('created_at')->first()
            ?? Contact::where('workspace_id', $j->workspace_id)->orderByDesc('created_at')->first()
            ?? throw new ApiException('no_contacts', 'Add a contact first: tests run on a real contact’s data.', 422);
        $props = match ($t) {
            'went_quiet' => ['days' => (int) ($c['days'] ?? 60), 'last_engaged_at' => $who->last_opened_at?->toIso8601ZuluString()],
            'inactive' => ['activity' => (string) ($c['activity'] ?? ''), 'days' => (int) ($c['days'] ?? 14), 'last_seen_at' => null],
            'schedule' => ['date' => now()->toDateString()],
            'date' => [(string) ($c['field'] ?? 'date') => ($who->fields ?? [])[(string) ($c['field'] ?? '')] ?? null],
            default => [],
        };

        return ['data' => JourneyData::input($who, ['event' => self::SCANNED[$t] ?? ($name ?: null), 'properties' => $props], null), 'source' => 'example', 'at' => null,
            'note' => isset(self::SCANNED[$t]) ? 'What the scheduler gives each contact it starts.' : 'No real “'.$name.'” event in the last 90 days, so this is the contact with no event details.'];
    }

    /** "Listen for test event": the journey URL takes one test call (?test=1) for a while. */
    public static function listen(Journey $j): int
    {
        Cache::put('journey:listen:'.$j->id, true, self::LISTEN_SECONDS);

        return self::LISTEN_SECONDS;
    }

    public static function listening(Journey $j): bool
    {
        return (bool) Cache::get('journey:listen:'.$j->id);
    }

    /**
     * A test call to the journey URL becomes the pinned test data (no run starts).
     *
     * @param  array<string, mixed>  $properties
     */
    public static function caught(Journey $j, Contact $contact, array $properties): void
    {
        Cache::forget('journey:listen:'.$j->id);
        $j->forceFill(['test_data' => ['source' => 'webhook', 'at' => now()->toIso8601ZuluString(), 'note' => 'A test call to the journey URL.',
            'data' => JourneyData::input($contact, ['event' => 'webhook:'.$j->id, 'properties' => $properties], null)]])->save();
    }

    /**
     * "1 day", "2 days".
     *
     * @param  array<string, mixed>  $c
     */
    private static function span(array $c): string
    {
        $n = (int) $c['amount'];

        return $n.' '.($n === 1 ? rtrim((string) $c['unit'], 's') : $c['unit']);
    }

    /**
     * Runs one draft step on test data.
     *
     * @param  array<string, mixed>  $data  {contact: {id, …}, trigger, steps}
     * @return array{output: array<string, mixed>, branch: string|null, note: string, failed: bool, ms: int, data: array<string, mixed>}
     */
    public function step(Journey $j, string $nodeId, array $data, ?string $choose = null): array
    {
        $n = $this->graph->draftStep($j, $nodeId); // ApiException: the step isn't complete yet
        if ($n['type'] === 'trigger') {
            throw new ApiException('validation_failed', 'Use “Fetch test event” on the trigger.', 422);
        }
        $contactId = is_array($data['contact'] ?? null) ? (string) ($data['contact']['id'] ?? '') : '';
        $contact = Contact::where('workspace_id', $j->workspace_id)->find($contactId) ?? throw new ApiException('validation_failed', 'Fetch the trigger’s test data first: tests run on a real contact.', 422);
        $trigger = (array) ($data['trigger'] ?? []);
        $steps = array_filter((array) ($data['steps'] ?? []), fn ($k) => is_string($k) && preg_match('/^[A-Za-z0-9_-]{1,24}$/', $k), ARRAY_FILTER_USE_KEY);
        $input = JourneyData::input($contact, ['event' => $trigger['event'] ?? null, 'properties' => (array) ($trigger['properties'] ?? [])], ['steps' => $steps]);
        if (strlen((string) json_encode($input)) > JourneyData::MAX_RUN * 2) {
            throw new ApiException('validation_failed', 'The test data is too large.', 422);
        }
        $c = (array) $n['config'];
        $t0 = hrtime(true);
        [$output, $branch, $note, $failed] = $this->run($j, $n, $c, $contact, $input, $choose) + [3 => false];
        $output = JourneyData::clip($output);
        if ($failed) {
            $mode = $c['on_error'] ?? 'continue';
            $branch = $mode === 'branch' ? 'error' : $branch;
            $note .= ' '.['continue' => 'On error: the run goes on to the next step.', 'stop' => 'On error: the run stops here.', 'branch' => 'On error: the run takes the “Error” connection.'][$mode];
        }

        return ['output' => $output, 'branch' => $branch, 'note' => $note, 'failed' => $failed, 'ms' => (int) round((hrtime(true) - $t0) / 1e6), 'data' => JourneyData::add($input, $nodeId, $output)];
    }

    /**
     * @param  array<string, mixed>  $n
     * @param  array<string, mixed>  $c
     * @param  array<string, mixed>  $input
     * @return array{0: array<string, mixed>, 1: string|null, 2: string, 3?: bool} 3: the step failed
     */
    private function run(Journey $j, array $n, array $c, Contact $contact, array $input, ?string $choose): array
    {
        $tz = $this->engine->timezone($contact, $j);
        switch ($n['type']) {
            case 'email':
                $e = JourneyEmail::where('journey_id', $j->id)->where('node_id', $n['id'])->first();
                $vars = JourneyEngine::triggerVariables(new JourneyRun(['context' => $input['trigger']])) + ContactService::mergeVars($contact); // the event wins, as when sending
                $subject = Compose::merge((string) $e?->subject, $vars, false);

                return [['to' => $contact->email, 'from' => $e?->from_email, 'subject' => $subject, 'type' => $c['type'], 'status' => 'not sent (test)'], null,
                    'Would send “'.$subject.'” to '.$contact->email.'. Use “Send test” on the email to see it in your inbox.'];
            case 'wait':
                $at = now()->addSeconds(JourneyGraph::seconds($c));

                return [['waited' => $c['amount'].' '.$c['unit'], 'until' => $at->toIso8601ZuluString()], null, 'Would wait until '.$at->copy()->setTimezone($tz)->format('D j M, H:i').' ('.$tz.'), then go on.'];
            case 'window':
                $at = $this->engine->nextWindow($c, $tz);

                return [['in_window' => $at->lte(now()), 'at' => $at->toIso8601ZuluString()], null, $at->lte(now()) ? 'In the send window now ('.$tz.'): goes straight on.' : 'Would wait for the window: '.$at->copy()->setTimezone($tz)->format('D j M, H:i').' ('.$tz.').'];
            case 'waitfor':
                $yes = $choose === 'yes';

                return [['event' => $c['event'], 'happened' => $yes], $yes ? 'yes' : 'no', 'Waits up to '.self::span($c).' for '.$c['event'].'. Testing the '.($yes ? '“Yes” (it happened)' : '“No” (it didn’t)').' path.'];
            case 'cond':
                $each = Conditions::explain($contact, $j->id, $c['rules'], SegmentController::customTypes($j->workspace_id), $input);
                $yes = $c['rules']['match'] === 'any' ? in_array(true, $each, true) : ! in_array(false, $each, true);

                return [['result' => $yes ? 'yes' : 'no', 'conditions' => $each], $yes ? 'yes' : 'no', $yes ? 'Matches: goes down Yes.' : 'Doesn’t match: goes down No.'];
            case 'split':
                $side = in_array($choose, ['a', 'b'], true) ? $choose : JourneyEngine::side('test:'.$contact->id, $n['id'], (int) $c['percent_a']);

                return [['side' => $side], $side, 'Random split: this contact goes down '.strtoupper($side).'.'];
            case 'goal':
                return [['goal' => $c['event']], null, 'Contacts who do '.$c['event'].' leave the journey here.'];
            case 'exit':
                return [['exited' => true], null, 'Leaves the journey.'];
            case 'jump':
                $target = Journey::where('workspace_id', $j->workspace_id)->find($c['journey_id']);

                return [['journey_id' => $c['journey_id'], 'journey' => $target?->name, 'moved' => false], null, 'Would move them to “'.($target->name ?? 'another journey').'”.'];
            case 'tag':
                $tag = mb_strtolower(trim(JourneyData::resolve((string) $c['tag'], $input)));
                if (! ContactService::validTag($tag)) {
                    return [['tag' => $tag, 'error' => 'invalid tag'], null, 'The tag comes out empty or invalid with this data: “'.mb_substr($tag, 0, 60).'”.', true];
                }
                $has = DB::table('contact_tags')->where('contact_id', $contact->id)->where('tag', $tag)->exists();

                return [['action' => $c['action'], 'tag' => $tag], null, ($c['action'] === 'remove' ? 'Would remove' : 'Would add').' the tag “'.$tag.'”'.($has === ($c['action'] === 'add') ? ' (they '.($has ? 'already have' : 'don’t have').' it).' : '.')];
            case 'field':
                $value = JourneyData::resolve((string) $c['value'], $input);

                return [['field' => $c['key'], 'before' => JourneyEngine::fieldValue($contact, (string) $c['key']), 'after' => $value === '' ? null : $value], null, 'Would set '.$c['key'].' to '.($value === '' ? '(empty)' : '“'.mb_substr($value, 0, 120).'”').'.'];
            case 'list':
                $name = ContactList::where('workspace_id', $j->workspace_id)->whereKey($c['list_id'])->value('name');

                return [['action' => $c['action'], 'list_id' => $c['list_id'], 'list' => $name], null, ($c['action'] === 'remove' ? 'Would remove them from ' : 'Would add them to ').'“'.$name.'”.'];
            case 'notify':
                $text = JourneyEngine::notifyText($j, $contact, (string) $c['message'], $input);

                return [['channel' => $c['channel'], 'to' => $c['to'] ?? null, 'message' => $text], null, 'Would notify your team ('.$c['channel'].'). Nothing was sent.'];
            case 'webhook':
                $r = $this->engine->webhook('test_'.$contact->id, $j, $contact, $c + ['node' => $n['id']], $input['trigger'], $input, true);

                return [(array) ($r['output'] ?? []), null, 'Called your endpoint for real (marked "test": true): '.($r['detail'] ?? '').'.', ($r['outcome'] ?? '') === 'failed'];
            case 'switch':
                $r = JourneyCore::switch($c, $input);

                return [$r['output'], $r['branch'], $r['branch'] === 'other' ? 'No case matched “'.mb_substr((string) $r['output']['value'], 0, 80).'”: goes down Other.' : 'Matches “'.$r['detail'].'”: goes down that path.'];
            case 'filter':
                $r = JourneyCore::filter($j, $contact, $c, $input);

                return [$r['output'], null, isset($r['end']) ? 'Doesn’t pass the filter: the run would end here.' : 'Passes the filter: goes on.'];
            case 'set':
                return [JourneyCore::set($c, $input)['output'], null, 'Later steps can use these values.'];
            case 'datetime':
                $r = JourneyCore::datetime($c, $input, $tz);

                return [$r['output'], null, ($r['outcome'] === 'failed' ? (string) $r['detail'].'.' : 'In '.$tz.'.'), $r['outcome'] === 'failed'];
            case 'http':
                $r = JourneyCore::http($c, $input);

                return [$r['output'], null, 'Called the API for real: '.$r['detail'].'.', $r['outcome'] === 'failed'];
            case 'stop':
                return [['stopped' => true, 'message' => $c['message']], null, 'The run would stop here, as failed: “'.$c['message'].'”.'];
            case 'approval':
                $yes = $choose !== 'no';

                return [['answer' => $yes ? 'approved' : 'rejected', 'approved' => $yes], $yes ? 'yes' : 'no', 'Would email '.implode(', ', (array) $c['to']).' a link to approve or reject, and wait up to '.self::span($c).' (no answer counts as not approved). Nothing was sent. Testing the '.($yes ? '“Approved”' : '“Not approved”').' path.'];
            case 'ai_write':
            case 'ai_classify':
            case 'ai_extract':
            case 'ai_agent':
            case 'ai_sentiment':
            case 'ai_summarize':
                $r = $this->ai->run($j, $contact, $n, $input, true);
                if (($r['outcome'] ?? '') === 'failed') {
                    return [(array) ($r['output'] ?? []), null, (string) ($r['detail'] ?? 'The AI step failed.'), true];
                }
                $out = (array) ($r['output'] ?? []);
                $note = match ($n['type']) {
                    'ai_classify' => 'The AI chose “'.($out['category'] ?? 'Other').'”: goes down that path.',
                    'ai_sentiment' => 'The AI reads it as '.($out['sentiment'] ?? 'neutral').': goes down that path.',
                    'ai_agent' => (! empty($out['memory']) ? 'It remembered '.(int) $out['memory']['remembered'].' earlier exchange'.((int) $out['memory']['remembered'] === 1 ? '' : 's').'; a test adds nothing to its memory. ' : '').'The agent really ran on this contact’s data. '.match ((int) ($out['changes'] ?? 0)) {
                        0 => 'It changed nothing.', 1 => 'Its change wasn’t made: this is a test.', default => 'Its '.(int) $out['changes'].' changes weren’t made: this is a test.'
                    },
                    default => 'The AI really ran on this test data.',
                };

                return [$out, $r['branch'] ?? null, $note.' '.number_format((int) ($out['tokens'] ?? 0)).' tokens.'];
            default:
                return [[], null, ''];
        }
    }
}
