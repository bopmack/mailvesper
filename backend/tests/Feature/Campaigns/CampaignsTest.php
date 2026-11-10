<?php

use App\Jobs\SendCampaignBatch;
use App\Jobs\StartCampaign;
use App\Models\Campaign;
use App\Models\ContactList;
use App\Models\Message;
use App\Models\Suppression;
use App\Models\Topic;
use App\Services\Campaigns\CampaignService;
use App\Services\Contacts\ContactService;
use App\Services\Ses\DnsLookup;
use App\Services\Ses\EventProcessor;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeAws;
use Tests\Support\FakeDns;

beforeEach(function () {
    $this->aws = FakeAws::install();
    $this->app->instance(DnsLookup::class, new FakeDns);
    [$this->ws] = sesWorkspace($this, ['viewer']);
    sesConnect($this, $this->ws)->assertOk();
    $this->list = ContactList::create(['workspace_id' => $this->ws->id, 'name' => 'Newsletter']);
    $this->doc = json_decode(file_get_contents(base_path('tests/fixtures/design.json')), true);
});

function people($test, int $n, string $domain = 'example.com', array $extra = []): void
{
    $svc = app(ContactService::class);
    foreach (range(1, $n) as $i) {
        $svc->upsert($test->ws, ['email' => "p$i@$domain", 'first_name' => "P$i", 'lists' => [$test->list->id]] + $extra, 'import');
    }
}

function cmp($test, string $path = ''): string
{
    return "/v1/workspaces/{$test->ws->id}/campaigns".($path ? "/$path" : '');
}

/** A complete draft, ready to send. */
function readyCampaign($test, array $over = []): string
{
    $id = $test->postJson(cmp($test), ['name' => 'October newsletter'])->assertCreated()->json('campaign.id');
    $test->patchJson(cmp($test, $id), $over + ['audience_type' => 'list', 'audience_id' => $test->list->id, 'from_email' => 'news@acme.com', 'from_name' => 'Acme',
        'subject' => 'October at Acme, {{ first_name | friend }}', 'preheader' => 'Three things you asked for', 'doc' => $test->doc])->assertOk();

    return $id;
}

it('explains what is missing before a campaign can go out', function () {
    $id = $this->postJson(cmp($this), ['name' => 'Draft'])->json('campaign.id');
    expect(collect($this->getJson(cmp($this, $id))->json('campaign.problems'))->pluck('key')->all())->toBe(['audience', 'from', 'subject', 'content']);
    $this->postJson(cmp($this, "$id/send"), ['mode' => 'now'])->assertStatus(422)->assertJsonPath('error.code', 'not_ready');

    $noFooter = $this->doc;
    array_pop($noFooter['rows']); // drop the footer (unsubscribe link)
    people($this, 2);
    $this->patchJson(cmp($this, $id), ['audience_type' => 'list', 'audience_id' => $this->list->id, 'from_email' => 'news@gmail.com', 'subject' => 'Hi', 'doc' => $noFooter])->assertOk()
        ->assertJsonPath('campaign.problems.0.message', 'The sender address isn’t on a verified domain.')
        ->assertJsonPath('campaign.problems.1.message', 'Add an unsubscribe link (the Footer block has one). Marketing email must have it.');
});

it('sends now to the frozen audience, skipping the unsubscribed, dormant and topic opt-outs', function () {
    people($this, 5);
    $svc = app(ContactService::class);
    $svc->upsert($this->ws, ['email' => 'gone@example.com', 'lists' => [$this->list->id]], 'import');
    Suppression::add($this->ws->id, 'gone@example.com', 'unsubscribe');
    $svc->upsert($this->ws, ['email' => 'sleepy@example.com', 'lists' => [$this->list->id]], 'import');
    DB::table('contacts')->where('email', 'sleepy@example.com')->update(['dormant' => true]);
    $topic = Topic::create(['workspace_id' => $this->ws->id, 'name' => 'Newsletter']);
    DB::table('contact_topic')->insert(['topic_id' => $topic->id, 'contact_id' => DB::table('contacts')->where('email', 'p5@example.com')->value('id'), 'subscribed' => false]);

    $id = readyCampaign($this, ['topic_id' => $topic->id]);
    expect($this->getJson(cmp($this, $id))->json('campaign.estimate'))->toBe(['total' => 5, 'dormant' => 1]);
    $this->postJson(cmp($this, "$id/send"), ['mode' => 'now'])->assertOk();

    $c = Campaign::find($id);
    expect($c->status)->toBe('sent')->and($c->recipients_count)->toBe(5)
        ->and(DB::table('campaign_recipients')->where('campaign_id', $id)->pluck('status', 'email')->all())->toMatchArray(['p1@example.com' => 'sent', 'p5@example.com' => 'skipped'])
        ->and(Message::where('campaign_id', $id)->where('status', 'sent')->count())->toBe(4)
        ->and(Message::where('to_email', 'p1@example.com')->value('subject'))->toBe('October at Acme, P1');
    $req = collect($this->aws->argsFor('SendEmail'))->first();
    expect(collect($req['EmailTags'])->pluck('Value', 'Name')->all())->toMatchArray(['sl_campaign' => $id, 'campaign' => $id])
        ->and(collect($req['Content']['Simple']['Headers'])->pluck('Name'))->toContain('List-Unsubscribe');
    $this->patchJson(cmp($this, $id), ['subject' => 'Too late'])->assertStatus(422)->assertJsonPath('error.code', 'not_editable');
});

