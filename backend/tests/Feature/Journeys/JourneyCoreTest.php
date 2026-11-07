<?php

use App\Models\Domain;
use App\Models\JourneyRun;
use App\Services\Contacts\ContactService;
use App\Services\Journeys\JourneyCore;
use App\Services\Journeys\JourneyEngine;
use App\Services\Journeys\JourneyEvents;
use App\Services\Platform\Settings;
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
    UrlGuard::$resolver = fn (string $host) => $host === 'internal.acme.com' ? ['10.0.0.5'] : ['93.184.216.34'];
    [$this->ws] = sesWorkspace($this, ['viewer']);
    sesConnect($this, $this->ws)->assertOk();
    JourneyEvents::flush();
});

afterEach(function () {
    UrlGuard::$resolver = null;
});

function jc($test, string $path = ''): string
{
    return "/v1/workspaces/{$test->ws->id}/journeys".($path ? "/$path" : '');
}

/** @param list<array<string, mixed>> $nodes @param list<array<string, mixed>> $edges */
function coreJourney($test, array $nodes, array $edges, bool $publish = true): string
{
    $id = $test->postJson(jc($test), ['name' => 'Core'])->assertCreated()->json('journey.id');
    $test->patchJson(jc($test, $id), ['settings' => ['reentry' => 'always'], 'graph' => ['nodes' => $nodes, 'edges' => $edges]])->assertOk();
    if ($publish) {
        $test->postJson(jc($test, "$id/publish"))->assertOk();
    }

    return $id;
}

function tagsOf(string $contactId): array
{
    return DB::table('contact_tags')->where('contact_id', $contactId)->orderBy('tag')->pluck('tag')->all();
}

