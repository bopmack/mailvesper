<?php

use App\Models\ApiKey;
use App\Models\Contact;
use App\Models\ContactList;
use App\Models\Journey;
use App\Models\JourneyRun;
use App\Models\Message;
use App\Models\Suppression;
use App\Services\Contacts\ContactService;
use App\Services\Journeys\JourneyEvents;
use App\Services\Journeys\Recipes;
use App\Services\Sending\Mailer;
use App\Services\Ses\DnsLookup;
use App\Services\Ses\EventProcessor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FakeAws;
use Tests\Support\FakeDns;

const J_HUMAN_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

beforeEach(function () {
    $this->aws = FakeAws::install();
    $this->app->instance(DnsLookup::class, new FakeDns);
    [$this->ws, $this->people] = sesWorkspace($this, ['viewer']);
    sesConnect($this, $this->ws)->assertOk();
    $this->doc = json_decode(file_get_contents(base_path('tests/fixtures/design.json')), true);
    JourneyEvents::flush();
});

function jr($test, string $path = ''): string
{
    return "/v1/workspaces/{$test->ws->id}/journeys".($path ? "/$path" : '');
}

/** Creates a journey from a recipe and completes every email step. */
function readyJourney($test, string $recipe = 'welcome'): string
{
    $id = $test->postJson(jr($test), ['recipe' => $recipe])->assertCreated()->json('journey.id');
    foreach (Journey::find($id)->graph['nodes'] as $n) {
        if ($n['type'] === 'email') {
            $test->putJson(jr($test, "$id/emails/{$n['id']}"), ['from_email' => 'news@acme.com', 'from_name' => 'Acme', 'doc' => $test->doc])->assertOk();
        }
    }

    return $id;
}

function addContact($test, string $email, array $extra = []): Contact
{
    return app(ContactService::class)->upsert($test->ws, ['email' => $email] + $extra, 'api');
}

function sentTo(string $email): array
{
    return Message::where('to_email', $email)->orderBy('created_at')->orderBy('id')->pluck('journey_node')->all();
}

function runOf(string $journeyId, Contact $c): JourneyRun
{
    return JourneyRun::where('journey_id', $journeyId)->where('contact_id', $c->id)->latest('entered_at')->firstOrFail();
}

/** A real (human) open, fed through the SES event pipeline. */
function humanOpen($test, Message $m): void
{
    foreach ([['Delivery', [], '2026-10-24T09:00:00Z'], ['Open', ['userAgent' => J_HUMAN_UA, 'ipAddress' => '102.89.1.1'], '2026-10-24T09:05:00Z']] as [$type, $section, $at]) {
        $event = ['eventType' => $type, 'mail' => ['messageId' => $m->ses_message_id, 'timestamp' => $at, 'destination' => [$m->to_email], 'tags' => ['sl_message' => [$m->id]]], lcfirst($type) => $section + ['timestamp' => $at]];
        $id = DB::table('ses_events')->insertGetId(['workspace_id' => $test->ws->id, 'event_type' => $type, 'message_id' => $m->ses_message_id, 'sns_message_id' => (string) Str::uuid(), 'payload' => json_encode($event), 'created_at' => now()]);
        app(EventProcessor::class)->apply($test->ws->id, $event, $id);
    }
}

