<?php

namespace App\Services\Journeys;

use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\Contacts\SegmentController;
use App\Jobs\AdvanceJourneyRun;
use App\Models\Contact;
use App\Models\Journey;
use App\Models\JourneyRun;
use App\Models\JourneyVersion;
use App\Models\Workspace;
use App\Services\Contacts\ContactService;
use App\Services\Sending\Mailer;
use App\Services\Webhooks\Signer;
use App\Services\Webhooks\UrlGuard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Moves one run through its journey version, step after step, until it has to wait or reaches an end.
 * A run is claimed (waiting → running) before anything happens, so two workers never move it twice.
 */
class JourneyEngine
{
    /** Safety valve: steps per pass before the run yields to the queue. */
    public const MAX_STEPS = 50;

    public function __construct(private Mailer $mailer, private ContactService $contacts, private JourneyEvents $events, private JourneyAi $ai, private TeamMail $team) {}

    public function advance(string $runId): void
    {
        $run = JourneyRun::find($runId);
        if (! $run || $run->status !== 'waiting' || ($run->wake_at && $run->wake_at->isFuture())) {
            return;
        }
        $journey = Journey::find($run->journey_id);
        if (! $journey || $journey->status === 'paused' || $journey->status === 'draft') {
            return; // paused journeys hold everyone where they are
        }
        if ($journey->status === 'archived') {
            $this->finish($run, 'exited', $run->current_node, 'The journey was archived.');

            return;
        }
        if (! JourneyRun::whereKey($run->id)->where('status', 'waiting')->update(['status' => 'running', 'updated_at' => now()])) {
            return; // another worker has it
        }
        $run->status = 'running';
        $run->syncOriginalAttribute('status'); // the DB says running now; later saves must compare against that
        try {
            $this->walk($run, $journey);
        } catch (Throwable $e) {
            report($e);
            $this->finish($run, 'failed', $run->current_node, 'Stopped by an unexpected error. Our team has been notified.');
        }
    }