it('pauses mid-send, pulling back emails not yet handed to SES, then resumes', function () {
    config(['queue.default' => 'database']);
    people($this, 250);
    $id = readyCampaign($this);
    $this->postJson(cmp($this, "$id/send"), ['mode' => 'now'])->assertOk();
    (new StartCampaign($id))->handle(app(CampaignService::class)); // snapshot
    app()->call([new SendCampaignBatch($id), 'handle']);                                   // first batch → 200 queued messages
    expect(Message::where('campaign_id', $id)->count())->toBe(200)->and(DB::table('campaign_recipients')->where('campaign_id', $id)->where('status', 'pending')->count())->toBe(50);

    $this->postJson(cmp($this, "$id/pause"))->assertOk()->assertJsonPath('campaign.status', 'paused');
    expect(Message::where('campaign_id', $id)->count())->toBe(0)                           // nothing reached SES yet, so nothing goes out
        ->and(DB::table('campaign_recipients')->where('campaign_id', $id)->where('status', 'pending')->count())->toBe(250);

    $this->postJson(cmp($this, "$id/resume"))->assertOk()->assertJsonPath('campaign.status', 'sending');
    $this->artisan('queue:work', ['--stop-when-empty' => true, '--queue' => 'default'])->assertSuccessful();
    // As the scheduler does each minute: later workers run any delayed retry and the check that closes the campaign.
    for ($i = 0; $i < 5 && Campaign::find($id)->status === 'sending'; $i++) {
        $this->travel(20)->seconds();
        $this->artisan('queue:work', ['--stop-when-empty' => true, '--queue' => 'default'])->assertSuccessful();
    }
    expect(Campaign::find($id)->status)->toBe('sent')->and(Message::where('campaign_id', $id)->where('status', 'sent')->count())->toBe(250)
        ->and($this->aws->argsFor('SendEmail'))->toHaveCount(250);
});

it('schedules for later, starts on time, and can go back to draft before then', function () {
    people($this, 3);
    $id = readyCampaign($this);
    $this->postJson(cmp($this, "$id/send"), ['mode' => 'scheduled', 'at' => '2020-01-01 09:00', 'timezone' => 'Africa/Lagos'])->assertStatus(422);
    $at = now()->addDay()->setTime(9, 30)->format('Y-m-d H:i');
    $this->postJson(cmp($this, "$id/send"), ['mode' => 'scheduled', 'at' => $at, 'timezone' => 'Africa/Lagos'])->assertOk()->assertJsonPath('campaign.status', 'scheduled');
    expect(Campaign::find($id)->scheduled_at->toDateTimeString())->toBe(now()->addDay()->setTime(8, 30)->toDateTimeString()); // Lagos is UTC+1
    $this->artisan('campaigns:tick');
    expect(Campaign::find($id)->status)->toBe('scheduled');
    $this->travelTo(now()->addDay()->setTime(8, 31));
    $this->artisan('campaigns:tick');
    expect(Campaign::find($id)->status)->toBe('sent')->and(Message::where('campaign_id', $id)->count())->toBe(3);

    $id2 = readyCampaign($this);
    $this->postJson(cmp($this, "$id2/send"), ['mode' => 'scheduled', 'at' => now()->addHours(5)->format('Y-m-d H:i'), 'timezone' => 'UTC'])->assertOk();
    $this->postJson(cmp($this, "$id2/unschedule"))->assertOk()->assertJsonPath('campaign.status', 'draft');
});