it('saves drafts loosely but lists every problem before going live', function () {
    $id = $this->postJson(jr($this), ['name' => 'Scratch'])->assertCreated()->assertJsonPath('journey.status', 'draft')->json('journey.id');
    $this->patchJson(jr($this, $id), ['graph' => ['nodes' => [['id' => 't1', 'type' => 'trigger']], 'edges' => [['from' => 'x', 'to' => 't1']]]])->assertStatus(422)->assertJsonPath('error.code', 'invalid_graph');
    $this->patchJson(jr($this, $id), ['graph' => ['nodes' => [['id' => 't1', 'type' => 'trigger'], ['id' => 'e1', 'type' => 'email']], 'edges' => [['from' => 'e1', 'to' => 't1']]]])
        ->assertStatus(422)->assertJsonPath('error.message', 'Nothing can connect into a trigger.');

    $graph = ['nodes' => [
        ['id' => 't1', 'type' => 'trigger', 'title' => 'Start', 'config' => ['trigger' => 'event', 'event' => 'contact.created']],
        ['id' => 'e1', 'type' => 'email', 'title' => 'Hello'],
        ['id' => 'w1', 'type' => 'wait', 'title' => 'Wait', 'config' => ['amount' => 0, 'unit' => 'days']],
        ['id' => 'x1', 'type' => 'tag', 'title' => 'Orphan', 'config' => ['tag' => 'vip']],
    ], 'edges' => [['from' => 't1', 'to' => 'e1'], ['from' => 'e1', 'to' => 'w1']]];
    $problems = collect($this->patchJson(jr($this, $id), ['graph' => $graph])->assertOk()->json('journey.problems'));
    expect($problems->pluck('node')->all())->toBe(['t1', 'e1', 'w1', 'x1'])
        ->and($problems->firstWhere('node', 't1')['message'])->toContain('enter the event name')
        ->and($problems->firstWhere('node', 'e1')['message'])->toContain('design the email')
        ->and($problems->firstWhere('node', 'x1')['message'])->toContain('isn’t connected to a trigger');
    $this->postJson(jr($this, "$id/publish"))->assertStatus(422)->assertJsonPath('error.code', 'not_ready')->assertJsonCount(4, 'error.details.problems');

    // Loops are refused: every path must end.
    $loop = ['nodes' => [['id' => 't1', 'type' => 'trigger', 'config' => ['trigger' => 'contact_created']], ['id' => 'w1', 'type' => 'wait', 'config' => ['amount' => 1, 'unit' => 'days']],
        ['id' => 'w2', 'type' => 'wait', 'config' => ['amount' => 1, 'unit' => 'days']]], 'edges' => [['from' => 't1', 'to' => 'w1'], ['from' => 'w1', 'to' => 'w2'], ['from' => 'w2', 'to' => 'w1']]];
    expect($this->patchJson(jr($this, $id), ['graph' => $loop])->json('journey.problems.0.message'))->toContain('loops back');
});

it('runs the welcome series for real contacts with correct branching and stats', function () {
    $id = readyJourney($this);
    $this->postJson(jr($this, "$id/publish"))->assertOk()->assertJsonPath('journey.status', 'active')->assertJsonPath('journey.version', 1);

    $amara = addContact($this, 'amara@example.com', ['first_name' => 'Amara']);
    $liam = addContact($this, 'liam@example.com');
    expect(sentTo('amara@example.com'))->toBe(['e1'])->and(sentTo('liam@example.com'))->toBe(['e1'])
        ->and(runOf($id, $amara)->status)->toBe('waiting')->and(runOf($id, $amara)->current_node)->toBe('w1')
        ->and(Message::where('to_email', 'amara@example.com')->value('subject'))->toBe('Welcome, Amara');

    // Amara really opens the welcome email (through the SES event pipeline); Liam doesn't.
    humanOpen($this, Message::where('to_email', 'amara@example.com')->first());

    $this->travel(47)->hours();
    $this->artisan('journeys:tick')->assertSuccessful();
    expect(sentTo('amara@example.com'))->toBe(['e1']); // still waiting

    $this->travel(2)->hours();
    $this->artisan('journeys:tick')->assertSuccessful();
    expect(sentTo('amara@example.com'))->toBe(['e1', 'e2'])->and(sentTo('liam@example.com'))->toBe(['e1', 'e3'])
        ->and(runOf($id, $amara)->status)->toBe('completed')->and(runOf($id, $liam)->status)->toBe('completed');

    $s = $this->getJson(jr($this, "$id/stats"))->assertOk()->json('stats');
    expect($s['entered'])->toBe(2)->and($s['inside'])->toBe(0)->and($s['completed'])->toBe(2)
        ->and($s['nodes'])->toMatchArray(['t1' => 2, 'e1' => 2, 'w1' => 2, 'c1' => 2, 'e2' => 1, 'e3' => 1])
        ->and(collect($s['edges'])->firstWhere(fn ($e) => $e['from'] === 'c1' && $e['to'] === 'e2')['count'])->toBe(1)
        ->and(collect($s['edges'])->firstWhere(fn ($e) => $e['from'] === 't1' && $e['to'] === 'e1')['count'])->toBe(2)
        ->and($s['emails']['e1'])->toMatchArray(['sent' => 2, 'opened' => 1])->and($s['outcomes']['c1'])->toMatchArray(['yes' => 1, 'no' => 1]);
    expect($this->getJson(jr($this, "$id/runs?node=e2"))->json('runs.0.contact.email'))->toBe('amara@example.com');
    $steps = $this->getJson(jr($this, "$id/runs/".runOf($id, $amara)->id))->json('run.steps');
    expect(array_column($steps, 'node'))->toBe(['t1', 'e1', 'w1', 'c1', 'e2'])->and($steps[3]['outcome'])->toBe('yes');
});