    private function walk(JourneyRun $run, Journey $journey): void
    {
        $version = JourneyVersion::findOrFail($run->version_id);
        $contact = Contact::find($run->contact_id);
        if (! $contact) {
            $this->finish($run, 'exited', $run->current_node, 'The contact was deleted.');

            return;
        }
        $nodes = [];
        foreach ($version->graph['nodes'] as $n) {
            $nodes[$n['id']] = $n;
        }
        $next = [];
        foreach ($version->graph['edges'] as $e) {
            $next[$e['from']][$e['branch']] = $e['to'];
        }
        $node = $run->current_node ?? ($next[(string) $run->trigger_node][''] ?? null);
        for ($i = 0; $i < self::MAX_STEPS; $i++) {
            if ($node === null || ! isset($nodes[$node])) {
                $this->finish($run, 'completed', $run->current_node, null);

                return;
            }
            $run->current_node = $node;
            $t0 = hrtime(true);
            $r = $this->step($run, $journey, $version, $contact, $nodes[$node], JourneyData::input($contact, $run->context, $run->data));
            $ms = (int) round((hrtime(true) - $t0) / 1e6);
            $r = self::onError($r, (array) ($nodes[$node]['config'] ?? []));
            if (isset($r['wait'])) {
                $run->forceFill(['status' => 'waiting', 'armed' => $node, 'wake_at' => $r['wait'], 'waiting_for' => $r['waiting_for'] ?? null, 'signal' => null])->save();

                return;
            }
            $to = isset($r['end']) ? null : ($next[$node][$r['branch'] ?? ''] ?? null);
            $output = JourneyData::clip((array) ($r['output'] ?? []));
            DB::table('journey_steps')->insert(['journey_id' => $journey->id, 'run_id' => $run->id, 'node_id' => $node, 'next_node_id' => $to,
                'outcome' => $r['outcome'] ?? ($r['branch'] ?? 'done'), 'detail' => isset($r['detail']) ? mb_substr((string) $r['detail'], 0, 255) : null,
                'output' => $output ? json_encode($output, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null, 'ms' => $ms,
                'message_id' => $r['message_id'] ?? null, 'created_at' => now()]);
            $run->data = JourneyData::add($run->data, $node, $output); // later steps read it as {{ steps.<id>.… }}
            $run->armed = null;
            $run->signal = null;
            if (isset($r['end'])) {
                $this->finish($run, $r['end'], $node, $r['detail'] ?? null);

                return;
            }
            if ($to === null) {
                $this->finish($run, 'completed', $node, null);

                return;
            }
            $node = $to;
        }
        // Very long chains yield, so one run can't hold a worker.
        $run->forceFill(['status' => 'waiting', 'current_node' => $node, 'wake_at' => now(), 'armed' => null])->save();
        AdvanceJourneyRun::dispatchFor($run->id);
    }

    /**
     * Runs one step. Returns ['branch' => …] to move on, ['wait' => Carbon] to pause, or ['end' => status], with
     * 'output': what the step produced, for later steps and the run's debug view.
     *
     * @param  array<string, mixed>  $n
     * @param  array<string, mixed>  $data  JourneyData::input: what this step sees
     * @return array<string, mixed>
     */
    private function step(JourneyRun $run, Journey $j, JourneyVersion $v, Contact $contact, array $n, array $data): array
    {
        $c = (array) ($n['config'] ?? []);
        $woken = $run->armed === $n['id'];

        switch ($n['type']) {
            case 'wait':
                if ($woken) {
                    return ['outcome' => 'waited', 'output' => ['waited' => $c['amount'].' '.$c['unit'], 'until' => now()->toIso8601ZuluString()]];
                }

                return ['wait' => now()->addSeconds(JourneyGraph::seconds($c))];
            case 'window':
                if ($woken) {
                    return ['outcome' => 'in_window', 'output' => ['in_window' => true, 'at' => now()->toIso8601ZuluString()]];
                }
                $at = $this->nextWindow($c, $this->timezone($contact, $j));

                return $at->lte(now()) ? ['outcome' => 'in_window', 'output' => ['in_window' => true, 'at' => now()->toIso8601ZuluString()]] : ['wait' => $at];
            case 'waitfor':
                if ($woken) {
                    $yes = $run->signal === 'yes';

                    return ['branch' => $yes ? 'yes' : 'no', 'outcome' => $yes ? 'yes' : 'no', 'detail' => $yes ? 'Did it: '.$c['event'] : 'Didn’t happen in time',
                        'output' => ['event' => $c['event'], 'happened' => $yes]];
                }
                $key = $c['node'] ? $c['event'].'#'.$j->id.':'.$c['node'] : $c['event'];

                return ['wait' => now()->addSeconds(JourneyGraph::seconds($c)), 'waiting_for' => $key];
            case 'cond':
                $each = Conditions::explain($contact, $j->id, $c['rules'], SegmentController::customTypes($j->workspace_id), $data);
                $yes = $c['rules']['match'] === 'any' ? in_array(true, $each, true) : ! in_array(false, $each, true);

                return ['branch' => $yes ? 'yes' : 'no', 'output' => ['result' => $yes ? 'yes' : 'no', 'conditions' => $each]];
            case 'split':
                $side = self::side($run->id, $n['id'], (int) $c['percent_a']);

                return ['branch' => $side, 'output' => ['side' => $side]];
            case 'goal':
                return ['end' => 'goal', 'outcome' => 'goal', 'detail' => 'Reached the goal step', 'output' => ['goal' => $c['event']]];
            case 'exit':
                return ['end' => 'completed', 'outcome' => 'exit', 'detail' => 'Left at an exit step', 'output' => ['exited' => true]];
            case 'jump':
                return $this->jump($j, $contact, (string) $c['journey_id']);
            case 'email':
                return $this->email($run, $j, $v, $contact, $n);
            case 'tag':
                $tag = mb_strtolower(trim(JourneyData::resolve((string) $c['tag'], $data)));
                if (! ContactService::validTag($tag)) {
                    return ['outcome' => 'failed', 'detail' => 'The tag came out empty or invalid: “'.mb_substr($tag, 0, 60).'”', 'output' => ['tag' => $tag, 'error' => 'invalid tag']];
                }
                $c['action'] === 'remove' ? $this->contacts->untag($contact, [$tag]) : $this->contacts->tag($contact, [$tag]);

                return ['outcome' => 'done', 'detail' => ($c['action'] === 'remove' ? 'Removed tag ' : 'Added tag ').$tag, 'output' => ['action' => $c['action'], 'tag' => $tag]];
            case 'list':
                if ($c['action'] === 'remove') {
                    DB::table('contact_list')->where('contact_id', $contact->id)->where('list_id', $c['list_id'])->delete();
                } else {
                    $this->contacts->addToLists($contact, [$c['list_id']]);
                }

                return ['outcome' => 'done', 'output' => ['action' => $c['action'], 'list_id' => $c['list_id']]];
            case 'field':
                $value = JourneyData::resolve((string) $c['value'], $data);
                $before = self::fieldValue($contact, (string) $c['key']);
                $this->contacts->setField($contact, (string) $c['key'], $value === '' ? null : $value);

                return ['outcome' => 'done', 'detail' => $c['key'].' = '.($value === '' ? '(cleared)' : mb_substr($value, 0, 200)),
                    'output' => ['field' => $c['key'], 'before' => $before, 'after' => $value === '' ? null : $value]];
            case 'notify':
                return $this->notify($j, $contact, $c, $data);
            case 'webhook':
                return $this->webhook($run->id, $j, $contact, $c + ['node' => $n['id']], $run->context, $data); // node: the delivery id, as before
            case 'ai_write':
            case 'ai_classify':
            case 'ai_extract':
            case 'ai_agent':
            case 'ai_sentiment':
            case 'ai_summarize':
                return $this->ai->run($j, $contact, $n, $data);
            case 'switch':
                return JourneyCore::switch($c, $data);
            case 'filter':
                return JourneyCore::filter($j, $contact, $c, $data);
            case 'set':
                return JourneyCore::set($c, $data);
            case 'datetime':
                return JourneyCore::datetime($c, $data, $this->timezone($contact, $j));
            case 'http':
                return JourneyCore::http($c, $data);
            case 'stop':
                return ['end' => 'failed', 'outcome' => 'stopped', 'detail' => (string) $c['message'], 'output' => ['stopped' => true, 'message' => (string) $c['message']]];
            case 'approval':
                if ($woken) {
                    $answer = $run->signal === 'yes' ? 'approved' : ($run->signal === 'no' ? 'rejected' : 'no_answer');

                    return ['branch' => $answer === 'approved' ? 'yes' : 'no', 'outcome' => $answer === 'approved' ? 'yes' : 'no',
                        'detail' => ['approved' => 'Approved', 'rejected' => 'Rejected', 'no_answer' => 'No answer in time'][$answer], 'output' => ['answer' => $answer, 'approved' => $answer === 'approved', 'at' => now()->toIso8601ZuluString()]];
                }
                $text = self::notifyText($j, $contact, (string) $c['message'], $data);
                $url = JourneyCore::approvalUrl($run, $n['id']);
                $until = now()->addSeconds(JourneyGraph::seconds($c));
                $html = '<p style="margin:22px 0 6px"><a href="'.e($url).'" style="display:inline-block;background:#5B5BF7;color:#fff;text-decoration:none;font-weight:600;padding:11px 20px;border-radius:9px">Review and answer</a></p>'
                    .'<p style="font-size:12px;color:#71717A">Approve or reject on the page that opens. If nobody answers by '.e($until->copy()->setTimezone($j->opts()['timezone'])->format('D j M, H:i')).' ('.e($j->opts()['timezone']).'), it counts as not approved.</p>';
                if ($err = $this->team->send($j, $contact, (array) $c['to'], 'Approve? '.mb_substr(strtok($text, "\n") ?: 'A journey is waiting for you', 0, 110), $text, $html, $text."\n\nAnswer here: $url")) {
                    return ['outcome' => 'failed', 'detail' => $err, 'output' => ['error' => $err]];
                }

                return ['wait' => $until, 'waiting_for' => JourneyCore::APPROVAL_KEY.$run->id.':'.$n['id']];
            default:
                return ['outcome' => 'done'];
        }
    }

    /**
     * A failed step under its "On error" setting: go on (the default), stop the run, or take the "error" connection.
     *
     * @param  array<string, mixed>  $r
     * @param  array<string, mixed>  $c
     * @return array<string, mixed>
     */
    public static function onError(array $r, array $c): array
    {
        if (($r['outcome'] ?? null) !== 'failed' || isset($r['end']) || isset($r['wait'])) {
            return $r;
        }

        return match ($c['on_error'] ?? 'continue') {
            'stop' => ['end' => 'failed'] + $r,
            'branch' => ['branch' => 'error'] + $r,
            default => $r,
        };
    }

    /** A/B side for a run: random across runs, stable for one. */
    public static function side(string $runId, string $nodeId, int $percentA): string
    {
        return (crc32($runId.':'.$nodeId) % 100) < $percentA ? 'a' : 'b';
    }

    public static function fieldValue(Contact $contact, string $key): mixed
    {
        return in_array($key, ['first_name', 'last_name'], true) ? $contact->{$key} : (($contact->fields ?? [])[$key] ?? null);
    }

    /**
     * A notification's text: the old short tags ({{ email }}, {{ journey }}) and any data expression.
     *
     * @param  array<mixed>  $data
     */
    public static function notifyText(Journey $j, Contact $contact, string $message, array $data): string
    {
        $text = strtr($message, ['{{ email }}' => $contact->email, '{{email}}' => $contact->email, '{{ first_name }}' => (string) $contact->first_name,
            '{{first_name}}' => (string) $contact->first_name, '{{ last_name }}' => (string) $contact->last_name, '{{ journey }}' => $j->name]);

        return JourneyData::resolve($text, $data);
    }

    /**
     * The event that started the run fills merge tags in its emails, e.g. {{ creator_name }} or {{ amount }} from
     * an "app.tip_sent" event. Only plain values with merge-tag-safe names; they win over contact fields of the
     * same name, because they describe this particular event.
     *
     * @return array<string, scalar|null>
     */
    public static function triggerVariables(JourneyRun $run): array
    {
        $out = [];
        foreach ((array) ($run->context['properties'] ?? []) as $k => $v) {
            if (is_string($k) && preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $k) && (is_scalar($v) || $v === null) && count($out) < 100) {
                $out[$k] = is_string($v) ? mb_substr($v, 0, 1000) : $v;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $n
     * @return array<string, mixed>
     */
    private function email(JourneyRun $run, Journey $j, JourneyVersion $v, Contact $contact, array $n): array
    {
        $e = $v->emails[$n['id']] ?? null;
        $c = (array) $n['config'];
        if (! $e) {
            return ['outcome' => 'failed', 'detail' => 'This email has no content in the published version.'];
        }
        $hours = (int) ($c['skip_recent_hours'] ?? 0);
        if ($hours > 0 && ($c['type'] ?? 'marketing') === 'marketing' && DB::table('messages')->where('workspace_id', $j->workspace_id)->where('to_email', $contact->email)
            ->where('type', 'marketing')->whereNotIn('status', ['suppressed', 'failed', 'rejected'])->where('created_at', '>=', now()->subHours($hours)->toDateTimeString())->exists()) {
            return ['outcome' => 'skipped', 'detail' => "Got a marketing email in the last $hours hours", 'output' => ['status' => 'skipped', 'reason' => "Got a marketing email in the last $hours hours"]];
        }
        $ws = Workspace::findOrFail($j->workspace_id);
        try {
            $p = $this->mailer->validate($ws, ['from' => $e['from_name'] ? "{$e['from_name']} <{$e['from_email']}>" : $e['from_email'], 'to' => $contact->email,
                'subject' => $e['subject'], 'html' => $e['html'], 'reply_to' => $e['reply_to'] ?: null, 'type' => $c['type'] ?? 'marketing', 'tags' => ['journey' => $j->id],
                'variables' => self::triggerVariables($run)]);
            $p['journey_id'] = $j->id;
            $p['journey_node'] = $n['id'];
            $m = $this->mailer->submit($ws, [$p], 'journey')[0];
        } catch (ApiException $ex) {
            return ['outcome' => 'failed', 'detail' => 'Not sent: '.$ex->getMessage(), 'output' => ['status' => 'failed', 'error' => $ex->getMessage()]];
        }
        $out = ['message_id' => $m->id, 'tracking_code' => $m->tracking_code, 'to' => $m->to_email, 'subject' => $m->subject, 'status' => $m->status];
        if ($m->status === 'suppressed') {
            return ['outcome' => 'skipped', 'detail' => (string) $m->error, 'message_id' => $m->id, 'output' => $out + ['reason' => (string) $m->error]];
        }

        return ['outcome' => 'sent', 'message_id' => $m->id, 'detail' => $m->tracking_code, 'output' => $out];
    }

    /** @return array<string, mixed> */
    private function jump(Journey $from, Contact $contact, string $targetId): array
    {
        $target = Journey::where('workspace_id', $from->workspace_id)->whereKey($targetId)->first();
        $version = $target && $target->status === 'active' && $target->live_version_id ? JourneyVersion::find($target->live_version_id) : null;
        $trigger = $version ? collect($version->graph['nodes'])->first(fn ($n) => $n['type'] === 'trigger') : null;
        if (! $target || ! $version || ! $trigger) {
            return ['end' => 'completed', 'outcome' => 'failed', 'detail' => 'The journey to move to isn’t active, so the contact stayed out.'];
        }
        $run = $this->events->enroll($target, $version, $contact, (string) $trigger['id'], ['event' => 'sendline.moved', 'properties' => ['from' => $from->id]]);

        return ['end' => 'completed', 'outcome' => $run ? 'moved' : 'skipped', 'detail' => ($run ? 'Moved to ' : 'Already been through ').$target->name,
            'output' => ['journey_id' => $target->id, 'journey' => $target->name, 'moved' => (bool) $run]];
    }

    /**
     * @param  array<string, mixed>  $c
     * @param  array<mixed>  $data
     * @return array<string, mixed>
     */
    private function notify(Journey $j, Contact $contact, array $c, array $data): array
    {
        $text = self::notifyText($j, $contact, (string) $c['message'], $data);
        if ($c['channel'] === 'slack') {
            try {
                $res = Http::timeout(10)->connectTimeout(5)->withoutRedirecting()->post((string) $c['slack_url'], ['text' => $text]);

                return $res->successful() ? ['outcome' => 'sent', 'detail' => 'Posted to Slack', 'output' => ['channel' => 'slack', 'message' => $text, 'status' => $res->status()]]
                    : ['outcome' => 'failed', 'detail' => 'Slack answered HTTP '.$res->status(), 'output' => ['channel' => 'slack', 'status' => $res->status()]];
            } catch (Throwable $e) {
                return ['outcome' => 'failed', 'detail' => 'Couldn’t reach Slack', 'output' => ['channel' => 'slack', 'error' => 'Couldn’t reach Slack']];
            }
        }
        if ($err = $this->team->send($j, $contact, (array) $c['to'], mb_substr(strtok($text, "\n") ?: 'Notification', 0, 120), $text, '', $text)) {
            return ['outcome' => 'failed', 'detail' => $err];
        }

        return ['outcome' => 'sent', 'detail' => 'Emailed '.implode(', ', $c['to']), 'output' => ['channel' => 'email', 'to' => $c['to'], 'message' => $text]];
    }

    /**
     * POSTs the contact, the trigger event, the earlier steps' data and the step's own body fields, signed with the
     * journey's secret. The answer becomes the step's output: {status, ok, body} (JSON when it is JSON), so later steps
     * can branch on it or use it, e.g. {{ steps.<id>.body.plan }}.
     *
     * @param  array<string, mixed>  $c
     * @param  array<string, mixed>|null  $context
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function webhook(string $runId, Journey $j, Contact $contact, array $c, ?array $context, array $data, bool $test = false): array
    {
        $url = (string) $c['url'];
        [$problem, $ip] = UrlGuard::vet($url); // connect to exactly the address that was checked
        if ($problem) {
            return ['outcome' => 'failed', 'detail' => $problem, 'output' => ['ok' => false, 'error' => $problem]];
        }
        $fields = [];
        foreach ((array) ($c['fields'] ?? []) as $name => $value) {
            $fields[$name] = JourneyData::resolve((string) $value, $data);
        }
        $body = (string) json_encode(array_filter(['type' => 'journey.step', 'test' => $test ?: null, 'journey' => ['id' => $j->id, 'name' => $j->name], 'run_id' => $runId,
            'contact' => ContactService::present($contact), 'trigger' => $context, 'steps' => $data['steps'] ?: null, 'data' => $fields ?: null,
            'sent_at' => now()->toIso8601ZuluString()], fn ($v) => $v !== null), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $host = (string) parse_url($url, PHP_URL_HOST);
        $port = (int) (parse_url($url, PHP_URL_PORT) ?: 443);
        $t0 = hrtime(true);
        try {
            $res = Http::withHeaders(Signer::headers($j->secret, 'msg_'.$runId.'_'.substr(md5((string) ($c['node'] ?? $url)), 0, 8), time(), $body) + ['User-Agent' => 'MailVesper-Journeys/1.0'])
                ->withBody($body, 'application/json')->timeout(10)->connectTimeout(5)->withoutRedirecting()
                ->withOptions($ip ? ['curl' => [CURLOPT_RESOLVE => ["$host:$port:$ip"]]] : [])->post($url);
        } catch (Throwable $e) {
            $why = str_contains($e->getMessage(), 'timed out') ? 'Timed out after 10 seconds' : 'Couldn’t connect';

            return ['outcome' => 'failed', 'detail' => $why, 'output' => ['ok' => false, 'error' => $why]];
        }
        $raw = substr((string) $res->body(), 0, 65536);
        $json = json_decode($raw, true);
        $out = ['status' => $res->status(), 'ok' => $res->successful(), 'body' => is_array($json) ? $json : mb_substr($raw, 0, 2000), 'ms' => (int) round((hrtime(true) - $t0) / 1e6)];

        return $res->successful() ? ['outcome' => 'sent', 'detail' => 'HTTP '.$res->status(), 'output' => $out]
            : ['outcome' => 'failed', 'detail' => 'Your endpoint answered HTTP '.$res->status(), 'output' => $out];
    }

    /**
     * The next moment inside a send window (now, if we're in it).
     *
     * @param  array<string, mixed>  $c
     */
    public function nextWindow(array $c, string $tz): Carbon
    {
        $now = now()->setTimezone($tz);
        for ($i = 0; $i < 8; $i++) {
            $day = $now->copy()->startOfDay()->addDays($i);
            if (! in_array($day->isoWeekday(), $c['days'], true)) {
                continue;
            }
            [$fh, $fm] = array_map('intval', explode(':', (string) $c['from']));
            [$th, $tm] = array_map('intval', explode(':', (string) $c['to']));
            $start = $day->copy()->setTime($fh, $fm);
            $end = $day->copy()->setTime($th, $tm);
            if ($i === 0 && $now->gte($start) && $now->lt($end)) {
                return now();
            }
            if ($now->lt($start)) {
                return $start->utc();
            }
        }

        return now(); // unreachable with at least one day selected
    }

    public function timezone(Contact $contact, Journey $j): string
    {
        $tz = $contact->fields['timezone'] ?? null;

        return is_string($tz) && in_array($tz, timezone_identifiers_list(), true) ? $tz : $j->opts()['timezone'];
    }

    private function finish(JourneyRun $run, string $status, ?string $node, ?string $reason): void
    {
        $run->forceFill(['status' => $status, 'current_node' => $node, 'armed' => null, 'signal' => null, 'wake_at' => null, 'waiting_for' => null,
            'exit_reason' => $reason ? mb_substr($reason, 0, 200) : null, 'finished_at' => now()])->save();
    }
}
