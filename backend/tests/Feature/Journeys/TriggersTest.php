<?php

use App\Models\Contact;
use App\Models\JourneyRun;
use App\Models\Topic;
use App\Services\Contacts\ContactService;
use App\Services\Journeys\JourneyEvents;
use App\Services\Journeys\TriggerScan;
use App\Services\Sending\Mailer;
use App\Services\Ses\DnsLookup;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeAws;
use Tests\Support\FakeDns;

// Triggers batch 4: segments, topics, went quiet, inactive in app, on a schedule.

beforeEach(function () {
    FakeAws::install();
    $this->app->instance(DnsLookup::class, new FakeDns);
    [$this->ws] = sesWorkspace($this);
    sesConnect($this, $this->ws)->assertOk();
    JourneyEvents::flush();
});

function trigUrl($test, string $path): string
{
    return "/v1/workspaces/{$test->ws->id}/$path";
}

/** A live journey: the trigger, then one tag step. */
function trigJourney($test, array $trigger, string $reentry = 'always'): string
{
    $id = $test->postJson(trigUrl($test, 'journeys'), ['name' => 'T '.$trigger['trigger']])->assertCreated()->json('journey.id');
    $test->patchJson(trigUrl($test, "journeys/$id"), ['graph' => ['nodes' => [['id' => 't1', 'type' => 'trigger', 'config' => $trigger], ['id' => 'k1', 'type' => 'tag', 'config' => ['tag' => 'done']]],
        'edges' => [['from' => 't1', 'to' => 'k1']]], 'settings' => ['reentry' => $reentry]])->assertOk();
    $test->postJson(trigUrl($test, "journeys/$id/publish"))->assertOk();
    JourneyEvents::flush();

    return $id;
}

function trigContact($test, string $email, array $extra = []): Contact
{
    return app(ContactService::class)->upsert($test->ws, ['email' => $email] + $extra, 'api');
}

function trigRuns(string $journeyId): array
{
    return JourneyRun::where('journey_id', $journeyId)->join('contacts', 'contacts.id', '=', 'journey_runs.contact_id')->orderBy('contacts.email')->pluck('contacts.email')->all();
}

it('starts journeys when contacts join or leave a segment, from a quiet baseline', function () {
    $seg = $this->postJson(trigUrl($this, 'segments'), ['name' => 'VIPs', 'rules' => ['match' => 'all', 'conditions' => [['field' => 'tag', 'op' => 'has', 'value' => 'vip']]]])->assertCreated()->json('segment.id');
    $already = trigContact($this, 'already@example.com', ['tags' => ['vip']]);
    $joined = trigJourney($this, ['trigger' => 'segment_joined', 'segment_id' => $seg]);
    $left = trigJourney($this, ['trigger' => 'segment_left', 'segment_id' => $seg]);

    $this->artisan('journeys:segments')->assertSuccessful(); // baseline: "already" is a member, nobody starts
    expect(trigRuns($joined))->toBe([])->and(DB::table('segment_members')->where('segment_id', $seg)->count())->toBe(1);

    $new = trigContact($this, 'new@example.com', ['tags' => ['vip']]);
    trigContact($this, 'other@example.com', ['tags' => ['customer']]);
    $this->artisan('journeys:segments')->assertSuccessful();
    $this->artisan('journeys:segments')->assertSuccessful(); // twice: still once
    expect(trigRuns($joined))->toBe(['new@example.com'])->and(trigRuns($left))->toBe([])
        ->and(DB::table('contact_events')->where('contact_id', $new->id)->where('name', 'contact.segment_joined')->count())->toBe(1);

    app(ContactService::class)->untag($already, ['vip']);
    $this->artisan('journeys:segments')->assertSuccessful();
    expect(trigRuns($left))->toBe(['already@example.com'])->and(trigRuns($joined))->toBe(['new@example.com']);

    // New rules are a new baseline: matching the new rules isn't "joining".
    $this->patchJson(trigUrl($this, "segments/$seg"), ['rules' => ['match' => 'all', 'conditions' => [['field' => 'tag', 'op' => 'has', 'value' => 'customer']]]])->assertOk();
    $this->artisan('journeys:segments')->assertSuccessful();
    expect(trigRuns($joined))->toBe(['new@example.com'])->and(trigRuns($left))->toBe(['already@example.com'])
        ->and(DB::table('segment_members')->where('segment_id', $seg)->pluck('contact_id')->all())->toBe([Contact::where('email', 'other@example.com')->value('id')]);

    // A segment a journey starts from can't be deleted; once nothing watches it, its members are forgotten.
    $this->deleteJson(trigUrl($this, "segments/$seg"))->assertStatus(409)->assertJsonPath('error.code', 'in_use');
    $this->postJson(trigUrl($this, "journeys/$joined/archive"))->assertOk();
    $this->postJson(trigUrl($this, "journeys/$left/archive"))->assertOk();
    $this->artisan('journeys:segments')->assertSuccessful();
    expect(DB::table('segment_members')->count())->toBe(0)->and(DB::table('segments')->value('tracked_hash'))->toBeNull();
    $this->deleteJson(trigUrl($this, "segments/$seg"))->assertOk();
});