it('waits for app events from the track API, ends at goals, and times out down No', function () {
    $id = readyJourney($this, 'abandoned_checkout');
    $this->postJson(jr($this, "$id/publish"))->assertOk();
    [, $key] = ApiKey::generate($this->ws->id, 'app', 'full', null);
    $api = fn (array $body) => $this->flushHeaders()->withHeaders(['Authorization' => "Bearer $key", 'Accept' => 'application/json'])->postJson('/v1/events', $body);

    $api(['email' => 'Buyer@Example.com', 'event' => 'checkout_started', 'properties' => ['cart' => 89.5], 'contact' => ['first_name' => 'Bola']])->assertStatus(202);
    $api(['email' => 'browser@example.com', 'event' => 'checkout_started'])->assertStatus(202);
    $api(['email' => 'x@example.com', 'event' => 'email.opened'])->assertStatus(422);
    $buyer = Contact::where('email', 'buyer@example.com')->firstOrFail();
    expect($buyer->first_name)->toBe('Bola')->and(runOf($id, $buyer)->current_node)->toBe('f1')->and(runOf($id, $buyer)->waiting_for)->toBe('order_placed');

    $api(['email' => 'buyer@example.com', 'event' => 'order_placed'])->assertStatus(202); // within the hour
    expect(runOf($id, $buyer)->status)->toBe('goal')->and(sentTo('buyer@example.com'))->toBe([]);

    $this->travel(61)->minutes();
    $this->artisan('journeys:tick')->assertSuccessful();
    $browser = Contact::where('email', 'browser@example.com')->firstOrFail();
    expect(sentTo('browser@example.com'))->toBe(['e1'])->and(runOf($id, $browser)->current_node)->toBe('w1');

    // The goal also ends a run that is mid-wait, before the second reminder.
    $api(['email' => 'browser@example.com', 'event' => 'order_placed'])->assertStatus(202);
    $this->travel(2)->days();
    $this->artisan('journeys:tick')->assertSuccessful();
    expect(runOf($id, $browser)->status)->toBe('goal')->and(sentTo('browser@example.com'))->toBe(['e1'])
        ->and(DB::table('contact_events')->where('contact_id', $browser->id)->pluck('name')->all())->toContain('checkout_started', 'order_placed');
});

it('follows re-entry rules: once, cooldown and every time', function () {
    $graph = fn () => ['nodes' => [['id' => 't1', 'type' => 'trigger', 'title' => 'Ping', 'config' => ['trigger' => 'event', 'event' => 'ping']], ['id' => 'g1', 'type' => 'tag', 'config' => ['tag' => 'pinged']]],
        'edges' => [['from' => 't1', 'to' => 'g1']]];
    $id = $this->postJson(jr($this), ['name' => 'Pings'])->json('journey.id');
    $this->patchJson(jr($this, $id), ['graph' => $graph()])->assertOk();
    $this->postJson(jr($this, "$id/publish"))->assertOk();
    $c = addContact($this, 'p@example.com');
    $ev = app(JourneyEvents::class);
    $count = fn () => JourneyRun::where('journey_id', $id)->count();

    $ev->fire($c, 'ping');
    $ev->fire($c, 'ping');
    expect($count())->toBe(1)->and(DB::table('contact_tags')->where('contact_id', $c->id)->pluck('tag')->all())->toBe(['pinged']);

    $this->patchJson(jr($this, $id), ['settings' => ['reentry' => 'cooldown', 'cooldown_days' => 7]])->assertOk();
    $this->postJson(jr($this, "$id/publish"))->assertOk();
    $ev->fire($c, 'ping');
    expect($count())->toBe(1);
    $this->travel(8)->days();
    $ev->fire($c, 'ping');
    expect($count())->toBe(2);

    $this->patchJson(jr($this, $id), ['settings' => ['reentry' => 'always']])->assertOk();
    $this->postJson(jr($this, "$id/publish"))->assertOk();
    $ev->fire($c, 'ping');
    $ev->fire($c, 'ping');
    expect($count())->toBe(4);
});