it('routes with Switch, computes values with Edit fields and Date & Time, and stops runs with Filter', function () {
    coreJourney($this, [
        ['id' => 't1', 'type' => 'trigger', 'config' => ['trigger' => 'event', 'event' => 'order_placed']],
        ['id' => 'f', 'type' => 'filter', 'config' => ['rules' => ['match' => 'all', 'conditions' => [['field' => 'data', 'path' => 'trigger.properties.amount', 'op' => 'is_set']]]]],
        ['id' => 'set', 'type' => 'set', 'config' => ['fields' => [['name' => 'total', 'type' => 'number', 'value' => '{{ trigger.properties.amount }}'], ['name' => 'vip', 'type' => 'yes_no', 'value' => '{{ trigger.properties.vip }}'], ['name' => 'label', 'type' => 'text', 'value' => '{{ contact.email }} / {{ trigger.properties.plan }}']]]],
        ['id' => 'renew', 'type' => 'datetime', 'config' => ['op' => 'add', 'value' => '{{ trigger.properties.renews_at }}', 'amount' => 1, 'unit' => 'months', 'format' => 'date']],
        ['id' => 'sw', 'type' => 'switch', 'config' => ['value' => '{{ trigger.properties.plan }}', 'cases' => [['id' => 'pro', 'op' => 'is', 'value' => 'pro', 'name' => 'Pro'], ['id' => 'team', 'op' => 'contains', 'value' => 'team', 'name' => '']]]],
        ['id' => 'tp', 'type' => 'tag', 'config' => ['action' => 'add', 'tag' => 'pro-until-{{ steps.renew.result }}']],
        ['id' => 'tt', 'type' => 'tag', 'config' => ['action' => 'add', 'tag' => 'team']],
        ['id' => 'to', 'type' => 'tag', 'config' => ['action' => 'add', 'tag' => 'other']],
    ], [['from' => 't1', 'to' => 'f'], ['from' => 'f', 'to' => 'set'], ['from' => 'set', 'to' => 'renew'], ['from' => 'renew', 'to' => 'sw'],
        ['from' => 'sw', 'to' => 'tp', 'branch' => 'pro'], ['from' => 'sw', 'to' => 'tt', 'branch' => 'team'], ['from' => 'sw', 'to' => 'to', 'branch' => 'other']]);
    $ada = app(ContactService::class)->upsert($this->ws, ['email' => 'ada@example.com'], 'api');
    $ev = app(JourneyEvents::class);
    $ev->fire($ada, 'order_placed', ['amount' => '1,250', 'vip' => 'yes', 'plan' => 'PRO', 'renews_at' => '2026-01-31'], 'api');
    $ev->fire($ada, 'order_placed', ['amount' => 40, 'plan' => 'Big team', 'renews_at' => '2026-03-01T09:00:00Z'], 'api');
    $ev->fire($ada, 'order_placed', ['amount' => 20, 'plan' => 'free', 'renews_at' => '2026-03-01'], 'api');
    $ev->fire($ada, 'order_placed', ['plan' => 'pro', 'renews_at' => '2026-03-01'], 'api'); // no amount: filtered out

    $runs = JourneyRun::where('contact_id', $ada->id)->orderBy('created_at')->orderBy('id')->get();
    expect($runs->pluck('status')->all())->toBe(['completed', 'completed', 'completed', 'completed'])
        ->and($runs[0]->data['steps']['set'])->toBe(['total' => 1250, 'vip' => true, 'label' => 'ada@example.com / PRO'])
        ->and($runs[0]->data['steps']['renew'])->toMatchArray(['result' => '2026-02-28', 'timezone' => 'UTC'])
        ->and($runs[0]->data['steps']['sw'])->toBe(['value' => 'PRO', 'matched' => 'Pro'])
        ->and($runs[1]->data['steps']['sw'])->toBe(['value' => 'Big team', 'matched' => 'Case 2'])
        ->and($runs[2]->data['steps']['sw'])->toBe(['value' => 'free', 'matched' => null])
        ->and($runs[3]->data['steps'])->not->toHaveKey('set') // filtered out
        ->and(DB::table('journey_steps')->where('run_id', $runs[3]->id)->where('node_id', 'f')->value('outcome'))->toBe('filtered')
        ->and(tagsOf($ada->id))->toBe(['other', 'pro-until-2026-02-28', 'team']);

    // Date & Time: differences, formats, and a value that isn't a date fails the step.
    $now = now()->startOfMinute();
    $r = JourneyCore::datetime(['op' => 'diff', 'value' => '{{ trigger.properties.at }}', 'unit' => 'days'], ['trigger' => ['properties' => ['at' => $now->copy()->addDays(3)->toIso8601ZuluString()]]], 'Europe/London');
    expect($r['output'])->toMatchArray(['result' => 3, 'unit' => 'days', 'past' => false]);
    expect(JourneyCore::datetime(['op' => 'format', 'value' => '2026-11-03T14:00:00Z', 'format' => 'datetime'], [], 'Africa/Lagos')['output']['result'])->toBe('2026-11-03 15:00')
        ->and(JourneyCore::datetime(['op' => 'format', 'value' => 'next tuesday', 'format' => 'date'], [], 'UTC'))->toMatchArray(['outcome' => 'failed', 'detail' => 'Not a date: “next tuesday”']);
});