it('sends at 09:00 in each recipient’s own time zone', function () {
    $svc = app(ContactService::class);
    $svc->upsert($this->ws, ['email' => 'lagos@example.com', 'fields' => ['timezone' => 'Africa/Lagos'], 'lists' => [$this->list->id]], 'import');
    $svc->upsert($this->ws, ['email' => 'ny@example.com', 'fields' => ['timezone' => 'America/New_York'], 'lists' => [$this->list->id]], 'import');
    $svc->upsert($this->ws, ['email' => 'nozone@example.com', 'lists' => [$this->list->id]], 'import');
    $this->travelTo('2026-11-02 12:00:00');
    $id = readyCampaign($this);
    $this->postJson(cmp($this, "$id/send"), ['mode' => 'local', 'date' => '2026-11-03', 'time' => '09:00', 'timezone' => 'Europe/London'])->assertOk()->assertJsonPath('campaign.status', 'scheduled');

    $this->travelTo('2026-11-03 08:00:30'); // 09:00 in Lagos
    $this->artisan('campaigns:tick');
    $at = DB::table('campaign_recipients')->where('campaign_id', $id)->pluck('send_at', 'email')->all();
    ksort($at);
    expect($at)->toBe(['lagos@example.com' => '2026-11-03 08:00:30', // its 09:00 has just arrived: goes now
        'nozone@example.com' => '2026-11-03 09:00:00',                 // no zone: the campaign's (London)
        'ny@example.com' => '2026-11-03 14:00:00'])
        ->and(Message::where('campaign_id', $id)->pluck('to_email')->all())->toBe(['lagos@example.com'])
        ->and(Campaign::find($id)->status)->toBe('sending');
    $this->travelTo('2026-11-03 14:01:00');
    $this->artisan('campaigns:tick');
    expect(Campaign::find($id)->status)->toBe('sent')->and(Message::where('campaign_id', $id)->count())->toBe(3);
});

it('runs an A/B subject test and sends the rest the winner', function () {
    people($this, 100);
    $id = readyCampaign($this, ['subject' => 'Plain subject', 'subject_b' => 'Curious subject?', 'ab_enabled' => true, 'ab_test_pct' => 20, 'ab_metric' => 'opens', 'ab_wait_hours' => 2]);
    $this->postJson(cmp($this, "$id/send"), ['mode' => 'now'])->assertOk();
    $groups = DB::table('campaign_recipients')->where('campaign_id', $id)->selectRaw("coalesce(variant, 'holdout') as v, count(*) as n")->groupBy('v')->pluck('n', 'v')->all();
    expect($groups)->toBe(['a' => 10, 'b' => 10, 'holdout' => 80])->and(Message::where('campaign_id', $id)->count())->toBe(20)->and(Campaign::find($id)->status)->toBe('sending');

    // B gets more real opens.
    $p = app(EventProcessor::class);
    foreach (Message::where('campaign_id', $id)->get() as $i => $m) {
        $p->apply($this->ws->id, ['eventType' => 'Delivery', 'mail' => ['messageId' => $m->ses_message_id, 'tags' => ['sl_message' => [$m->id]]], 'delivery' => ['timestamp' => now()->toIso8601ZuluString()]]);
        if ($m->variant === 'b' || $i % 5 === 0) {
            $p->apply($this->ws->id, ['eventType' => 'Open', 'mail' => ['messageId' => $m->ses_message_id], 'open' => ['timestamp' => now()->addMinutes(10)->toIso8601ZuluString(), 'userAgent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/130']]);
        }
    }
    $this->artisan('campaigns:tick');
    expect(Campaign::find($id)->ab_winner)->toBeNull(); // still waiting
    $this->travel(2)->hours();
    $this->artisan('campaigns:tick');
    $c = Campaign::find($id);
    expect($c->ab_winner)->toBe('b')->and($c->status)->toBe('sent')
        ->and(Message::where('campaign_id', $id)->where('subject', 'Curious subject?')->count())->toBe(90);
    $r = $this->getJson(cmp($this, "$id/report"))->assertOk();
    expect($r->json('report.variants.b.opened'))->toBe(10)->and($r->json('campaign.ab.winner'))->toBe('b');
});