it('keeps people on the version they started, and pause holds them where they are', function () {
    $id = $this->postJson(jr($this), ['name' => 'Versioned'])->json('journey.id');
    $g = fn (int $days, string $tag) => ['nodes' => [['id' => 't1', 'type' => 'trigger', 'config' => ['trigger' => 'event', 'event' => 'go']], ['id' => 'w1', 'type' => 'wait', 'config' => ['amount' => $days, 'unit' => 'days']],
        ['id' => 'k1', 'type' => 'tag', 'config' => ['tag' => $tag]]], 'edges' => [['from' => 't1', 'to' => 'w1'], ['from' => 'w1', 'to' => 'k1']]];
    $this->patchJson(jr($this, $id), ['graph' => $g(1, 'v1')])->assertOk();
    $this->postJson(jr($this, "$id/publish"))->assertOk();
    $early = addContact($this, 'early@example.com');
    app(JourneyEvents::class)->fire($early, 'go');

    $rev = Journey::find($id)->draft_rev;
    $this->patchJson(jr($this, $id), ['graph' => $g(3, 'v2'), 'base_rev' => $rev])->assertOk()->assertJsonPath('journey.unpublished', true);
    $this->patchJson(jr($this, $id), ['graph' => $g(3, 'v2'), 'base_rev' => $rev])->assertStatus(409); // a stale tab can't overwrite
    $this->postJson(jr($this, "$id/publish"))->assertOk()->assertJsonPath('journey.version', 2)->assertJsonPath('journey.unpublished', false);
    $late = addContact($this, 'late@example.com');
    app(JourneyEvents::class)->fire($late, 'go');

    $this->postJson(jr($this, "$id/pause"))->assertOk()->assertJsonPath('journey.status', 'paused');
    $this->travel(2)->days();
    $this->artisan('journeys:tick')->assertSuccessful();
    expect(runOf($id, $early)->status)->toBe('waiting'); // paused: nobody moves
    $this->postJson(jr($this, "$id/resume"))->assertOk();
    expect(runOf($id, $early)->status)->toBe('completed')
        ->and(DB::table('contact_tags')->where('contact_id', $early->id)->pluck('tag')->all())->toBe(['v1'])
        ->and(runOf($id, $late)->status)->toBe('waiting');
    $this->travel(2)->days();
    $this->artisan('journeys:tick')->assertSuccessful();
    expect(DB::table('contact_tags')->where('contact_id', $late->id)->pluck('tag')->all())->toBe(['v2']);
});

it('starts from contact changes: list, tag and field triggers, including changes made by other journeys', function () {
    $list = ContactList::create(['workspace_id' => $this->ws->id, 'name' => 'VIP']);
    $mk = function (string $name, array $trigger, array $step) {
        $id = $this->postJson(jr($this), ['name' => $name])->json('journey.id');
        $this->patchJson(jr($this, $id), ['graph' => ['nodes' => [['id' => 't1', 'type' => 'trigger', 'config' => $trigger], ['id' => 's1'] + $step], 'edges' => [['from' => 't1', 'to' => 's1']]]])->assertOk();
        $this->postJson(jr($this, "$id/publish"))->assertOk();

        return $id;
    };
    $a = $mk('List → field', ['trigger' => 'list_added', 'list_id' => $list->id], ['type' => 'field', 'config' => ['key' => 'tier', 'value' => 'gold']]);
    $b = $mk('Field → tag', ['trigger' => 'field_changed', 'field' => 'tier', 'to' => 'gold'], ['type' => 'tag', 'config' => ['tag' => 'gold-member']]);
    $c = $mk('Tag → list out', ['trigger' => 'tag_added', 'tag' => 'gold-member'], ['type' => 'list', 'config' => ['action' => 'remove', 'list_id' => $list->id]]);

    $p = addContact($this, 'vip@example.com', ['lists' => [$list->id]]);
    expect(Contact::find($p->id)->fields['tier'])->toBe('gold')
        ->and(DB::table('contact_tags')->where('contact_id', $p->id)->pluck('tag')->all())->toBe(['gold-member'])
        ->and(DB::table('contact_list')->where('contact_id', $p->id)->count())->toBe(0)
        ->and(JourneyRun::whereIn('journey_id', [$a, $b, $c])->where('status', 'completed')->count())->toBe(3);
    // A field changed to something else doesn't match "to: gold".
    app(ContactService::class)->setField(Contact::find($p->id), 'tier', 'silver');
    expect(JourneyRun::where('journey_id', $b)->count())->toBe(1);
});

