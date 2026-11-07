<?php

use App\Models\Contact;
use App\Models\Journey;
use App\Models\JourneyRun;
use App\Services\Contacts\ContactService;
use App\Services\Journeys\JourneyData;
use App\Services\Journeys\JourneyEvents;
use App\Services\Ses\DnsLookup;
use App\Services\Webhooks\UrlGuard;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeAws;
use Tests\Support\FakeDns;

beforeEach(function () {
    FakeAws::install();
    $this->app->instance(DnsLookup::class, new FakeDns);
    UrlGuard::$resolver = fn () => ['93.184.216.34'];
    [$this->ws] = sesWorkspace($this, ['viewer']);
    sesConnect($this, $this->ws)->assertOk();
    JourneyEvents::flush();
    Http::fake(['https://api.acme.com/*' => Http::response(['plan' => 'pro', 'score' => 42, 'items' => [['sku' => 'A1']]], 200)]);
});

afterEach(function () {
    UrlGuard::$resolver = null;
});

function jd($test, string $path = ''): string
{
    return "/v1/workspaces/{$test->ws->id}/journeys".($path ? "/$path" : '');
}

/** order_placed → webhook (its answer) → if plan is pro → set plan + tag from the answer; else exit. */
function dataJourney($test): string
{
    $id = $test->postJson(jd($test), ['name' => 'Data'])->assertCreated()->json('journey.id');
    $test->patchJson(jd($test, $id), ['graph' => ['nodes' => [
        ['id' => 't1', 'type' => 'trigger', 'config' => ['trigger' => 'event', 'event' => 'order_placed']],
        ['id' => 'hook', 'type' => 'webhook', 'config' => ['url' => 'https://api.acme.com/score', 'fields' => [['name' => 'amount', 'value' => '{{ trigger.properties.amount }}'], ['name' => 'who', 'value' => '{{ contact.first_name }}']]]],
        ['id' => 'if', 'type' => 'cond', 'config' => ['rules' => ['match' => 'all', 'conditions' => [['field' => 'data', 'path' => 'steps.hook.body.plan', 'op' => 'is', 'value' => 'Pro'], ['field' => 'data', 'path' => 'trigger.properties.amount', 'op' => 'gte', 'value' => '50']]]]],
        ['id' => 'set', 'type' => 'field', 'config' => ['key' => 'plan', 'value' => '{{ steps.hook.body.plan }}']],
        ['id' => 'tag', 'type' => 'tag', 'config' => ['action' => 'add', 'tag' => 'plan-{{ steps.hook.body.plan }}']],
        ['id' => 'bye', 'type' => 'exit', 'config' => []],
    ], 'edges' => [['from' => 't1', 'to' => 'hook'], ['from' => 'hook', 'to' => 'if'], ['from' => 'if', 'to' => 'set', 'branch' => 'yes'], ['from' => 'if', 'to' => 'bye', 'branch' => 'no'], ['from' => 'set', 'to' => 'tag']]]])->assertOk();

    return $id;
}

it('passes each step’s output to the next: expressions, data conditions and the webhook’s answer', function () {
    $id = dataJourney($this);
    $this->postJson(jd($this, "$id/publish"))->assertOk();
    $ada = app(ContactService::class)->upsert($this->ws, ['email' => 'ada@example.com', 'first_name' => 'Ada'], 'api');
    $bo = app(ContactService::class)->upsert($this->ws, ['email' => 'bo@example.com'], 'api');
    app(JourneyEvents::class)->fire($ada, 'order_placed', ['amount' => 99], 'api');
    app(JourneyEvents::class)->fire($bo, 'order_placed', ['amount' => 10], 'api');

    $run = JourneyRun::where('contact_id', $ada->id)->firstOrFail();
    expect($run->status)->toBe('completed')
        ->and($run->data['steps']['hook'])->toMatchArray(['status' => 200, 'ok' => true, 'body' => ['plan' => 'pro', 'score' => 42, 'items' => [['sku' => 'A1']]]])
        ->and($run->data['steps']['if'])->toBe(['result' => 'yes', 'conditions' => [true, true]])
        ->and($run->data['steps']['set'])->toBe(['field' => 'plan', 'before' => null, 'after' => 'pro'])
        ->and($ada->refresh()->fields['plan'])->toBe('pro')
        ->and(DB::table('contact_tags')->where('contact_id', $ada->id)->pluck('tag')->all())->toContain('plan-pro');
    // The endpoint got the body fields, filled from the trigger and the contact.
    Http::assertSent(fn (HttpRequest $r) => $r['data'] === ['amount' => '99', 'who' => 'Ada'] && $r['trigger']['properties']['amount'] === 99 && ! isset($r['test']));
    // Bo's order is too small: No → exit, and nothing set.
    $boRun = JourneyRun::where('contact_id', $bo->id)->firstOrFail();
    expect($boRun->data['steps']['if'])->toBe(['result' => 'no', 'conditions' => [true, false]])->and($bo->refresh()->fields['plan'] ?? null)->toBeNull();

    // The run's debug view has every step's output and timing.
    $steps = $this->getJson(jd($this, "$id/runs/{$run->id}"))->assertOk()->json('run');
    expect(array_column($steps['steps'], 'node'))->toBe(['t1', 'hook', 'if', 'set', 'tag'])->and($steps['steps'][1]['output']['body']['plan'])->toBe('pro')
        ->and($steps['steps'][1]['ms'])->toBeInt()->and($steps['trigger'])->toBe(['event' => 'order_placed', 'properties' => ['amount' => 99]])->and($steps['contact']['email'])->toBe('ada@example.com');
});

