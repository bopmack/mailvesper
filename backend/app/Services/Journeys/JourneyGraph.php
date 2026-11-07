<?php

namespace App\Services\Journeys;

use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\Contacts\SegmentController;
use App\Models\ContactList;
use App\Models\Form;
use App\Models\Journey;
use App\Models\JourneyEmail;
use App\Models\Segment;
use App\Models\Topic;
use App\Services\Ai\AiClient;
use App\Services\Contacts\ContactService;
use App\Services\Sending\Senders;
use App\Services\Webhooks\UrlGuard;

/**
 * The journey graph: {nodes: [{id, type, x, y, title, config}], edges: [{from, to, branch}]}.
 * Saving a draft only checks the shape (so half-finished steps can be saved); publishing checks that every
 * step is complete and returns normalised configs, which is what the engine runs.
 */
class JourneyGraph
{
    public const MAX_NODES = 100;

    public const MAX_EDGES = 200;

    /** Step type → the branches it can have (empty string = its single "next" connection). */
    public const OUTPUTS = [
        'trigger' => [''], 'email' => [''], 'wait' => [''], 'window' => [''], 'tag' => [''], 'field' => [''], 'list' => [''],
        'notify' => [''], 'webhook' => [''], 'waitfor' => ['yes', 'no'], 'cond' => ['yes', 'no'], 'split' => ['a', 'b'],
        'goal' => [], 'exit' => [], 'jump' => [],
        'ai_write' => [''], 'ai_extract' => [''], 'ai_agent' => [''], 'ai_classify' => ['other'], // Classify also has one branch per category
        'switch' => ['other'], // and one branch per case
        'filter' => [''], 'set' => [''], 'datetime' => [''], 'http' => [''], 'stop' => [], 'approval' => ['yes', 'no'],
        'ai_sentiment' => ['positive', 'neutral', 'negative'], 'ai_summarize' => [''],
        // AI sub-nodes (n8n's cluster nodes): they plug in under an AI step through their own kind of connection.
        'ai_model' => ['ai_model'], 'ai_memory' => ['ai_memory'], 'ai_tool' => ['ai_tool'], 'ai_parser' => ['ai_parser'],
    ];

    /** Sub-node connection → [the steps it plugs into, how many each can take]. */
    public const SUB = ['ai_model' => [JourneyAi::TYPES, 1], 'ai_memory' => [['ai_agent'], 1], 'ai_tool' => [['ai_agent'], 10], 'ai_parser' => [['ai_agent'], 1]];

    /** What an AI agent's tools can be (each one a Tool sub-node). */
    public const TOOL_KINDS = ['contact', 'activity', 'tags', 'fields', 'lists', 'http', 'think', 'calculator', 'notify'];

    /** Default names, for messages about a step that has none. */
    public const NAMES = ['ai_model' => 'Chat Model', 'ai_memory' => 'Simple Memory', 'ai_tool' => 'Tool', 'ai_parser' => 'Output Parser', 'switch' => 'Switch', 'filter' => 'Filter', 'set' => 'Edit fields', 'datetime' => 'Date & Time', 'http' => 'HTTP Request', 'stop' => 'Stop and error', 'approval' => 'Human approval',
        'ai_sentiment' => 'Sentiment with AI', 'ai_summarize' => 'Summarize with AI', 'ai_write' => 'Write with AI', 'ai_classify' => 'Classify with AI', 'ai_extract' => 'Extract with AI', 'ai_agent' => 'AI agent', 'cond' => 'If / else', 'waitfor' => 'Wait for event', 'split' => 'A/B split'];

    /** Steps that can fail (an email not sent, an endpoint down, the AI unavailable…): they have an "On error" setting. */
    public const FAILABLE = ['email', 'tag', 'field', 'list', 'notify', 'webhook', 'ai_write', 'ai_classify', 'ai_extract', 'ai_agent', 'http', 'datetime', 'approval', 'ai_sentiment', 'ai_summarize'];

    /** continue: go on to the next step (the default) · stop: end the run as failed · branch: take the step's "error" connection. */
    public const ON_ERROR = ['continue', 'stop', 'branch'];

    /** Triggers that work today (the editor shows the rest of the catalogue as "coming soon"). */
    public const TRIGGERS = ['contact_created', 'list_added', 'tag_added', 'field_changed', 'segment_joined', 'segment_left', 'form_submitted', 'optin_confirmed',
        'topic_unsubscribed', 'event',
        'inactive', 'price_drop', 'back_in_stock', 'email_opened', 'email_clicked', 'email_replied', 'went_quiet', 'date', 'schedule', 'webhook'];

    /** Triggers the scheduler checks (TriggerScan, journeys:dates) instead of an event starting them. */
    public const SCANNED = ['date', 'schedule', 'went_quiet', 'inactive'];

    /** Event names MailVesper emits itself; the track API can't use these prefixes. */
    public const RESERVED = ['contact.', 'email.', 'sendline.', 'webhook:'];

    public const UNITS = ['minutes' => 60, 'hours' => 3600, 'days' => 86400];

    /**
     * The connections a step can have: its type's, a Classify step's categories, and "error" when it's set to take one.
     *
     * @param  array<string, mixed>  $n
     * @return list<string>
     */
    public static function outputs(array $n): array
    {
        $c = is_array($n['config'] ?? null) ? $n['config'] : [];
        $out = self::OUTPUTS[$n['type']] ?? [];
        if ($n['type'] === 'ai_classify' || $n['type'] === 'switch') {
            $ids = [];
            foreach (array_slice((array) ($c[$n['type'] === 'switch' ? 'cases' : 'categories'] ?? []), 0, 10) as $k) {
                $id = is_array($k) ? ($k['id'] ?? null) : null;
                if (is_string($id) && preg_match('/^[a-z0-9]{1,12}$/', $id) && ! in_array($id, ['other', 'error'], true)) {
                    $ids[] = $id;
                }
            }
            $out = array_merge(array_values(array_unique($ids)), $out);
        }
        if (in_array($n['type'], self::FAILABLE, true) && ($c['on_error'] ?? null) === 'branch') {
            $out[] = 'error';
        }

        return $out;
    }