it('starts from its own webhook URL, creating the contact, and ignores retried ids', function () {
    $id = $this->postJson(jr($this), ['name' => 'Zapier leads'])->json('journey.id');
    $url = '/v1/hooks/j/'.Journey::find($id)->hook_token;
    $this->patchJson(jr($this, $id), ['graph' => ['nodes' => [['id' => 't1', 'type' => 'trigger', 'config' => ['trigger' => 'webhook']], ['id' => 's1', 'type' => 'tag', 'config' => ['tag' => 'lead']]],
        'edges' => [['from' => 't1', 'to' => 's1']], ], 'settings' => ['reentry' => 'always']])->assertOk();
    $this->flushHeaders()->postJson($url, ['email' => 'lead@example.com'])->assertStatus(409); // not on yet
    $this->postJson(jr($this, "$id/publish"))->assertOk();
    $this->flushHeaders()->postJson($url, ['email' => 'Lead@Example.com', 'first_name' => 'Lola', 'id' => 'zap-1', 'properties' => ['form' => 'pricing']])->assertStatus(202);
    $this->flushHeaders()->postJson($url, ['email' => 'lead@example.com', 'id' => 'zap-1'])->assertStatus(202);
    $this->flushHeaders()->postJson('/v1/hooks/j/'.str_repeat('a', 32), ['email' => 'x@example.com'])->assertNotFound();
    $lead = Contact::where('email', 'lead@example.com')->firstOrFail();
    expect($lead->first_name)->toBe('Lola')->and($lead->source)->toBe('webhook')->and(JourneyRun::where('journey_id', $id)->count())->toBe(1)
        ->and(runOf($id, $lead)->context['properties'])->toBe(['form' => 'pricing']);
});

it('fires date triggers at the chosen hour in each contact’s time zone, once a year', function () {
    $id = readyJourney($this, 'birthday');
    $this->postJson(jr($this, "$id/publish"))->assertOk();
    $this->travelTo('2026-11-03 07:30:00'); // 08:30 in Lagos (UTC+1), 07:30 in London
    $lagos = addContact($this, 'lagos@example.com', ['fields' => ['birthday' => '1994-11-03', 'timezone' => 'Africa/Lagos']]);
    $london = addContact($this, 'london@example.com', ['fields' => ['birthday' => '11-03', 'timezone' => 'Europe/London']]);
    addContact($this, 'other@example.com', ['fields' => ['birthday' => '1990-05-01']]);

    $this->artisan('journeys:dates')->assertSuccessful();
    expect(sentTo('lagos@example.com'))->toBe([]);
    $this->travelTo('2026-11-03 08:10:00'); // 09:10 Lagos
    $this->artisan('journeys:dates')->assertSuccessful();
    $this->artisan('journeys:dates')->assertSuccessful(); // twice in the hour: still once
    expect(sentTo('lagos@example.com'))->toBe(['e1'])->and(sentTo('london@example.com'))->toBe([]);
    $this->travelTo('2026-11-03 09:05:00'); // 09:05 London
    $this->artisan('journeys:dates')->assertSuccessful();
    expect(sentTo('london@example.com'))->toBe(['e1'])->and(sentTo('other@example.com'))->toBe([]);
    $this->travelTo('2027-11-03 08:10:00');
    $this->artisan('journeys:dates')->assertSuccessful();
    expect(sentTo('lagos@example.com'))->toBe(['e1', 'e1']);
});