it('ramps up gently for Outlook addresses', function () {
    config(['queue.default' => 'database']);
    people($this, 100, 'outlook.com');
    $id = readyCampaign($this);
    $this->postJson(cmp($this, "$id/send"), ['mode' => 'now'])->assertOk();
    (new StartCampaign($id))->handle(app(CampaignService::class));
    app()->call([new SendCampaignBatch($id), 'handle']);
    expect(Message::where('campaign_id', $id)->count())->toBe(SendCampaignBatch::MICROSOFT_PER_BATCH);
});

it('reports results that match Activity, with a click map and a shareable link without addresses', function () {
    people($this, 4);
    $id = readyCampaign($this);
    $this->postJson(cmp($this, "$id/send"), ['mode' => 'now'])->assertOk();
    $p = app(EventProcessor::class);
    $ms = Message::where('campaign_id', $id)->orderBy('to_email')->get();
    foreach ($ms as $m) {
        $p->apply($this->ws->id, ['eventType' => 'Delivery', 'mail' => ['messageId' => $m->ses_message_id], 'delivery' => ['timestamp' => now()->toIso8601ZuluString(), 'smtpResponse' => '250 gsmtp']]);
    }
    $click = fn ($m, $url) => $p->apply($this->ws->id, ['eventType' => 'Click', 'mail' => ['messageId' => $m->ses_message_id], 'click' => ['timestamp' => now()->addMinutes(5)->toIso8601ZuluString(), 'link' => $url, 'userAgent' => 'Mozilla/5.0 (Macintosh) Chrome/130']]);
    $click($ms[0], 'https://acme.com/pricing');
    $click($ms[1], 'https://acme.com/pricing');
    $click($ms[1], 'https://acme.com/blog');
    $p->apply($this->ws->id, ['eventType' => 'Bounce', 'mail' => ['messageId' => $ms[3]->ses_message_id], 'bounce' => ['bounceType' => 'Permanent', 'bouncedRecipients' => [['emailAddress' => $ms[3]->to_email]]]]);
    $this->postJson("/v1/unsubscribe/{$ms[2]->unsubscribe_token}")->assertOk();

    $r = $this->getJson(cmp($this, "$id/report"))->assertOk()->json('report');
    expect($r['totals'])->toMatchArray(['recipients' => 4, 'sent' => 4, 'delivered' => 4, 'clicked' => 2, 'opened' => 2, 'bounced' => 1, 'unsubscribed' => 1])
        ->and($r['links'])->toBe([['url' => 'https://acme.com/pricing', 'clicks' => 2, 'people' => 2], ['url' => 'https://acme.com/blog', 'clicks' => 1, 'people' => 1]])
        ->and($r['providers'][0])->toMatchArray(['provider' => 'google', 'sent' => 4]);
    expect($this->getJson(cmp($this, "$id/recipients?filter=clicked"))->json('recipients'))->toHaveCount(2)
        ->and($this->getJson(cmp($this, "$id/recipients?filter=unsubscribed"))->json('recipients.0.email'))->toBe($ms[2]->to_email);
    // Activity agrees.
    expect($this->getJson("/v1/workspaces/{$this->ws->id}/activity?status=clicked")->json('counts.clicked'))->toBe(2);

    $url = $this->postJson(cmp($this, "$id/share"), ['enabled' => true])->assertOk()->json('share_url');
    $token = substr($url, strpos($url, '?t=') + 3);
    $public = $this->getJson("/v1/reports/$token")->assertOk();
    expect($public->json('report.totals.clicked'))->toBe(2)->and($public->getContent())->not->toContain('@example.com');
    $this->postJson(cmp($this, "$id/share"), ['enabled' => false])->assertOk();
    $this->getJson("/v1/reports/$token")->assertNotFound();
});

it('cancels, duplicates, and keeps viewers read-only', function () {
    people($this, 3);
    $id = readyCampaign($this);
    $this->postJson(cmp($this, "$id/send"), ['mode' => 'scheduled', 'at' => now()->addDay()->format('Y-m-d H:i'), 'timezone' => 'UTC'])->assertOk();
    $this->deleteJson(cmp($this, $id))->assertStatus(422);
    $this->postJson(cmp($this, "$id/cancel"))->assertOk()->assertJsonPath('campaign.status', 'cancelled');
    $copy = $this->postJson(cmp($this, "$id/duplicate"))->assertCreated()->json('campaign');
    expect($copy['status'])->toBe('draft')->and($copy['name'])->toBe('October newsletter (copy)')->and($copy['has_design'])->toBeTrue();
    switchTo($this, 'viewer@example.com');
    $this->getJson(cmp($this))->assertOk()->assertJsonCount(2, 'campaigns');
    $this->postJson(cmp($this), ['name' => 'x'])->assertForbidden();
    $this->postJson(cmp($this, "{$copy['id']}/send"), ['mode' => 'now'])->assertForbidden();
});