it('starts a journey when someone turns a topic off, on the unsubscribe page or on their contact', function () {
    $news = Topic::create(['workspace_id' => $this->ws->id, 'name' => 'Newsletter']);
    $beta = Topic::create(['workspace_id' => $this->ws->id, 'name' => 'Beta invites', 'default_subscribed' => false]);
    $any = trigJourney($this, ['trigger' => 'topic_unsubscribed']);
    $onlyNews = trigJourney($this, ['trigger' => 'topic_unsubscribed', 'topic_id' => $news->id]);

    $mailer = app(Mailer::class);
    $m = $mailer->submit($this->ws, [$mailer->validate($this->ws, ['from' => 'news@acme.com', 'to' => 'reader@example.com', 'subject' => 'x', 'text' => 'x', 'topic' => $news->id])], 'api')[0];
    $this->postJson("/v1/unsubscribe/{$m->unsubscribe_token}", ['topic_id' => $news->id])->assertOk();
    $this->postJson("/v1/unsubscribe/{$m->unsubscribe_token}", ['topic_id' => $news->id])->assertOk(); // already off: nothing new
    expect(trigRuns($any))->toBe(['reader@example.com'])->and(trigRuns($onlyNews))->toBe(['reader@example.com']);

    // Beta is opt-in: turning it off for someone who never opted in isn't an unsubscribe.
    $c = trigContact($this, 'member@example.com');
    $this->patchJson(trigUrl($this, "contacts/{$c->id}"), ['topics' => [$beta->id => false]])->assertOk();
    expect(trigRuns($any))->toBe(['reader@example.com']);
    $this->patchJson(trigUrl($this, "contacts/{$c->id}"), ['topics' => [$beta->id => true]])->assertOk();
    $this->patchJson(trigUrl($this, "contacts/{$c->id}"), ['topics' => [$beta->id => false]])->assertOk();
    expect(trigRuns($any))->toBe(['member@example.com', 'reader@example.com'])->and(trigRuns($onlyNews))->toBe(['reader@example.com']);

    $this->deleteJson(trigUrl($this, "topics/{$news->id}"))->assertStatus(409);
});

it('starts “went quiet” once per quiet spell, only for people still being emailed', function () {
    $id = trigJourney($this, ['trigger' => 'went_quiet', 'days' => 60]);
    $quiet = trigContact($this, 'quiet@example.com');
    $never = trigContact($this, 'never@example.com');
    $active = trigContact($this, 'active@example.com');
    $unmailed = trigContact($this, 'unmailed@example.com');
    $fresh = trigContact($this, 'fresh@example.com');
    Contact::query()->update(['created_at' => now()->subDays(200), 'last_sent_at' => now()->subDays(2)]);
    $quiet->forceFill(['last_opened_at' => now()->subDays(70)])->saveQuietly();
    $active->forceFill(['last_clicked_at' => now()->subDays(5)])->saveQuietly();
    $unmailed->forceFill(['last_sent_at' => now()->subDays(90)])->saveQuietly();
    $fresh->forceFill(['created_at' => now()->subDays(10)])->saveQuietly();

    expect(app(TriggerScan::class)->quiet())->toBe(2)->and(app(TriggerScan::class)->quiet())->toBe(0);
    expect(trigRuns($id))->toBe(['never@example.com', 'quiet@example.com'])
        ->and(JourneyRun::where('contact_id', $quiet->id)->value('context')['properties']['days'])->toBe(60);

    // Engages again, then goes quiet again: a new spell.
    $quiet->forceFill(['last_opened_at' => now()])->saveQuietly();
    $this->travel(61)->days();
    Contact::query()->update(['last_sent_at' => now()->subDay()]);
    app(TriggerScan::class)->quiet();
    expect(JourneyRun::where('journey_id', $id)->where('contact_id', $quiet->id)->count())->toBe(2)
        ->and(JourneyRun::where('journey_id', $id)->where('contact_id', $never->id)->count())->toBe(1);
});