it('waits for the send window in the contact’s time zone', function () {
    $id = $this->postJson(jr($this), ['name' => 'Window'])->json('journey.id');
    $this->patchJson(jr($this, $id), ['graph' => ['nodes' => [['id' => 't1', 'type' => 'trigger', 'config' => ['trigger' => 'event', 'event' => 'go']],
        ['id' => 'w1', 'type' => 'window', 'config' => ['days' => [1, 2, 3, 4, 5], 'from' => '09:00', 'to' => '17:00']], ['id' => 'k1', 'type' => 'tag', 'config' => ['tag' => 'sent']]],
        'edges' => [['from' => 't1', 'to' => 'w1'], ['from' => 'w1', 'to' => 'k1']]]])->assertOk();
    $this->postJson(jr($this, "$id/publish"))->assertOk();
    $this->travelTo('2026-10-31 12:00:00'); // Saturday
    $c = addContact($this, 'w@example.com', ['fields' => ['timezone' => 'America/New_York']]);
    app(JourneyEvents::class)->fire($c, 'go');
    expect(runOf($id, $c)->wake_at->toDateTimeString())->toBe('2026-11-02 14:00:00'); // Monday 09:00 New York (EST, UTC−5)
    $this->travelTo('2026-11-02 14:00:30');
    $this->artisan('journeys:tick')->assertSuccessful();
    expect(runOf($id, $c)->status)->toBe('completed');
});

it('starts from real opens only, never from Apple or scanner opens', function () {
    $id = $this->postJson(jr($this), ['name' => 'Engaged'])->json('journey.id');
    $this->patchJson(jr($this, $id), ['graph' => ['nodes' => [['id' => 't1', 'type' => 'trigger', 'config' => ['trigger' => 'email_opened']], ['id' => 'k1', 'type' => 'tag', 'config' => ['tag' => 'engaged']]],
        'edges' => [['from' => 't1', 'to' => 'k1']]]])->assertOk();
    $this->postJson(jr($this, "$id/publish"))->assertOk();
    $c = addContact($this, 'reader@example.com');
    $mailer = app(Mailer::class);
    $m = $mailer->submit($this->ws, [$mailer->validate($this->ws, ['from' => 'news@acme.com', 'to' => 'reader@example.com', 'subject' => 'Hi', 'html' => '<p>Hi</p>'])], 'api')[0]->refresh();
    $feed = function (string $type, array $section, string $at) use ($m) {
        $event = ['eventType' => $type, 'mail' => ['messageId' => $m->ses_message_id, 'timestamp' => $at, 'destination' => [$m->to_email], 'tags' => ['sl_message' => [$m->id]]], lcfirst($type) => $section + ['timestamp' => $at]];
        app(EventProcessor::class)->apply($this->ws->id, $event, DB::table('ses_events')->insertGetId(['workspace_id' => $this->ws->id, 'event_type' => $type, 'sns_message_id' => (string) Str::uuid(), 'payload' => '{}', 'created_at' => now()]));
    };
    $feed('Delivery', [], '2026-10-24T09:00:00Z');
    $feed('Open', ['userAgent' => 'Mozilla/5.0'], '2026-10-24T09:00:01Z'); // Apple Mail Privacy Protection
    expect(JourneyRun::where('journey_id', $id)->count())->toBe(0);
    $feed('Open', ['userAgent' => J_HUMAN_UA], '2026-10-24T10:00:00Z');
    expect(runOf($id, $c)->status)->toBe('completed');
});