it('calls any API with HTTP Request, filled from the data, and refuses private addresses even when the URL comes from data', function () {
    Http::fake(['https://api.acme.com/*' => Http::response(['score' => 87, 'segment' => 'warm'], 200), 'https://down.acme.com/*' => Http::response('nope', 503)]);
    coreJourney($this, [
        ['id' => 't1', 'type' => 'trigger', 'config' => ['trigger' => 'event', 'event' => 'scored']],
        ['id' => 'h', 'type' => 'http', 'config' => ['method' => 'POST', 'url' => 'https://{{ trigger.properties.host }}/score', 'query' => [['name' => 'email', 'value' => '{{ contact.email }}']],
            'headers' => [['name' => 'Authorization', 'value' => 'Bearer abc123']], 'body' => [['name' => 'amount', 'value' => '{{ trigger.properties.amount }}']], 'on_error' => 'branch']],
        ['id' => 'ok', 'type' => 'tag', 'config' => ['action' => 'add', 'tag' => '{{ steps.h.body.segment }}']],
        ['id' => 'bad', 'type' => 'tag', 'config' => ['action' => 'add', 'tag' => 'api-failed']],
    ], [['from' => 't1', 'to' => 'h'], ['from' => 'h', 'to' => 'ok'], ['from' => 'h', 'to' => 'bad', 'branch' => 'error']]);
    $ada = app(ContactService::class)->upsert($this->ws, ['email' => 'ada@example.com'], 'api');
    $bo = app(ContactService::class)->upsert($this->ws, ['email' => 'bo@example.com'], 'api');
    $cy = app(ContactService::class)->upsert($this->ws, ['email' => 'cy@example.com'], 'api');
    app(JourneyEvents::class)->fire($ada, 'scored', ['host' => 'api.acme.com', 'amount' => 70], 'api');
    app(JourneyEvents::class)->fire($bo, 'scored', ['host' => 'internal.acme.com'], 'api');
    app(JourneyEvents::class)->fire($cy, 'scored', ['host' => 'down.acme.com'], 'api');

    expect(tagsOf($ada->id))->toBe(['warm'])->and(tagsOf($bo->id))->toBe(['api-failed'])->and(tagsOf($cy->id))->toBe(['api-failed'])
        ->and(JourneyRun::where('contact_id', $ada->id)->first()->data['steps']['h'])->toMatchArray(['status' => 200, 'ok' => true, 'body' => ['score' => 87, 'segment' => 'warm']])
        ->and(JourneyRun::where('contact_id', $cy->id)->first()->data['steps']['h'])->toMatchArray(['status' => 503, 'ok' => false]);
    Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://api.acme.com/score?email=ada%40example.com' && $r->method() === 'POST' && $r['amount'] === '70' && $r->header('Authorization') === ['Bearer abc123']);
    Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'internal.acme.com'));

    // Publishing checks what it can: plain http and a fixed private URL are refused.
    $bad = coreJourney($this, [['id' => 't1', 'type' => 'trigger', 'config' => ['trigger' => 'event', 'event' => 'x']], ['id' => 'h', 'type' => 'http', 'config' => ['method' => 'GET', 'url' => 'http://api.acme.com/x']]], [['from' => 't1', 'to' => 'h']], false);
    $this->postJson(jc($this, "$bad/publish"))->assertStatus(422);
});

it('waits for a teammate to approve or reject from an email, once, and treats no answer as not approved', function () {
    Domain::updateOrCreate(['workspace_id' => $this->ws->id, 'name' => 'acme.com'], ['region' => 'eu-west-1', 'status' => 'verified']);
    coreJourney($this, [
        ['id' => 't1', 'type' => 'trigger', 'config' => ['trigger' => 'event', 'event' => 'refund_requested']],
        ['id' => 'ap', 'type' => 'approval', 'config' => ['to' => ['boss@acme.com'], 'message' => 'Refund {{ trigger.properties.amount }} for {{ email }}?', 'amount' => 2, 'unit' => 'days']],
        ['id' => 'y', 'type' => 'tag', 'config' => ['action' => 'add', 'tag' => 'refund-approved']],
        ['id' => 'n', 'type' => 'tag', 'config' => ['action' => 'add', 'tag' => 'refund-declined']],
    ], [['from' => 't1', 'to' => 'ap'], ['from' => 'ap', 'to' => 'y', 'branch' => 'yes'], ['from' => 'ap', 'to' => 'n', 'branch' => 'no']]);
    [$ada, $bo, $cy] = array_map(fn ($e) => app(ContactService::class)->upsert($this->ws, ['email' => $e], 'api'), ['ada@example.com', 'bo@example.com', 'cy@example.com']);
    foreach ([$ada, $bo, $cy] as $c) {
        app(JourneyEvents::class)->fire($c, 'refund_requested', ['amount' => '$40'], 'api');
    }
    $run = fn ($c) => JourneyRun::where('contact_id', $c->id)->firstOrFail();
    expect($run($ada)->status)->toBe('waiting');
    $mail = DB::table('messages')->where('workspace_id', $this->ws->id)->where('to_email', 'boss@acme.com')->orderBy('created_at')->first();
    $token = JourneyCore::approvalToken($run($ada)->id, 'ap');
    expect($mail->subject)->toBe('[Core] Approve? Refund $40 for ada@example.com?')->and($mail->html)->toContain('/a/?t='.$token);

    // Opening the link only shows the request.
    $this->getJson("/v1/approve/$token")->assertOk()->assertJson(['journey' => 'Core', 'message' => 'Refund $40 for ada@example.com?', 'status' => 'waiting', 'contact' => ['email' => 'ada@example.com']]);
    expect($run($ada)->status)->toBe('waiting');
    $this->postJson("/v1/approve/$token", ['decision' => 'approve'])->assertOk();
    $this->postJson("/v1/approve/$token", ['decision' => 'reject'])->assertStatus(409);
    $this->postJson('/v1/approve/'.JourneyCore::approvalToken($run($bo)->id, 'ap'), ['decision' => 'reject'])->assertOk();
    $this->getJson('/v1/approve/'.substr($token, 0, -1).(str_ends_with($token, '0') ? '1' : '0'))->assertStatus(404); // a changed signature (always a different last character)
    expect(tagsOf($ada->id))->toBe(['refund-approved'])->and(tagsOf($bo->id))->toBe(['refund-declined'])
        ->and($run($ada)->data['steps']['ap'])->toMatchArray(['answer' => 'approved', 'approved' => true])
        ->and($this->getJson("/v1/approve/$token")->json('status'))->toBe('closed');

    // Nobody answers for Cy: after two days it goes down "not approved".
    $this->travel(2)->days();
    $this->travel(1)->minutes();
    app(JourneyEngine::class)->advance($run($cy)->id);
    expect($run($cy)->data['steps']['ap'])->toMatchArray(['answer' => 'no_answer', 'approved' => false])->and(tagsOf($cy->id))->toBe(['refund-declined']);
});