    /**
     * Shape check for a draft save.
     *
     * @return array{nodes: list<array<string, mixed>>, edges: list<array{from: string, to: string, branch: string}>}
     */
    public static function sanitize(mixed $graph): array
    {
        $bad = fn (string $m) => new ApiException('invalid_graph', $m, 422);
        if (! is_array($graph) || ! is_array($graph['nodes'] ?? null) || ! is_array($graph['edges'] ?? null)) {
            throw $bad('The journey couldn’t be read. Reload the editor and try again.');
        }
        if (count($graph['nodes']) > self::MAX_NODES || count($graph['edges']) > self::MAX_EDGES) {
            throw $bad('A journey can have up to '.self::MAX_NODES.' steps.');
        }
        $nodes = [];
        foreach ($graph['nodes'] as $n) {
            $id = is_array($n) ? ($n['id'] ?? null) : null;
            if (! is_string($id) || ! preg_match('/^[A-Za-z0-9_-]{1,24}$/', $id) || isset($nodes[$id])) {
                throw $bad('Two steps share an id, or a step has no id.');
            }
            $type = $n['type'] ?? null;
            if (! is_string($type) || ! array_key_exists($type, self::OUTPUTS)) {
                throw $bad('Unknown step type.');
            }
            $config = $n['config'] ?? [];
            if (! is_array($config) || strlen((string) json_encode($config)) > 20000) {
                throw $bad('A step’s settings are too large.');
            }
            $nodes[$id] = ['id' => $id, 'type' => $type, 'x' => (int) round((float) ($n['x'] ?? 0)), 'y' => (int) round((float) ($n['y'] ?? 0)),
                'title' => mb_substr(trim((string) ($n['title'] ?? '')), 0, 120), 'config' => $config];
        }
        $edges = [];
        $seen = [];
        foreach ($graph['edges'] as $e) {
            $from = is_array($e) ? ($e['from'] ?? null) : null;
            $to = is_array($e) ? ($e['to'] ?? null) : null;
            $branch = is_array($e) ? (string) ($e['branch'] ?? '') : '';
            if (! is_string($from) || ! is_string($to) || ! isset($nodes[$from], $nodes[$to]) || $from === $to) {
                throw $bad('A connection points to a step that doesn’t exist.');
            }
            if ($nodes[$to]['type'] === 'trigger') {
                throw $bad('Nothing can connect into a trigger.');
            }
            $name = fn (string $id) => '“'.($nodes[$id]['title'] ?: (self::NAMES[$nodes[$id]['type']] ?? $nodes[$id]['type'])).'”';
            if (isset(self::SUB[$nodes[$from]['type']])) { // a sub-node plugs in under an AI step
                [$targets, $max] = self::SUB[$nodes[$from]['type']];
                if ($branch !== $nodes[$from]['type'] || ! in_array($nodes[$to]['type'], $targets, true)) {
                    throw $bad($name($from).' can only plug into '.($nodes[$from]['type'] === 'ai_model' ? 'an AI step.' : 'an AI agent.'));
                }
                if (isset($seen["$from|$branch|$to"])) {
                    throw $bad($name($from).' is already plugged into '.$name($to).'.');
                }
                $seen["$from|$branch|$to"] = true;
                $seen["n|$to|$branch"] = ($seen["n|$to|$branch"] ?? 0) + 1;
                if ($seen["n|$to|$branch"] > $max) {
                    throw $bad($name($to).' can have '.($max === 1 ? 'one '.strtolower(self::NAMES[$branch]) : "up to $max tools").'.');
                }
                $edges[] = ['from' => $from, 'to' => $to, 'branch' => $branch];

                continue;
            }
            if (isset(self::SUB[$nodes[$to]['type']])) {
                throw $bad('Steps can’t connect into '.$name($to).': it plugs in under an AI step.');
            }
            if (! in_array($branch, self::outputs($nodes[$from]), true)) {
                throw $bad('“'.($nodes[$from]['title'] ?: $nodes[$from]['type']).'” can’t have that connection.');
            }
            if (isset($seen["$from|$branch"])) {
                throw $bad('“'.($nodes[$from]['title'] ?: $nodes[$from]['type']).'” already has a '.($branch ?: 'next').' connection. Remove it first.');
            }
            $seen["$from|$branch"] = true;
            $edges[] = ['from' => $from, 'to' => $to, 'branch' => $branch];
        }

        return ['nodes' => array_values($nodes), 'edges' => $edges];
    }

    /**
     * Everything that stops this journey going live. Empty = ready.
     *
     * @return list<array{node: string|null, message: string}>
     */
    public function problems(Journey $j): array
    {
        try {
            $this->compile($j);
        } catch (JourneyProblems $e) {
            return $e->problems;
        }

        return [];
    }

    /**
     * Strict check + normalisation for publishing.
     *
     * @return array{graph: array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}, emails: array<string, array<string, mixed>>, triggers: list<array<string, mixed>>}
     *
     * @throws JourneyProblems
     */
    public function compile(Journey $j): array
    {
        $g = self::sanitize($j->graph);
        $problems = [];
        $add = function (?string $node, string $m) use (&$problems) {
            $problems[] = ['node' => $node, 'message' => $m];
        };
        $byId = [];
        foreach ($g['nodes'] as $n) {
            $byId[$n['id']] = $n;
        }
        $out = [];
        foreach ($g['edges'] as $e) {
            $out[$e['from']][$e['branch']] = $e['to'];
        }
        $triggers = array_filter($g['nodes'], fn ($n) => $n['type'] === 'trigger');
        if (! $triggers) {
            $add(null, 'Add a trigger: what starts this journey?');
        }

        // Reachability from the triggers, and no loops (a contact must always reach an end).
        $reach = [];
        $state = [];
        $cycle = false;
        $visit = function (string $id) use (&$visit, &$reach, &$state, &$cycle, $out) {
            if (($state[$id] ?? 0) === 1) {
                $cycle = true;

                return;
            }
            if (($state[$id] ?? 0) === 2) {
                return;
            }
            $state[$id] = 1;
            $reach[$id] = true;
            foreach ($out[$id] ?? [] as $to) {
                $visit($to);
            }
            $state[$id] = 2;
        };
        foreach ($triggers as $t) {
            $visit($t['id']);
        }
        if ($cycle) {
            $add(null, 'The journey loops back on itself. Connections must always lead forward to an end.');
        }

        $customTypes = SegmentController::customTypes($j->workspace_id);
        $emails = [];
        $index = [];
        $nodes = [];
        foreach ($g['nodes'] as $n) {
            $label = '“'.($n['title'] ?: (self::NAMES[$n['type']] ?? ucfirst($n['type']))).'”';
            if (isset(self::SUB[$n['type']])) { // a sub-node counts when it plugs into a step that runs
                if (! collect($g['edges'])->contains(fn ($e) => $e['from'] === $n['id'] && isset($reach[$e['to']]))) {
                    $add($n['id'], "$label isn’t plugged into an AI step that runs. Connect it under one, or delete it.");
                }
            } elseif (! isset($reach[$n['id']]) && $n['type'] !== 'goal') { // a goal also works unconnected: "leave when this happens"
                $add($n['id'], "$label isn’t connected to a trigger. Connect it or delete it.");
            }
            $n['attached'] = self::attached($g, $n['id']);
            if ($n['type'] === 'trigger' && empty($out[$n['id']][''])) {
                $add($n['id'], "$label doesn’t lead anywhere. Connect it to the first step.");
            }
            try {
                $n['config'] = $this->config($j, $n, $customTypes, $byId);
                if ($n['type'] === 'trigger') {
                    $index[] = ['node' => $n['id']] + $n['config'];
                }
                if ($n['type'] === 'email') {
                    $emails[$n['id']] = $this->email($j, $n);
                }
            } catch (ApiException $e) {
                $add($n['id'], "$label: ".$e->getMessage());
            }
            unset($n['attached']);
            $nodes[] = $n;
        }
        if ($problems) {
            throw new JourneyProblems($problems);
        }

        return ['graph' => ['nodes' => $nodes, 'edges' => $g['edges']], 'emails' => $emails, 'triggers' => $index];
    }