it('previews a contact’s path without sending, and keeps viewers read-only', function () {
    $id = readyJourney($this);
    $c = addContact($this, 'preview@example.com');
    $t = $this->postJson(jr($this, "$id/test"), ['email' => 'preview@example.com'])->assertOk()->json('test');
    expect($t['ready'])->toBeTrue()->and(array_column($t['steps'], 'node'))->toBe(['t1', 'e1', 'w1', 'c1', 'e3'])
        ->and($t['steps'][1]['text'])->toContain('Sends “Welcome, {{ first_name | there }}”')
        ->and(Message::count())->toBe(0)->and(JourneyRun::count())->toBe(0);
    $this->postJson(jr($this, "$id/test"), ['email' => 'nobody@example.com'])->assertNotFound();

    switchTo($this, 'viewer@example.com');
    $this->getJson(jr($this, $id))->assertOk();
    $this->getJson(jr($this, "$id/stats"))->assertOk();
    $this->patchJson(jr($this, $id), ['name' => 'Nope'])->assertForbidden();
    $this->postJson(jr($this, "$id/publish"))->assertForbidden();
    $this->postJson(jr($this), ['name' => 'Nope'])->assertForbidden();
});

it('archives instead of deleting journeys that ran, and exits everyone still inside', function () {
    $id = readyJourney($this);
    $this->postJson(jr($this, "$id/publish"))->assertOk();
    $c = addContact($this, 'inside@example.com');
    expect(runOf($id, $c)->status)->toBe('waiting');
    $this->deleteJson(jr($this, $id))->assertOk();
    expect(Journey::find($id)->status)->toBe('archived')->and(runOf($id, $c)->status)->toBe('exited');
    $this->travel(3)->days();
    $this->artisan('journeys:tick')->assertSuccessful();
    expect(sentTo('inside@example.com'))->toBe(['e1']);
    $draft = $this->postJson(jr($this), ['name' => 'Never ran'])->json('journey.id');
    $this->deleteJson(jr($this, $draft))->assertOk();
    expect(Journey::find($draft))->toBeNull();
});

it('exports and erases a contact’s event history with the contact', function () {
    $c = addContact($this, 'gone@example.com');
    app(JourneyEvents::class)->fire($c, 'viewed_pricing', ['plan' => 'Pro', 'note' => 'personal']);
    expect(DB::table('contact_events')->where('contact_id', $c->id)->count())->toBe(2); // contact.created + the event
    expect(collect(app(ContactService::class)->export($c)['activity'])->pluck('name')->all())->toBe(['contact.created', 'viewed_pricing']);
    app(ContactService::class)->erase($c);
    expect(DB::table('contact_events')->where('contact_id', $c->id)->count())->toBe(0);
});

it('shows the journey signing secret to owners and admins only, so receivers can verify "Send webhook" steps', function () {
    $id = $this->postJson(jr($this), ['recipe' => 'welcome'])->assertCreated()->json('journey.id');
    expect($this->getJson(jr($this, $id))->json('journey'))->not->toHaveKey('secret');
    $secret = $this->getJson(jr($this, "$id/secret"))->assertOk()->json('secret');
    expect($secret)->toStartWith('whsec_')->toBe(Journey::find($id)->secret);
    switchTo($this, 'viewer@example.com');
    $this->getJson(jr($this, "$id/secret"))->assertForbidden();
});

it('takes events in batches, all checked first, and never records the same event id twice', function () {
    [, $key] = ApiKey::generate($this->ws->id, 'full', 'full', null);
    $h = ['Authorization' => "Bearer $key", 'Accept' => 'application/json'];
    $this->flushHeaders()->withHeaders($h)->postJson('/v1/events/batch', [['email' => 'a@example.com', 'event' => 'app.creator_live'], ['email' => 'b@example.com', 'event' => 'email.fake']])
        ->assertStatus(422)->assertJsonPath('error.fields', ['1.event' => 'Invalid event name.']);
    expect(DB::table('contact_events')->count())->toBe(0);

    $batch = [['email' => 'a@example.com', 'event' => 'app.creator_live', 'id' => 'live-7:a', 'properties' => ['creator' => 'lola']],
        ['email' => 'b@example.com', 'event' => 'app.creator_live', 'id' => 'live-7:b', 'properties' => ['creator' => 'lola']]];
    $this->flushHeaders()->withHeaders($h)->postJson('/v1/events/batch', $batch)->assertStatus(202)->assertJson(['accepted' => 2, 'duplicates' => 0]);
    $this->flushHeaders()->withHeaders($h)->postJson('/v1/events/batch', $batch)->assertStatus(202)->assertJson(['accepted' => 0, 'duplicates' => 2]); // a retry after a timeout
    $this->flushHeaders()->withHeaders($h)->postJson('/v1/events', $batch[0])->assertStatus(202)->assertJson(['duplicate' => true]);
    expect(DB::table('contact_events')->where('name', 'app.creator_live')->count())->toBe(2)->and(Contact::where('workspace_id', $this->ws->id)->count())->toBe(2);
});