it('ends a run as failed with Stop and error, and reads sentiment and summaries with AI', function () {
    Settings::set('ai.openrouter_key', 'sk-or-test-123');
    Http::fake(['openrouter.ai/*' => Http::sequence()
        ->push(['model' => 'm', 'choices' => [['message' => ['content' => '{"sentiment": "negative", "score": -0.8, "reason": "Angry about a late order."}']]], 'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 10]])
        ->push(['model' => 'm', 'choices' => [['message' => ['content' => 'The order is a week late and they want a refund.']]], 'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 10]])]);
    coreJourney($this, [
        ['id' => 't1', 'type' => 'trigger', 'config' => ['trigger' => 'event', 'event' => 'review']],
        ['id' => 's', 'type' => 'ai_sentiment', 'config' => ['input' => '{{ trigger.properties.text }}']],
        ['id' => 'sum', 'type' => 'ai_summarize', 'config' => ['input' => '{{ trigger.properties.text }}', 'length' => 'sentence']],
        ['id' => 'stop', 'type' => 'stop', 'config' => ['message' => 'Unhappy customer: {{ handled by hand }}']],
        ['id' => 'pos', 'type' => 'tag', 'config' => ['action' => 'add', 'tag' => 'happy']],
    ], [['from' => 't1', 'to' => 's'], ['from' => 's', 'to' => 'sum', 'branch' => 'negative'], ['from' => 's', 'to' => 'pos', 'branch' => 'positive'], ['from' => 'sum', 'to' => 'stop']]);
    $ada = app(ContactService::class)->upsert($this->ws, ['email' => 'ada@example.com'], 'api');
    app(JourneyEvents::class)->fire($ada, 'review', ['text' => 'My order is a week late. I want a refund now.'], 'api');
    $r = JourneyRun::where('contact_id', $ada->id)->firstOrFail();
    expect($r->status)->toBe('failed')->and($r->exit_reason)->toBe('Unhappy customer: {{ handled by hand }}')
        ->and($r->data['steps']['s'])->toMatchArray(['sentiment' => 'negative', 'score' => -0.8])
        ->and($r->data['steps']['sum'])->toMatchArray(['summary' => 'The order is a week late and they want a refund.'])
        ->and(tagsOf($ada->id))->toBe([]);
});