it('checks data conditions and expression settings before going live', function () {
    $id = $this->postJson(jd($this), ['name' => 'Bad'])->assertCreated()->json('journey.id');
    $this->patchJson(jd($this, $id), ['graph' => ['nodes' => [
        ['id' => 't1', 'type' => 'trigger', 'config' => ['trigger' => 'event', 'event' => 'x']],
        ['id' => 'if', 'type' => 'cond', 'config' => ['rules' => ['match' => 'all', 'conditions' => [['field' => 'data', 'path' => 'secrets.key', 'op' => 'is', 'value' => '1']]]]],
        ['id' => 'n', 'type' => 'cond', 'config' => ['rules' => ['match' => 'all', 'conditions' => [['field' => 'data', 'path' => 'trigger.properties.total', 'op' => 'gt', 'value' => 'lots']]]]],
        ['id' => 'h', 'type' => 'webhook', 'config' => ['url' => 'https://api.acme.com/x', 'fields' => [['name' => 'bad name', 'value' => 'x']]]],
    ], 'edges' => [['from' => 't1', 'to' => 'if'], ['from' => 'if', 'to' => 'n', 'branch' => 'yes'], ['from' => 'n', 'to' => 'h', 'branch' => 'yes']]]])->assertOk();
    $msgs = collect($this->getJson(jd($this, $id))->json('journey.problems'))->pluck('message', 'node');
    expect($msgs['if'])->toContain('choose a value from the data')->and($msgs['n'])->toContain('compare with a number')->and($msgs['h'])->toContain('needs a unique name');

    expect(JourneyData::resolve('Hi {{ contact.first_name }}, {{ first_name | there }} {{ steps.x.list }} {{ steps.none.y }}', ['contact' => ['first_name' => 'Ada'], 'steps' => ['x' => ['list' => [1, 2]]]]))
        ->toBe('Hi Ada, {{ first_name | there }} [1,2] ') // other merge tags stay for the email renderer
        ->and(JourneyData::clip(['small' => 'ok', 'big' => str_repeat('x', 9000)]))->toBe(['small' => 'ok', 'big' => '(too large to keep: 8.8 KB)']);
});