it('lets integrations keep their own lists: find-or-create by name, join and leave, and resubscribe on request', function () {
    [, $key] = ApiKey::generate($this->ws->id, 'full', 'full', null);
    $h = ['Authorization' => "Bearer $key", 'Accept' => 'application/json'];
    $id = $this->flushHeaders()->withHeaders($h)->postJson('/v1/lists', ['name' => 'Newsletter'])->assertCreated()->json('list.id');
    expect($this->flushHeaders()->withHeaders($h)->postJson('/v1/lists', ['name' => 'Newsletter'])->assertOk()->json('list.id'))->toBe($id); // the same list again
    expect($this->flushHeaders()->withHeaders($h)->getJson('/v1/lists')->json('data.0.name'))->toBe('Newsletter');

    $this->flushHeaders()->withHeaders($h)->postJson('/v1/contacts', ['email' => 'fan@example.com', 'lists' => [$id]])->assertCreated()->assertJsonPath('contact.lists', [$id]);
    $this->flushHeaders()->withHeaders($h)->postJson('/v1/contacts', ['email' => 'fan@example.com', 'remove_lists' => [$id], 'unsubscribed' => true])->assertOk()
        ->assertJsonPath('contact.lists', [])->assertJsonPath('contact.status', 'unsubscribed');
    $this->flushHeaders()->withHeaders($h)->postJson('/v1/contacts', ['email' => 'fan@example.com', 'unsubscribed' => true, 'resubscribe' => true])->assertStatus(422);
    $this->flushHeaders()->withHeaders($h)->postJson('/v1/contacts', ['email' => 'fan@example.com', 'resubscribe' => true, 'lists' => [$id]])->assertOk()->assertJsonPath('contact.status', 'subscribed');

    Suppression::add($this->ws->id, 'bounced@example.com', 'hard_bounce');
    $this->flushHeaders()->withHeaders($h)->postJson('/v1/contacts', ['email' => 'bounced@example.com', 'resubscribe' => true])->assertCreated()->assertJsonPath('contact.status', 'bounced'); // never undoes a bounce
});

it('fills journey emails with the triggering event’s properties, e.g. “{{ creator_name }} is live”', function () {
    $id = readyJourney($this, 'abandoned_checkout');
    $e1 = collect(Journey::find($id)->graph['nodes'])->firstWhere('id', 'e1');
    $this->putJson(jr($this, "$id/emails/{$e1['id']}"), ['subject' => '{{ first_name | Hi }}, your {{ cart }} cart from {{ shop | us }} is waiting'])->assertOk();
    $this->postJson(jr($this, "$id/publish"))->assertOk();
    [, $key] = ApiKey::generate($this->ws->id, 'app', 'full', null);
    $this->flushHeaders()->withHeaders(['Authorization' => "Bearer $key", 'Accept' => 'application/json'])->postJson('/v1/events', [
        'email' => 'bola@example.com', 'event' => 'checkout_started', 'contact' => ['first_name' => 'Bola'],
        'properties' => ['cart' => 89.5, 'shop' => 'Lola’s', 'items' => [['sku' => 'x']], 'bad key' => 'ignored'],
    ])->assertStatus(202);
    $this->travel(61)->minutes();
    $this->artisan('journeys:tick')->assertSuccessful();
    expect(Message::where('to_email', 'bola@example.com')->value('subject'))->toBe('Bola, your 89.5 cart from Lola’s is waiting');
});

it('publishes every recipe once its emails have a design', function () {
    foreach (Recipes::KEYS as $key) {
        $id = readyJourney($this, $key);
        $this->postJson(jr($this, "$id/publish"))->assertOk()->assertJsonPath('journey.status', 'active');
    }
    expect(Journey::where('workspace_id', $this->ws->id)->where('status', 'active')->count())->toBe(count(Recipes::KEYS));
});