it('starts “inactive in app” for people who stopped sending the activity event', function () {
    $id = trigJourney($this, ['trigger' => 'inactive', 'activity' => 'app_opened', 'days' => 14]);
    $gone = trigContact($this, 'gone@example.com');
    $here = trigContact($this, 'here@example.com');
    trigContact($this, 'neverused@example.com');
    $ev = app(JourneyEvents::class);
    $ev->fire($gone, 'app_opened', [], 'api', now()->subDays(20));
    $ev->fire($here, 'app_opened', [], 'api', now()->subDays(20));
    $ev->fire($here, 'app_opened', [], 'api', now()->subDays(3));

    $this->artisan('journeys:scan')->assertSuccessful();
    $this->artisan('journeys:scan')->assertSuccessful();
    expect(trigRuns($id))->toBe(['gone@example.com'])->and(JourneyRun::where('journey_id', $id)->value('context')['properties']['activity'])->toBe('app_opened');

    $ev->fire($gone, 'app_opened', [], 'api'); // came back…
    $this->travel(15)->days(); // …and left again
    $this->artisan('journeys:scan')->assertSuccessful();
    expect(trigRuns($id))->toBe(['gone@example.com', 'gone@example.com', 'here@example.com']);
});

it('runs a weekly or monthly schedule at the hour in each contact’s time zone, once a day', function () {
    $weekly = trigJourney($this, ['trigger' => 'schedule', 'every' => 'week', 'weekday' => 1, 'hour' => 9]);
    $monthly = trigJourney($this, ['trigger' => 'schedule', 'every' => 'month', 'monthday' => 31, 'hour' => 9,
        'filter' => ['match' => 'all', 'conditions' => [['field' => 'tag', 'op' => 'has', 'value' => 'monthly']]]]);
    trigContact($this, 'lagos@example.com', ['fields' => ['timezone' => 'Africa/Lagos'], 'tags' => ['monthly']]);
    trigContact($this, 'ny@example.com', ['fields' => ['timezone' => 'America/New_York']]);
    trigContact($this, 'gone@example.com', ['unsubscribed' => true]);

    $this->travelTo('2026-11-02 08:20:00'); // Monday 09:20 in Lagos, 03:20 in New York
    $this->artisan('journeys:scan')->assertSuccessful();
    $this->artisan('journeys:scan')->assertSuccessful();
    expect(trigRuns($weekly))->toBe(['lagos@example.com']);
    $this->travelTo('2026-11-02 14:05:00'); // 09:05 in New York
    $this->artisan('journeys:scan')->assertSuccessful();
    expect(trigRuns($weekly))->toBe(['lagos@example.com', 'ny@example.com']);
    $this->travelTo('2026-11-03 08:20:00'); // Tuesday
    $this->artisan('journeys:scan')->assertSuccessful();
    expect(trigRuns($weekly))->toHaveCount(2)->and(trigRuns($monthly))->toBe([]);

    $this->travelTo('2026-11-30 08:20:00'); // November has 30 days: "the 31st" is its last day; only the tagged contact
    $this->artisan('journeys:scan')->assertSuccessful();
    expect(trigRuns($monthly))->toBe(['lagos@example.com']);
});

it('lists what each new trigger is missing before it can go live', function () {
    $id = $this->postJson(trigUrl($this, 'journeys'), ['name' => 'Half done'])->json('journey.id');
    $graph = fn (array $c) => ['nodes' => [['id' => 't1', 'type' => 'trigger', 'config' => $c], ['id' => 'k1', 'type' => 'tag', 'config' => ['tag' => 'x']]], 'edges' => [['from' => 't1', 'to' => 'k1']]];
    $problem = function (array $c) use ($id, $graph) {
        $this->patchJson(trigUrl($this, "journeys/$id"), ['graph' => $graph($c)])->assertOk();

        return $this->postJson(trigUrl($this, "journeys/$id/publish"))->assertStatus(422)->json('error.details.problems.0.message');
    };
    expect($problem(['trigger' => 'segment_joined', 'segment_id' => '01jbadbadbadbadbadbadbadba']))->toContain('choose a segment')
        ->and($problem(['trigger' => 'topic_unsubscribed', 'topic_id' => '01jbadbadbadbadbadbadbadba']))->toContain('choose a topic')
        ->and($problem(['trigger' => 'went_quiet', 'days' => 0]))->toContain('how many days')
        ->and($problem(['trigger' => 'inactive', 'days' => 14, 'activity' => 'email.opened']))->toContain('app_opened')
        ->and($problem(['trigger' => 'schedule', 'every' => 'year', 'hour' => 9]))->toContain('every day, week or month');
});

it('offers a win-back recipe built on “went quiet”', function () {
    $id = $this->postJson(trigUrl($this, 'journeys'), ['recipe' => 'win_back'])->assertCreated()->json('journey.id');
    $this->getJson(trigUrl($this, "journeys/$id"))->assertOk()->assertJsonPath('journey.graph.nodes.0.config.trigger', 'went_quiet');
    $this->getJson(trigUrl($this, 'journeys'))->assertJsonFragment(['key' => 'win_back', 'trigger' => 'Went quiet: 60 days']);
});