it('tests the trigger and each step on real data without changing anything, as in n8n', function () {
    $id = dataJourney($this);
    $ada = app(ContactService::class)->upsert($this->ws, ['email' => 'ada@example.com', 'first_name' => 'Ada'], 'api');
    app(JourneyEvents::class)->fire($ada, 'order_placed', ['amount' => 120, 'currency' => 'NGN'], 'api'); // the journey is a draft: nobody enters
    expect(JourneyRun::count())->toBe(0);

    // Fetch test event: the latest real order_placed.
    $s = $this->postJson(jd($this, "$id/test/trigger"))->assertOk()->json('sample');
    expect($s['source'])->toBe('event')->and($s['data']['trigger'])->toBe(['event' => 'order_placed', 'properties' => ['amount' => 120, 'currency' => 'NGN']])
        ->and($s['data']['contact']['email'])->toBe('ada@example.com')->and($s['data']['steps'])->toBe([]);
    $this->putJson(jd($this, "$id/test-data"), ['test_data' => $s])->assertOk()->assertJsonPath('test_data.source', 'event');
    expect(Journey::find($id)->test_data['data']['trigger']['properties']['amount'])->toBe(120);

    // Test each step in turn, feeding the data on.
    $data = $s['data'];
    $hook = $this->postJson(jd($this, "$id/test/step"), ['node' => 'hook', 'data' => $data])->assertOk()->json('result');
    expect($hook['output']['body']['plan'])->toBe('pro')->and($hook['note'])->toContain('Called your endpoint for real');
    Http::assertSent(fn (HttpRequest $r) => ($r['test'] ?? null) === true && $r['data']['amount'] === '120');
    $if = $this->postJson(jd($this, "$id/test/step"), ['node' => 'if', 'data' => $hook['data']])->assertOk()->json('result');
    expect($if['branch'])->toBe('yes')->and($if['output'])->toBe(['result' => 'yes', 'conditions' => [true, true]]);
    $set = $this->postJson(jd($this, "$id/test/step"), ['node' => 'set', 'data' => $if['data']])->assertOk()->json('result');
    expect($set['output'])->toBe(['field' => 'plan', 'before' => null, 'after' => 'pro'])->and($set['note'])->toBe('Would set plan to “pro”.');
    $tag = $this->postJson(jd($this, "$id/test/step"), ['node' => 'tag', 'data' => $set['data']])->assertOk()->json('result');
    expect($tag['output'])->toBe(['action' => 'add', 'tag' => 'plan-pro']);
    expect($ada->refresh()->fields['plan'] ?? null)->toBeNull()->and(DB::table('contact_tags')->where('contact_id', $ada->id)->count())->toBe(0); // nothing changed

    // Without the webhook's answer the condition can't match.
    $this->postJson(jd($this, "$id/test/step"), ['node' => 'if', 'data' => $data])->assertOk()->assertJsonPath('result.branch', 'no');
    $this->postJson(jd($this, "$id/test/step"), ['node' => 't1', 'data' => $data])->assertStatus(422);
    $this->postJson(jd($this, "$id/test/step"), ['node' => 'if', 'data' => ['contact' => ['id' => 'nope']]])->assertStatus(422);
    $this->putJson(jd($this, "$id/test-data"), ['test_data' => ['data' => ['contact' => ['id' => Contact::max('id').'x']]]])->assertStatus(422);

    // Viewers can look, not run.
    switchTo($this, 'viewer@example.com');
    $this->getJson(jd($this, "$id/test-data"))->assertOk()->assertJsonPath('test_data.source', 'event');
    $this->postJson(jd($this, "$id/test/step"), ['node' => 'if', 'data' => $data])->assertForbidden();
});

it('listens for one test call to the journey URL and pins it, without starting a run', function () {
    $id = $this->postJson(jd($this), ['name' => 'Hook'])->assertCreated()->json('journey.id');
    $this->patchJson(jd($this, $id), ['graph' => ['nodes' => [['id' => 't1', 'type' => 'trigger', 'config' => ['trigger' => 'webhook']]], 'edges' => []]])->assertOk();
    $token = Journey::find($id)->hook_token;
    $call = ['email' => 'zed@example.com', 'first_name' => 'Zed', 'properties' => ['plan' => 'Pro', 'seats' => 3]];
    $this->postJson("/v1/hooks/j/$token?test=1", $call)->assertStatus(409)->assertJsonPath('error.code', 'not_listening');

    $this->postJson(jd($this, "$id/test/listen"))->assertOk()->assertJsonPath('listening', 120);
    $this->postJson("/v1/hooks/j/$token?test=1", $call)->assertStatus(202)->assertJsonPath('test', true);
    $pinned = $this->getJson(jd($this, "$id/test-data"))->assertOk()->json();
    expect($pinned['listening'])->toBeFalse()->and($pinned['test_data']['source'])->toBe('webhook')
        ->and($pinned['test_data']['data']['trigger']['properties'])->toBe(['plan' => 'Pro', 'seats' => 3])->and($pinned['test_data']['data']['contact']['first_name'])->toBe('Zed')
        ->and(JourneyRun::count())->toBe(0);
    $this->postJson("/v1/hooks/j/$token?test=1", $call)->assertStatus(409); // one call per listen
    $this->postJson("/v1/hooks/j/$token", $call)->assertStatus(409); // and the real URL only works once it's on
});