it('keeps preview text edited in the builder, and lets the campaign field override it', function () {
    $id = readyCampaign($this);
    $doc = $this->doc;
    $doc['settings']['preheader'] = 'Edited in the builder';
    $this->patchJson(cmp($this, $id), ['doc' => $doc])->assertOk()->assertJsonPath('campaign.preheader', 'Edited in the builder');
    expect(Campaign::find($id)->html)->toContain('Edited in the builder');

    $this->patchJson(cmp($this, $id), ['preheader' => 'Typed on the campaign page'])->assertOk();
    $c = Campaign::find($id);
    expect($c->doc['settings']['preheader'])->toBe('Typed on the campaign page')->and($c->html)->toContain('Typed on the campaign page')->not->toContain('Edited in the builder');

    $doc['settings']['preheader'] = '';
    $this->patchJson(cmp($this, $id), ['doc' => $doc])->assertOk()->assertJsonPath('campaign.preheader', 'Typed on the campaign page');
});

it('holds a pause even for emails queued after the pull-back, and stays sending until the queue drains', function () {
    config(['queue.default' => 'database']);
    people($this, 250);
    $id = readyCampaign($this);
    $this->postJson(cmp($this, "$id/send"), ['mode' => 'now'])->assertOk();
    (new StartCampaign($id))->handle(app(CampaignService::class));
    app()->call([new SendCampaignBatch($id), 'handle']);
    app()->call([new SendCampaignBatch($id), 'handle']);
    // Everyone is handed to the mailer, but nothing has reached SES: still "sending", so it can still be paused.
    expect(DB::table('campaign_recipients')->where('campaign_id', $id)->where('status', 'pending')->count())->toBe(0)
        ->and(Campaign::find($id)->status)->toBe('sending');

    // The race seen in production: the pause lands while a batch is still queueing emails the pull-back didn't see.
    Campaign::whereKey($id)->update(['status' => 'paused']);
    $this->artisan('queue:work', ['--stop-when-empty' => true, '--queue' => 'default'])->assertSuccessful();
    expect($this->aws->argsFor('SendEmail'))->toHaveCount(0)
        ->and(Message::where('campaign_id', $id)->count())->toBe(0)
        ->and(DB::table('campaign_recipients')->where('campaign_id', $id)->where('status', 'pending')->count())->toBe(250);

    $this->postJson(cmp($this, "$id/resume"))->assertOk();
    $this->artisan('queue:work', ['--stop-when-empty' => true, '--queue' => 'default'])->assertSuccessful();
    expect($this->aws->argsFor('SendEmail'))->toHaveCount(250);
    // As the scheduler does each minute: later workers run the delayed check that closes the campaign.
    for ($i = 0; $i < 5 && Campaign::find($id)->status === 'sending'; $i++) {
        $this->travel(20)->seconds();
        $this->artisan('queue:work', ['--stop-when-empty' => true, '--queue' => 'default'])->assertSuccessful();
    }
    expect(Campaign::find($id)->status)->toBe('sent');
});

it('stops a batch between chunks when the campaign is paused mid-batch', function () {
    config(['queue.default' => 'database']);
    people($this, 120);
    $id = readyCampaign($this);
    $this->postJson(cmp($this, "$id/send"), ['mode' => 'now'])->assertOk();
    (new StartCampaign($id))->handle(app(CampaignService::class));
    $calls = 0;
    Message::created(function () use (&$calls, $id) {
        if (++$calls === 50) { // the first chunk of 50 is queued; a pause lands now
            Campaign::whereKey($id)->update(['status' => 'paused']);
        }
    });
    app()->call([new SendCampaignBatch($id), 'handle']);
    expect(Message::where('campaign_id', $id)->count())->toBe(50)
        ->and(DB::table('campaign_recipients')->where('campaign_id', $id)->where('status', 'pending')->count())->toBe(70);
});