    /**
     * One step of the draft, checked and normalised as publishing would (for testing a step on its own).
     *
     * @return array<string, mixed> the step with its normalised config
     *
     * @throws ApiException when the step isn't complete
     */
    public function draftStep(Journey $j, string $nodeId): array
    {
        $g = self::sanitize($j->graph);
        $byId = [];
        foreach ($g['nodes'] as $n) {
            $byId[$n['id']] = $n;
        }
        $n = $byId[$nodeId] ?? throw new ApiException('not_found', 'That step isn’t in this journey any more.', 404);
        $n['attached'] = self::attached($g, $nodeId);
        $n['config'] = $this->config($j, $n, SegmentController::customTypes($j->workspace_id), $byId);
        unset($n['attached']);

        return $n;
    }

    /**
     * The sub-nodes plugged in under a step, by kind.
     *
     * @param  array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}  $g
     * @return array<string, list<array<string, mixed>>>
     */
    public static function attached(array $g, string $id): array
    {
        $out = ['ai_model' => [], 'ai_memory' => [], 'ai_tool' => [], 'ai_parser' => []];
        $byId = array_column($g['nodes'], null, 'id');
        foreach ($g['edges'] as $e) {
            if ($e['to'] === $id && isset($out[$e['branch']], $byId[$e['from']])) {
                $out[$e['branch']][] = $byId[$e['from']];
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $n
     * @param  array<string, string>  $customTypes
     * @param  array<string, array<string, mixed>>  $byId
     * @return array<string, mixed>
     */
    private function config(Journey $j, array $n, array $customTypes, array $byId): array
    {
        $o = $this->settings($j, $n, $customTypes, $byId);
        if (in_array($n['type'], self::FAILABLE, true)) {
            $mode = $n['config']['on_error'] ?? 'continue';
            $o['on_error'] = in_array($mode, self::ON_ERROR, true) ? $mode : 'continue';
        }

        return $o;
    }

    /**
     * @param  array<string, mixed>  $n
     * @param  array<string, string>  $customTypes
     * @param  array<string, array<string, mixed>>  $byId
     * @return array<string, mixed>
     */
    private function settings(Journey $j, array $n, array $customTypes, array $byId): array
    {
        $c = $n['config'];
        $need = fn (string $m) => new ApiException('incomplete', $m, 422);
        $str = fn (string $k, int $max = 255) => is_string($c[$k] ?? null) ? mb_substr(trim($c[$k]), 0, $max) : '';
        $int = fn (string $k, int $min, int $max, int $default) => max($min, min($max, (int) ($c[$k] ?? $default)));
        $duration = function (string $what) use ($c, $need) {
            $amount = (int) ($c['amount'] ?? 0);
            $unit = (string) ($c['unit'] ?? '');
            if (! isset(self::UNITS[$unit]) || $amount < 1 || $amount * self::UNITS[$unit] > 365 * 86400) {
                throw $need("set how long to $what (up to a year).");
            }

            return ['amount' => $amount, 'unit' => $unit];
        };
        $rules = fn (mixed $r) => is_array($r) && ! empty($r['conditions']) ? Conditions::validate($r, $customTypes, $byId) : null;

        switch ($n['type']) {
            case 'trigger':
                $t = (string) ($c['trigger'] ?? '');
                if (! in_array($t, self::TRIGGERS, true)) {
                    throw $need('choose what starts the journey.');
                }
                $o = ['trigger' => $t, 'event' => self::eventFor($t, $c), 'filter' => $rules($c['filter'] ?? null)];
                if ($t === 'list_added') {
                    $o['list_id'] = $str('list_id', 26);
                    if (! ContactList::where('workspace_id', $j->workspace_id)->whereKey($o['list_id'])->exists()) {
                        throw $need('choose a list.');
                    }
                }
                if ($t === 'tag_added') {
                    $o['tag'] = mb_strtolower($str('tag', 64));
                    if (! ContactService::validTag($o['tag'])) {
                        throw $need('enter the tag.');
                    }
                }
                if ($t === 'field_changed') {
                    $o['field'] = $str('field', 64);
                    $o['to'] = $str('to', 255) ?: null;
                    if (! in_array($o['field'], ['first_name', 'last_name'], true) && ! ContactService::validKey($o['field'])) {
                        throw $need('choose the field to watch.');
                    }
                }
                if ($t === 'segment_joined' || $t === 'segment_left') {
                    $o['segment_id'] = $str('segment_id', 26);
                    $seg = Segment::where('workspace_id', $j->workspace_id)->find($o['segment_id']);
                    if (! $seg) {
                        throw $need('choose a segment.');
                    }
                    if ($seg->site_connection_id) { // a site's people are read live, not stored, so nobody can "join" or "leave"
                        throw $need('choose a segment of your contacts (a connected site’s segments are read live and can’t start journeys).');
                    }
                }
                if ($t === 'form_submitted' || $t === 'optin_confirmed') {
                    $o['form_id'] = $str('form_id', 26) ?: null; // none = any form
                    if ($o['form_id'] && ! Form::where('workspace_id', $j->workspace_id)->whereKey($o['form_id'])->exists()) {
                        throw $need('choose a form, or any form.');
                    }
                }
                if ($t === 'price_drop') {
                    $o['min_percent'] = $int('min_percent', 0, 90, 0);
                }
                if ($t === 'topic_unsubscribed') {
                    $o['topic_id'] = $str('topic_id', 26) ?: null; // none = any topic
                    if ($o['topic_id'] && ! Topic::where('workspace_id', $j->workspace_id)->whereKey($o['topic_id'])->exists()) {
                        throw $need('choose a topic, or any topic.');
                    }
                }
                if ($t === 'went_quiet' || $t === 'inactive') {
                    $days = (int) ($c['days'] ?? 0);
                    if ($days < 1 || $days > 365) {
                        throw $need('set how many days (1 to 365).');
                    }
                    $o['days'] = $days;
                }
                if ($t === 'inactive') {
                    $o['activity'] = $str('activity', 100);
                    if (! self::validEvent($o['activity'])) {
                        throw $need('enter the event your app sends when someone is active, like app_opened.');
                    }
                }
                if ($t === 'schedule') {
                    $o['every'] = in_array($c['every'] ?? null, ['day', 'week', 'month'], true) ? $c['every'] : throw $need('choose every day, week or month.');
                    $o['weekday'] = $o['every'] === 'week' ? $int('weekday', 1, 7, 1) : null;
                    $o['monthday'] = $o['every'] === 'month' ? $int('monthday', 1, 31, 1) : null;
                    $o['hour'] = $int('hour', 0, 23, 9);
                }
                if ($t === 'event') {
                    if (! self::validEvent($o['event'])) {
                        throw $need('enter the event name your app sends, like trial_started.');
                    }
                }
                if ($t === 'email_clicked') {
                    $o['url_contains'] = $str('url_contains', 255) ?: null;
                }
                if ($t === 'date') {
                    $o['field'] = $str('field', 64);
                    if ($o['field'] !== 'created_at' && ! ContactService::validKey($o['field'])) {
                        throw $need('choose the date field.');
                    }
                    $o['offset_days'] = $int('offset_days', -365, 365, 0);
                    $o['hour'] = $int('hour', 0, 23, 9);
                    $o['yearly'] = (bool) ($c['yearly'] ?? in_array($o['field'], ['birthday', 'created_at'], true));
                }

                return $o;
            case 'email':
                return ['skip_recent_hours' => $int('skip_recent_hours', 0, 72, 0), 'type' => ($c['type'] ?? 'marketing') === 'transactional' ? 'transactional' : 'marketing'];
            case 'wait':
                return $duration('wait');
            case 'window':
                $days = array_values(array_unique(array_filter(array_map('intval', (array) ($c['days'] ?? [])), fn ($d) => $d >= 1 && $d <= 7)));
                sort($days);
                $from = $str('from', 5);
                $to = $str('to', 5);
                if (! $days || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $from) || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $to) || $from >= $to) {
                    throw $need('pick at least one day and a time range like 09:00–18:00.');
                }

                return ['days' => $days, 'from' => $from, 'to' => $to];
            case 'waitfor':
                $event = $str('event', 100);
                $node = $str('node', 24) ?: null;
                if (! self::validEvent($event) && ! in_array($event, ['email.opened', 'email.clicked', 'email.replied'], true)) {
                    throw $need('choose the event to wait for.');
                }
                if ($node && (($byId[$node]['type'] ?? null) !== 'email')) {
                    throw $need('the email it watches isn’t in this journey any more.');
                }

                return ['event' => $event, 'node' => str_starts_with($event, 'email.') ? $node : null] + $duration('wait for it');
            case 'cond':
                $r = $rules($c['rules'] ?? null) ?? throw $need('add at least one condition.');

                return ['rules' => $r];
            case 'split':
                return ['percent_a' => $int('percent_a', 1, 99, 50)];
            case 'goal':
                $event = $str('event', 100);
                if (! self::validEvent($event)) {
                    throw $need('enter the event that means success, like plan_upgraded.');
                }

                return ['event' => $event];
            case 'tag':
                $tag = $str('tag', 120);
                $tag = JourneyData::uses($tag) ? $tag : mb_strtolower($tag); // {{ trigger.properties.plan }}: checked when it runs
                if (! JourneyData::uses($tag) && ! ContactService::validTag($tag)) {
                    throw $need('enter the tag.');
                }

                return ['action' => ($c['action'] ?? 'add') === 'remove' ? 'remove' : 'add', 'tag' => $tag];
            case 'field':
                $key = $str('key', 64);
                if (! in_array($key, ['first_name', 'last_name'], true) && ! ContactService::validKey($key)) {
                    throw $need('choose the field to update.');
                }

                return ['key' => $key, 'value' => $str('value', 1000)];
            case 'list':
                $id = $str('list_id', 26);
                if (! ContactList::where('workspace_id', $j->workspace_id)->whereKey($id)->exists()) {
                    throw $need('choose a list.');
                }

                return ['action' => ($c['action'] ?? 'add') === 'remove' ? 'remove' : 'add', 'list_id' => $id];
            case 'notify':
                $channel = ($c['channel'] ?? 'email') === 'slack' ? 'slack' : 'email';
                $message = $str('message', 2000);
                if ($message === '') {
                    throw $need('write the message.');
                }
                if ($channel === 'slack') {
                    $url = $str('slack_url', 500);
                    if (! preg_match('#^https://hooks\.slack\.com/services/[A-Za-z0-9/_-]+$#', $url)) {
                        throw $need('paste a Slack incoming-webhook URL (https://hooks.slack.com/services/…).');
                    }

                    return ['channel' => 'slack', 'slack_url' => $url, 'message' => $message];
                }
                $to = array_values(array_filter(array_map(fn ($e) => mb_strtolower(trim((string) $e)), (array) ($c['to'] ?? [])), fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
                if (! $to || count($to) > 5) {
                    throw $need('add up to 5 team addresses.');
                }

                return ['channel' => 'email', 'to' => $to, 'message' => $message];
            case 'webhook':
                $url = $str('url', 500);
                if ($e = UrlGuard::problem($url)) {
                    throw $need($e);
                }
                // Extra body fields, n8n style: {"name": "plan", "value": "{{ trigger.properties.plan }}"} → "data": {"plan": "pro"}.
                $fields = [];
                foreach (array_slice(array_values((array) ($c['fields'] ?? [])), 0, 21) as $i => $fd) {
                    $name = is_array($fd) && is_string($fd['name'] ?? null) ? trim($fd['name']) : '';
                    $value = is_array($fd) && is_scalar($fd['value'] ?? null) ? mb_substr((string) $fd['value'], 0, 1000) : '';
                    if ($name === '' && $value === '') {
                        continue;
                    }
                    if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $name) || isset($fields[$name])) {
                        throw $need('body field '.($i + 1).' needs a unique name (letters, digits and _).');
                    }
                    $fields[$name] = $value;
                }
                if (count($fields) > 20) {
                    throw $need('send up to 20 body fields.');
                }

                return ['url' => $url, 'fields' => $fields];
            case 'jump':
                $id = $str('journey_id', 26);
                if ($id === $j->id || ! Journey::where('workspace_id', $j->workspace_id)->whereKey($id)->where('status', '!=', 'archived')->exists()) {
                    throw $need('choose another journey to move contacts to.');
                }

                return ['journey_id' => $id];
            case 'switch':
            case 'filter':
            case 'set':
            case 'datetime':
            case 'http':
            case 'stop':
            case 'approval':
                return $this->core($n['type'], $c, $need, $str, $int, $duration, $rules);
            case 'ai_write':
            case 'ai_classify':
            case 'ai_extract':
            case 'ai_agent':
            case 'ai_sentiment':
            case 'ai_summarize':
                if ($why = app(JourneyAi::class)->unavailable()) {
                    throw $need($why);
                }
                $att = (array) ($n['attached'] ?? []) + ['ai_model' => [], 'ai_memory' => [], 'ai_tool' => [], 'ai_parser' => []];
                $sub = function (array $node) use ($j, $need) { // a plugged-in sub-node's settings; its own problem is shown on it too
                    try {
                        return $this->sub($j, $node);
                    } catch (ApiException $e) {
                        throw $need('its “'.($node['title'] ?: self::NAMES[$node['type']]).'” isn’t ready: '.$e->getMessage());
                    }
                };
                $o = $this->ai($j, $n['type'], $c, $need, $str, $int);
                $o['llm'] = $this->llm($att, $o, $sub);

                return $n['type'] === 'ai_agent' ? $this->agentParts($att, $o, $sub, $need) : $o;
            case 'ai_model':
            case 'ai_memory':
            case 'ai_tool':
            case 'ai_parser':
                return $this->sub($j, $n);
            default:
                return [];
        }
    }

    /**
     * The n8n core nodes that fit one-contact journeys: Switch, Filter, Edit fields, Date & Time, HTTP Request,
     * Stop and error, Human approval.
     *
     * @param  array<string, mixed>  $c
     * @return array<string, mixed>
     */
    private function core(string $type, array $c, callable $need, callable $str, callable $int, callable $duration, callable $rules): array
    {
        $name = '/^[A-Za-z_][A-Za-z0-9_]{0,63}$/';
        $pairs = function (string $key, int $max, string $what, bool $headers = false) use ($c, $need, $name) {
            $out = [];
            foreach (array_values((array) ($c[$key] ?? [])) as $i => $f) {
                if (! is_array($f)) {
                    continue;
                }
                $k = is_string($f['name'] ?? null) ? trim($f['name']) : '';
                $v = is_scalar($f['value'] ?? null) ? mb_substr((string) $f['value'], 0, 1000) : '';
                if ($k === '' && $v === '') {
                    continue;
                }
                $ok = $headers ? preg_match('/^[A-Za-z0-9-]{1,64}$/', $k) && ! in_array(strtolower($k), ['host', 'content-length', 'transfer-encoding', 'connection'], true) : preg_match($name, $k);
                if (! $ok || isset($out[$k])) {
                    throw $need("$what ".($i + 1).' needs a unique, valid name.');
                }
                $out[$k] = $v;
            }
            if (count($out) > $max) {
                throw $need("use up to $max {$what}s.");
            }

            return $out;
        };
        switch ($type) {
            case 'switch':
                $value = $str('value', 1000);
                if ($value === '') {
                    throw $need('choose the value to route on, e.g. {{ trigger.properties.plan }}.');
                }
                $cases = [];
                foreach (array_values((array) ($c['cases'] ?? [])) as $i => $k) {
                    if (! is_array($k)) {
                        continue;
                    }
                    $id = is_string($k['id'] ?? null) ? $k['id'] : '';
                    $op = in_array($k['op'] ?? null, Conditions::SWITCH_OPS, true) ? $k['op'] : 'is';
                    $v = is_scalar($k['value'] ?? null) ? mb_substr(trim((string) $k['value']), 0, 255) : '';
                    if (! preg_match('/^[a-z0-9]{1,12}$/', $id) || in_array($id, ['other', 'error'], true)) {
                        throw $need('the cases couldn’t be read. Reload the editor.');
                    }
                    if ($v === '' && ! in_array($op, ['is_set', 'is_not_set'], true)) {
                        throw $need('case '.($i + 1).' needs a value to compare with.');
                    }
                    if (in_array($op, ['gt', 'gte', 'lt', 'lte'], true) && ! is_numeric($v)) {
                        throw $need('case '.($i + 1).' compares numbers: enter a number.');
                    }
                    $cases[] = ['id' => $id, 'op' => $op, 'value' => $v, 'name' => is_string($k['name'] ?? null) ? mb_substr(trim($k['name']), 0, 60) : ''];
                }
                if (! $cases || count($cases) > 10) {
                    throw $need('add 1 to 10 cases.');
                }

                return ['value' => $value, 'cases' => $cases];
            case 'filter':
                return ['rules' => $rules($c['rules'] ?? null) ?? throw $need('add at least one condition.')];
            case 'set':
                $fields = [];
                foreach (array_values((array) ($c['fields'] ?? [])) as $i => $f) {
                    if (! is_array($f)) {
                        continue;
                    }
                    $k = is_string($f['name'] ?? null) ? trim($f['name']) : '';
                    if ($k === '') {
                        continue;
                    }
                    if (! preg_match($name, $k) || isset($fields[$k])) {
                        throw $need('field '.($i + 1).' needs a unique name (letters, digits and _).');
                    }
                    $t = $f['type'] ?? 'text';
                    $fields[$k] = ['name' => $k, 'value' => is_scalar($f['value'] ?? null) ? mb_substr((string) $f['value'], 0, 2000) : '', 'type' => in_array($t, JourneyAi::FIELD_TYPES, true) ? $t : 'text'];
                }
                if (! $fields || count($fields) > 20) {
                    throw $need('add 1 to 20 fields.');
                }

                return ['fields' => array_values($fields)];
            case 'datetime':
                $op = in_array($c['op'] ?? null, JourneyCore::DATE_OPS, true) ? $c['op'] : throw $need('choose what to do with the date.');
                $o = ['op' => $op, 'value' => $op === 'now' ? '' : $str('value', 500), 'format' => in_array($c['format'] ?? null, array_keys(JourneyCore::FORMATS), true) ? $c['format'] : 'iso'];
                if ($op !== 'now' && $o['value'] === '') {
                    throw $need('choose the date to use, e.g. {{ contact.fields.renews_at }}.');
                }
                if (in_array($op, ['add', 'subtract'], true)) {
                    $o['amount'] = $int('amount', 1, 3650, 1);
                    $o['unit'] = in_array($c['unit'] ?? null, array_keys(JourneyCore::DATE_UNITS), true) ? $c['unit'] : 'days';
                }
                if ($op === 'diff') {
                    $o['unit'] = in_array($c['unit'] ?? null, array_keys(JourneyCore::DATE_UNITS), true) ? $c['unit'] : 'days';
                }

                return $o;
            case 'http':
                $method = in_array($c['method'] ?? null, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true) ? $c['method'] : 'GET';
                $url = $str('url', 1000);
                if (! JourneyData::uses($url) && ($e = UrlGuard::problem($url))) {
                    throw $need($e);
                }
                if (! str_starts_with($url, 'https://')) {
                    throw $need('the URL must start with https://.');
                }

                return ['method' => $method, 'url' => $url, 'query' => $pairs('query', 20, 'query parameter'), 'headers' => $pairs('headers', 10, 'header', true),
                    'body' => in_array($method, ['POST', 'PUT', 'PATCH'], true) ? $pairs('body', 30, 'body field') : []];
            case 'stop':
                return ['message' => $str('message', 255) ?: 'Stopped by a “Stop and error” step.'];
            default: // approval
                $to = array_values(array_filter(array_map(fn ($e) => mb_strtolower(trim((string) $e)), (array) ($c['to'] ?? [])), fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
                if (! $to || count($to) > 5) {
                    throw $need('add up to 5 teammates who can approve.');
                }
                $message = $str('message', 2000);
                if ($message === '') {
                    throw $need('write what they’re approving.');
                }

                return ['to' => $to, 'message' => $message] + $duration('wait for an answer');
        }
    }

    /**
     * An AI step's settings.
     *
     * @param  array<string, mixed>  $c
     * @param  callable(string): ApiException  $need
     * @param  callable(string, int=): string  $str
     * @param  callable(string, int, int, int): int  $int
     * @return array<string, mixed>
     */
    private function ai(Journey $j, string $type, array $c, callable $need, callable $str, callable $int): array
    {
        $name = '/^[A-Za-z_][A-Za-z0-9_]{0,63}$/';
        switch ($type) {
            case 'ai_write':
                $prompt = $str('prompt', 4000);
                if ($prompt === '') {
                    throw $need('write what the AI should write.');
                }

                return ['prompt' => $prompt];
            case 'ai_classify':
                $cats = [];
                $seen = [];
                foreach (array_values((array) ($c['categories'] ?? [])) as $i => $k) {
                    if (! is_array($k)) {
                        continue;
                    }
                    $label = is_string($k['name'] ?? null) ? mb_substr(trim($k['name']), 0, 60) : '';
                    $id = is_string($k['id'] ?? null) ? $k['id'] : '';
                    if ($label === '' && trim((string) ($k['description'] ?? '')) === '') {
                        continue;
                    }
                    if ($label === '' || mb_strtolower($label) === 'other' || isset($seen[mb_strtolower($label)])) {
                        throw $need('category '.($i + 1).' needs its own name (not “Other”, which is always there).');
                    }
                    if (! preg_match('/^[a-z0-9]{1,12}$/', $id) || in_array($id, ['other', 'error'], true)) {
                        throw $need('the categories couldn’t be read. Reload the editor.');
                    }
                    $seen[mb_strtolower($label)] = true;
                    $cats[] = ['id' => $id, 'name' => $label, 'description' => is_string($k['description'] ?? null) ? mb_substr(trim($k['description']), 0, 300) : ''];
                }
                if (count($cats) < 2 || count($cats) > 8) {
                    throw $need('add 2 to 8 categories.');
                }

                return ['input' => $str('input', 2000), 'categories' => $cats, 'instructions' => $str('instructions', 1000)];
            case 'ai_extract':
                $input = $str('input', 2000);
                if ($input === '') {
                    throw $need('choose the text to read, e.g. {{ trigger.properties.message }}.');
                }
                $fields = [];
                foreach (array_values((array) ($c['fields'] ?? [])) as $i => $f) {
                    if (! is_array($f)) {
                        continue;
                    }
                    $key = is_string($f['name'] ?? null) ? trim($f['name']) : '';
                    if ($key === '') {
                        continue;
                    }
                    if (! preg_match($name, $key) || isset($fields[$key]) || in_array($key, ['model', 'tokens'], true)) {
                        throw $need('field '.($i + 1).' needs a unique name (letters, digits and _; not model or tokens).');
                    }
                    $t = $f['type'] ?? 'text';
                    $fields[$key] = ['name' => $key, 'type' => in_array($t, JourneyAi::FIELD_TYPES, true) ? $t : 'text',
                        'description' => is_string($f['description'] ?? null) ? mb_substr(trim($f['description']), 0, 300) : ''];
                }
                if (! $fields || count($fields) > 15) {
                    throw $need('add 1 to 15 fields to take out.');
                }

                return ['input' => $input, 'fields' => array_values($fields)];
            case 'ai_sentiment':
            case 'ai_summarize':
                $input = $str('input', 2000);
                if ($input === '') {
                    throw $need('choose the text to read, e.g. {{ trigger.properties.message }}.');
                }

                return $type === 'ai_summarize' ? ['input' => $input, 'length' => in_array($c['length'] ?? null, ['sentence', 'paragraph', 'bullets'], true) ? $c['length'] : 'sentence'] : ['input' => $input];
            default: // ai_agent
                $instructions = $str('instructions', 4000);
                if ($instructions === '') {
                    throw $need('write the agent’s instructions.');
                }
                $tools = array_values(array_intersect(JourneyAi::TOOLS, (array) ($c['tools'] ?? [])));
                $fields = array_values(array_unique(array_filter(array_map(fn ($k) => is_string($k) ? trim($k) : '', (array) ($c['fields'] ?? [])),
                    fn ($k) => in_array($k, ['first_name', 'last_name'], true) || ContactService::validKey($k))));
                $lists = array_values(array_unique(array_filter((array) ($c['lists'] ?? []), 'is_string')));
                $lists = $lists ? ContactList::where('workspace_id', $j->workspace_id)->whereIn('id', $lists)->pluck('id')->all() : [];
                if (in_array('fields', $tools, true) && ! $fields) {
                    throw $need('choose the fields the agent may change.');
                }
                if (in_array('lists', $tools, true) && ! $lists) {
                    throw $need('choose the lists the agent may use.');
                }
                if (count($fields) > 20 || count($lists) > 20) {
                    throw $need('allow up to 20 fields and 20 lists.');
                }

                // Chat Model, Memory and Output sub-nodes, as in n8n's AI Agent.
                $model = $str('model', 120);
                if ($model !== '' && ! in_array($model, app(AiClient::class)->models(), true)) {
                    throw $need('that model isn’t available any more. Choose another.');
                }
                $mem = is_array($c['memory'] ?? null) ? $c['memory'] : [];
                $memory = ! empty($mem['on']) ? ['on' => true, 'key' => is_string($mem['key'] ?? null) && preg_match('/^[a-z0-9_-]{1,40}$/', $mem['key']) ? $mem['key'] : throw $need('the memory key can use a–z, 0–9, _ and - (up to 40).'),
                    'window' => max(1, min(20, (int) ($mem['window'] ?? 5)))] : ['on' => false];
                $output = [];
                foreach (array_values((array) ($c['output'] ?? [])) as $i => $f) {
                    if (! is_array($f)) {
                        continue;
                    }
                    $key = is_string($f['name'] ?? null) ? trim($f['name']) : '';
                    if ($key === '') {
                        continue;
                    }
                    if (! preg_match($name, $key) || isset($output[$key]) || in_array($key, JourneyAi::AGENT_KEYS, true)) {
                        throw $need('output field '.($i + 1).' needs a unique name (letters, digits and _; not '.implode(', ', JourneyAi::AGENT_KEYS).').');
                    }
                    $t = $f['type'] ?? 'text';
                    $output[$key] = ['name' => $key, 'type' => in_array($t, JourneyAi::FIELD_TYPES, true) ? $t : 'text', 'description' => is_string($f['description'] ?? null) ? mb_substr(trim($f['description']), 0, 300) : ''];
                }
                if (count($output) > 10) {
                    throw $need('ask for up to 10 output fields.');
                }

                // An agent saved before sub-nodes kept these inline; agentParts() prefers plugged-in sub-nodes.
                return ['instructions' => $instructions, 'tools' => $tools, 'fields' => $fields, 'lists' => array_values($lists), 'max_turns' => $int('max_turns', 2, JourneyAi::MAX_TURNS, 5),
                    'model' => $model, 'memory' => $memory, 'output' => array_values($output)];
        }
    }

    /**
     * The brain of an AI step: its Chat Model sub-node, else the agent's old inline model, else the platform's.
     *
     * @param  array<string, list<array<string, mixed>>>  $att
     * @param  array<string, mixed>  $o
     * @return array{model: string, temperature: float|null, max_tokens: int}
     */
    private function llm(array $att, array $o, callable $sub): array
    {
        if ($att['ai_model']) {
            return $sub($att['ai_model'][0]);
        }

        return ['model' => (string) ($o['model'] ?? ''), 'temperature' => null, 'max_tokens' => 0];
    }

    /**
     * An agent's tools, memory and output parser: its plugged-in sub-nodes, else what it kept inline.
     *
     * @param  array<string, list<array<string, mixed>>>  $att
     * @param  array<string, mixed>  $o
     * @return array<string, mixed>
     */
    private function agentParts(array $att, array $o, callable $sub, callable $need): array
    {
        $tools = [];
        if ($att['ai_tool']) {
            foreach ($att['ai_tool'] as $t) {
                $tools[] = ['id' => $t['id'], 'title' => $t['title']] + $sub($t);
            }
        } else {
            foreach ((array) $o['tools'] as $k) {
                $tools[] = ['id' => null, 'title' => '', 'tool' => $k] + ($k === 'fields' ? ['fields' => $o['fields']] : []) + ($k === 'lists' ? ['lists' => $o['lists']] : []);
            }
        }
        $names = [];
        foreach ($tools as $t) {
            if ($t['tool'] === 'http') {
                if (isset($names[$t['name']])) {
                    throw $need('two HTTP tools are called “'.$t['name'].'”. Give each its own name.');
                }
                $names[$t['name']] = true;
            }
        }
        $memory = $att['ai_memory'] ? ['on' => true] + $sub($att['ai_memory'][0]) : $o['memory'];
        $parser = $att['ai_parser'] ? $sub($att['ai_parser'][0]) : ($o['output'] ? ['kind' => 'structured', 'fields' => $o['output'], 'autofix' => true] : null);

        return ['instructions' => $o['instructions'], 'max_turns' => $o['max_turns'], 'llm' => $o['llm'], 'tool_nodes' => $tools, 'memory' => $memory, 'parser' => $parser];
    }

    /**
     * A sub-node's own settings: Chat Model, Simple Memory, a Tool, or an Output Parser.
     *
     * @param  array<string, mixed>  $n
     * @return array<string, mixed>
     */
    private function sub(Journey $j, array $n): array
    {
        $c = (array) ($n['config'] ?? []);
        $need = fn (string $m) => new ApiException('incomplete', $m, 422);
        $str = fn (string $k, int $max) => is_string($c[$k] ?? null) ? mb_substr(trim($c[$k]), 0, $max) : '';
        $key = '/^[A-Za-z_][A-Za-z0-9_]{0,63}$/';
        switch ($n['type']) {
            case 'ai_model':
                $model = $str('model', 120);
                if ($model !== '' && ! in_array($model, app(AiClient::class)->models(), true)) {
                    throw $need('that model isn’t available any more. Choose another.');
                }
                $max = (int) ($c['max_tokens'] ?? 0);

                return ['model' => $model, 'temperature' => is_numeric($c['temperature'] ?? null) ? round(max(0, min(1, (float) $c['temperature'])), 2) : 0.3,
                    'max_tokens' => $max <= 0 ? 0 : max(100, min(4000, $max))];
            case 'ai_memory':
                $k = $str('key', 40) ?: 'agent';
                if (! preg_match('/^[a-z0-9_-]{1,40}$/', $k)) {
                    throw $need('the memory key can use a–z, 0–9, _ and - (up to 40).');
                }

                return ['key' => $k, 'window' => max(1, min(20, (int) ($c['window'] ?? 5)))];
            case 'ai_parser':
                if (($c['kind'] ?? 'structured') === 'list') {
                    return ['kind' => 'list', 'max_items' => max(1, min(50, (int) ($c['max_items'] ?? 5))), 'item' => $str('item', 200)];
                }
                $fields = [];
                foreach (array_values((array) ($c['fields'] ?? [])) as $i => $f) {
                    $name = is_array($f) && is_string($f['name'] ?? null) ? trim($f['name']) : '';
                    if ($name === '') {
                        continue;
                    }
                    if (! preg_match($key, $name) || isset($fields[$name]) || in_array($name, JourneyAi::AGENT_KEYS, true)) {
                        throw $need('field '.($i + 1).' needs a unique name (letters, digits and _; not '.implode(', ', JourneyAi::AGENT_KEYS).').');
                    }
                    $t = $f['type'] ?? 'text';
                    $fields[$name] = ['name' => $name, 'type' => in_array($t, JourneyAi::FIELD_TYPES, true) ? $t : 'text', 'description' => is_string($f['description'] ?? null) ? mb_substr(trim($f['description']), 0, 300) : ''];
                }
                if (! $fields || count($fields) > 10) {
                    throw $need('add 1 to 10 fields to the output format.');
                }

                return ['kind' => 'structured', 'fields' => array_values($fields), 'autofix' => (bool) ($c['autofix'] ?? true)];
            default: // ai_tool
                $tool = in_array($c['tool'] ?? null, self::TOOL_KINDS, true) ? (string) $c['tool'] : throw $need('choose what this tool does.');
                $o = ['tool' => $tool];
                if ($tool === 'fields') {
                    $o['fields'] = array_values(array_unique(array_filter(array_map(fn ($k) => is_string($k) ? trim($k) : '', (array) ($c['fields'] ?? [])),
                        fn ($k) => in_array($k, ['first_name', 'last_name'], true) || ContactService::validKey($k))));
                    if (! $o['fields'] || count($o['fields']) > 20) {
                        throw $need('choose 1 to 20 fields it may change.');
                    }
                }
                if ($tool === 'lists') {
                    $ids = array_values(array_unique(array_filter((array) ($c['lists'] ?? []), 'is_string')));
                    $o['lists'] = $ids ? ContactList::where('workspace_id', $j->workspace_id)->whereIn('id', $ids)->pluck('id')->all() : [];
                    if (! $o['lists'] || count($o['lists']) > 20) {
                        throw $need('choose the lists it may use.');
                    }
                }
                if ($tool === 'notify') {
                    $o['to'] = array_values(array_filter(array_map(fn ($e) => mb_strtolower(trim((string) $e)), (array) ($c['to'] ?? [])), fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
                    if (! $o['to'] || count($o['to']) > 5) {
                        throw $need('add up to 5 team addresses it may email.');
                    }
                }
                if ($tool === 'think') {
                    $o['description'] = $str('description', 300);
                }
                if ($tool === 'http') {
                    $o['name'] = $str('name', 40);
                    if (! preg_match('/^[a-z][a-z0-9_]{0,39}$/', $o['name']) || in_array($o['name'], JourneyAi::BUILTIN_TOOLS, true)) {
                        throw $need('name the tool in lowercase letters, digits and _ (like check_stock), not a built-in tool’s name.');
                    }
                    $o['description'] = $str('description', 300);
                    if ($o['description'] === '') {
                        throw $need('describe what the tool does and when to use it: the agent decides from this.');
                    }
                    $o['method'] = in_array($c['method'] ?? null, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true) ? $c['method'] : 'GET';
                    $o['url'] = $str('url', 1000);
                    if (! str_starts_with($o['url'], 'https://')) {
                        throw $need('the URL must start with https://.');
                    }
                    $o['headers'] = [];
                    foreach (array_values((array) ($c['headers'] ?? [])) as $h) {
                        $hn = is_array($h) && is_string($h['name'] ?? null) ? trim($h['name']) : '';
                        if ($hn === '') {
                            continue;
                        }
                        if (! preg_match('/^[A-Za-z0-9-]{1,64}$/', $hn) || in_array(strtolower($hn), ['host', 'content-length', 'transfer-encoding', 'connection'], true)) {
                            throw $need("the header “{$hn}” can’t be used.");
                        }
                        $o['headers'][$hn] = is_scalar($h['value'] ?? null) ? mb_substr((string) $h['value'], 0, 1000) : '';
                    }
                    $o['params'] = [];
                    foreach (array_values((array) ($c['params'] ?? [])) as $p) {
                        $pn = is_array($p) && is_string($p['name'] ?? null) ? trim($p['name']) : '';
                        if ($pn === '') {
                            continue;
                        }
                        if (! preg_match($key, $pn) || isset($o['params'][$pn])) {
                            throw $need("the parameter “{$pn}” needs a unique name (letters, digits and _).");
                        }
                        $in = in_array($p['in'] ?? null, ['query', 'body', 'path'], true) ? $p['in'] : 'query';
                        if ($in === 'path' && ! str_contains($o['url'], '{'.$pn.'}')) {
                            throw $need("put {{$pn}} in the URL where the agent’s value goes.");
                        }
                        $o['params'][$pn] = ['name' => $pn, 'description' => is_string($p['description'] ?? null) ? mb_substr(trim($p['description']), 0, 200) : '',
                            'type' => in_array($p['type'] ?? null, ['string', 'number', 'boolean'], true) ? $p['type'] : 'string', 'in' => $in, 'required' => (bool) ($p['required'] ?? true)];
                    }
                    preg_match_all('/(?<!\{)\{([A-Za-z_][A-Za-z0-9_]*)\}(?!\})/', $o['url'], $holes); // {sku}, not {{ expressions }}
                    foreach ($holes[1] as $h) {
                        if (($o['params'][$h]['in'] ?? null) !== 'path') {
                            throw $need("the URL has {{$h}}: add a path parameter called {$h} for the agent to fill.");
                        }
                    }
                    if (count($o['params']) > 10 || count($o['headers']) > 10) {
                        throw $need('use up to 10 parameters and 10 headers.');
                    }
                    $o['params'] = array_values($o['params']);
                }

                return $o;
        }
    }

    /**
     * @param  array<string, mixed>  $n
     * @return array<string, mixed>
     */
    private function email(Journey $j, array $n): array
    {
        $e = JourneyEmail::where('journey_id', $j->id)->where('node_id', $n['id'])->first();
        if (! $e || ! $e->html) {
            throw new ApiException('incomplete', 'design the email.', 422);
        }
        if (! $e->subject) {
            throw new ApiException('incomplete', 'add a subject line.', 422);
        }
        if (! $e->from_email) {
            throw new ApiException('incomplete', 'choose who it’s from.', 422);
        }
        if (! Senders::allows($j->workspace_id, $e->from_email)) {
            throw new ApiException('incomplete', Senders::method($j->workspace_id) === Senders::SMTP ? 'the sender address isn’t verified (Sending → Senders).' : 'the sender address isn’t on a verified domain.', 422);
        }
        if (($n['config']['type'] ?? 'marketing') === 'marketing' && ! str_contains($e->html, '{{unsubscribe_url}}') && ! str_contains($e->html, '{{ unsubscribe_url }}')) {
            throw new ApiException('incomplete', 'add an unsubscribe link (the Footer block has one).', 422);
        }

        return ['subject' => $e->subject, 'preheader' => $e->preheader, 'from_email' => $e->from_email, 'from_name' => $e->from_name, 'reply_to' => $e->reply_to, 'html' => $e->html];
    }

    /** @param array<string, mixed> $c */
    public static function eventFor(string $trigger, array $c): string
    {
        return match ($trigger) {
            'contact_created' => 'contact.created', 'list_added' => 'contact.list_added', 'tag_added' => 'contact.tag_added',
            'field_changed' => 'contact.field_changed', 'segment_joined' => 'contact.segment_joined', 'segment_left' => 'contact.segment_left',
            'form_submitted' => 'contact.form_submitted', 'optin_confirmed' => 'contact.optin_confirmed',
            'topic_unsubscribed' => 'contact.topic_unsubscribed', 'email_opened' => 'email.opened', 'email_clicked' => 'email.clicked', 'email_replied' => 'email.replied',
            'price_drop' => 'sendline.price_drop', 'back_in_stock' => 'sendline.back_in_stock', 'went_quiet' => 'sendline.went_quiet', 'inactive' => 'sendline.inactive', 'schedule' => 'sendline.schedule', 'date' => 'sendline.date', 'webhook' => 'webhook:', 'event' => is_string($c['event'] ?? null) ? trim($c['event']) : '',
            default => '',
        };
    }

    public static function validEvent(string $name): bool
    {
        if (! preg_match('/^[A-Za-z0-9_.:-]{1,100}$/', $name)) {
            return false;
        }
        foreach (self::RESERVED as $p) {
            if (str_starts_with($name, $p)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $c */
    public static function seconds(array $c): int
    {
        return (int) ($c['amount'] ?? 0) * (self::UNITS[(string) ($c['unit'] ?? '')] ?? 0);
    }
}