it('withdraws queued emails of a cancelled campaign instead of sending them', function () {
    config(['queue.default' => 'database']);
    people($this, 30);
    $id = readyCampaign($this);
    $this->postJson(cmp($this, "$id/send"), ['mode' => 'now'])->assertOk();
    (new StartCampaign($id))->handle(app(CampaignService::class));
    app()->call([new SendCampaignBatch($id), 'handle']);
    Campaign::whereKey($id)->update(['status' => 'cancelled']);
    $this->artisan('queue:work', ['--stop-when-empty' => true, '--queue' => 'default'])->assertSuccessful();
    expect($this->aws->argsFor('SendEmail'))->toHaveCount(0)
        ->and(DB::table('campaign_recipients')->where('campaign_id', $id)->where('status', 'cancelled')->count())->toBe(30);
});

it('sends a plain-text letter: text only, no unsubscribe header, no SES tracking, and it needs a way out in the words', function () {
    people($this, 2);
    $id = readyCampaign($this);
    $this->patchJson(cmp($this, $id), ['format' => 'plain', 'text_body' => "Hi {{ first_name | there }},\n\nAre you around this week?\n\nThanks"])->assertOk()
        ->assertJsonPath('campaign.format', 'plain')->assertJsonPath('campaign.has_design', true)
        ->assertJsonPath('campaign.problems.0.key', 'content');
    $this->patchJson(cmp($this, $id), ['text_body' => "Hi {{ first_name | there }},\n\nAre you around this week?\n\nThanks\n\nP.S. If you’d rather not hear from us again, just reply and let us know."])
        ->assertOk()->assertJsonPath('campaign.problems', []);

    $this->postJson(cmp($this, "$id/test"), ['to' => 'me@acme.com', 'as' => 'p1@example.com'])->assertStatus(202);
    $this->postJson(cmp($this, "$id/send"), ['mode' => 'now'])->assertOk();

    $m = Message::where('campaign_id', $id)->where('to_email', 'p1@example.com')->first();
    expect($m->html)->toBeNull()->and($m->text)->toStartWith("Hi P1,\n\nAre you around")->and($m->unsubscribe_token)->toBeNull();
    $reqs = collect($this->aws->argsFor('SendEmail'));
    expect($reqs)->toHaveCount(3); // the test, then the two people
    foreach ($reqs as $req) {
        expect($req['ConfigurationSetName'])->toBe('sendline-events-own-tracking')
            ->and($req['Content']['Simple']['Body'])->not->toHaveKey('Html')
            ->and(collect($req['Content']['Simple']['Headers'] ?? [])->pluck('Name'))->not->toContain('List-Unsubscribe')->not->toContain('Feedback-ID')->not->toContain('X-Sendline-Tracking');
    }
    expect($reqs->first()['Content']['Simple']['Body']['Text']['Data'])->toStartWith('Hi P1,'); // the test: their version
    expect(collect($this->aws->argsFor('CreateConfigurationSetEventDestination'))->last()['EventDestination']['MatchingEventTypes'])->not->toContain('OPEN')->not->toContain('CLICK');

    // With tracking: a plain-looking HTML copy, SES's normal (tracking) set, still no bulk headers.
    $id2 = (string) $this->postJson(cmp($this, "$id/duplicate"))->json('campaign.id');
    $this->patchJson(cmp($this, $id2), ['letter_tracking' => true, 'text_body' => "Hi {{ first_name | there }},\n\nSee it: https://acme.com\n\nIf not, just reply and we won't email you again."])->assertOk()->assertJsonPath('campaign.letter_tracking', true);
    $this->postJson(cmp($this, "$id2/test"), ['to' => 'me@acme.com', 'as' => 'p2@example.com'])->assertStatus(202);
    $req = collect($this->aws->argsFor('SendEmail'))->last();
    expect($req['ConfigurationSetName'])->toBe('sendline-events')
        ->and($req['Content']['Simple']['Body']['Html']['Data'])->toContain('<p style="margin:0 0 14px">Hi P2,</p>')->toContain('<a href="https://acme.com" style="color:#1a0dab">acme.com</a>')
        ->and($req['Content']['Simple']['Body']['Text']['Data'])->toStartWith('Hi P2,')
        ->and(collect($req['Content']['Simple']['Headers'] ?? [])->pluck('Name'))->not->toContain('List-Unsubscribe')->not->toContain('Feedback-ID');

    // Back to the design: the designed email and its unsubscribe header again.
    $this->patchJson(cmp($this, (string) $this->postJson(cmp($this, "$id/duplicate"))->json('campaign.id')), ['format' => 'design'])->assertOk()->assertJsonPath('campaign.format', 'design');
});
