<?php

use App\Jobs\SendCampaignBatch;
use App\Jobs\StartCampaign;
use App\Models\ApiKey;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Segment;
use App\Models\SiteConnection;
use App\Services\Campaigns\CampaignService;
use App\Services\Ses\DnsLookup;
use App\Services\Webhooks\Signer;
use App\Services\Webhooks\UrlGuard;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeAws;
use Tests\Support\FakeDns;

const SITE_AUDIENCE = 'https://micro.example.com/sendline/audience';

beforeEach(function () {
    FakeAws::install();
    $this->app->instance(DnsLookup::class, new FakeDns);
    [$this->ws] = sesWorkspace($this);
    sesConnect($this, $this->ws)->assertOk();
    UrlGuard::$resolver = fn (string $host) => ['93.184.216.34'];
    [$key, $this->key] = ApiKey::generate($this->ws->id, 'Site: micro.example.com', 'full', null);
    $this->site = SiteConnection::create(['workspace_id' => $this->ws->id, 'site_url' => 'https://micro.example.com', 'name' => 'Lemon Micro',
        'redirect_uri' => 'https://micro.example.com/sendline/callback', 'api_key_id' => $key->id, 'connected_at' => now()]);
    $this->secret = $this->withToken($this->key)->postJson('/v1/connect/audience-hook', ['url' => SITE_AUDIENCE])->assertOk()->json('secret');
    // The site's users, as its package would answer (it reads them live; MailVesper never stores them).
    $this->people = [
        ['id' => '1', 'email' => 'ada@example.com', 'first_name' => 'Ada', 'last_name' => 'Obi', 'subscribed' => true, 'fields' => ['role' => 'creator', 'total_spent' => 0]],
        ['id' => '2', 'email' => 'bola@example.com', 'first_name' => 'Bola', 'last_name' => null, 'subscribed' => true, 'fields' => ['role' => 'fan', 'total_spent' => 150]],
        ['id' => '3', 'email' => 'chi@example.com', 'first_name' => null, 'last_name' => null, 'subscribed' => false, 'fields' => ['role' => 'fan', 'total_spent' => 0]],
        ['id' => '4', 'email' => 'dayo@example.com', 'first_name' => 'Dayo', 'last_name' => null, 'subscribed' => true, 'fields' => ['role' => 'fan', 'total_spent' => 20]],
    ];
    $this->asked = [];
    $this->siteDown = false;
    $this->withToken($this->key)->postJson('/v1/connect/preset', ['preset' => 'creator_platform', 'group' => 'Lemon Micro',
        'types' => ['role' => 'text', 'email_verified' => 'boolean', 'creator_verified' => 'boolean', 'account_status' => 'text', 'last_login_at' => 'date', 'signed_up_at' => 'date',
            'active_subscriptions' => 'number', 'next_subscription_ends_at' => 'date', 'total_spent' => 'number', 'last_purchase_at' => 'date', 'country' => 'text']])->assertOk();
    $this->app['auth']->forgetGuards(); // back to the signed-in owner (the key call set a token)
    Http::fake([SITE_AUDIENCE => fn (Request $r) => fakeSite($this, $r)]);
});

afterEach(fn () => UrlGuard::$resolver = null);

/** A tiny site package: checks MailVesper's signature, then answers counts / page / chunk from $test->people. */
function fakeSite($test, Request $r)
{
    $sig = 'v1,'.Signer::sign($test->secret, $r->header('webhook-id')[0] ?? '', (int) ($r->header('webhook-timestamp')[0] ?? 0), $r->body());
    if (($r->header('webhook-signature')[0] ?? '') !== $sig) {
        return Http::response(['error' => 'Invalid signature.'], 401);
    }
    if ($test->siteDown) {
        return Http::response('down', 503);
    }
    $b = $r->data();
    $test->asked[] = $b['op'];
    $match = function (array $p, $rules) use ($b) {
        if (! empty($b['consented_only']) && ! $p['subscribed']) {
            return false;
        }
        foreach ((array) (((array) $rules)['conditions'] ?? []) as $c) {
            if ($c['field'] === 'field:role' && $p['fields']['role'] !== $c['value']) {
                return false;
            }
            if ($c['field'] === 'field:total_spent' && $c['op'] === 'gte' && $p['fields']['total_spent'] < $c['value']) {
                return false;
            }
        }

        return true;
    };
    $who = fn ($rules) => array_values(array_filter($test->people, fn ($p) => $match($p, $rules)));

    return match ($b['op']) {
        'counts' => Http::response(['counts' => array_map(fn ($rules) => count($who($rules)), $b['sets'])]),
        'page' => Http::response(['people' => array_values(array_filter($who($b['rules'] ?? []), fn ($p) => ($b['q'] ?? '') === '' || str_contains($p['email'], $b['q']))), 'total' => count($who($b['rules'] ?? []))]),
        'chunk' => (function () use ($who, $b) {
            $rest = array_values(array_filter($who($b['rules']), fn ($p) => $b['after'] === null || (int) $p['id'] > (int) $b['after']));
            $page = array_slice($rest, 0, (int) $b['limit']);

            return Http::response(['people' => $page, 'next' => count($rest) > count($page) ? end($page)['id'] : null]);
        })(),
    };
}

function liveSeg($test, string $name): Segment
{
    return Segment::where('workspace_id', $test->ws->id)->where('site_connection_id', $test->site->id)->where('name', $name)->firstOrFail();
}

it('makes a connected site a live audience: its fields and ready-made segments, and no contacts', function () {
    // 15: "Opens your emails, not on the site for 14 days" needs open history, which a live site doesn't have.
    expect(Segment::where('workspace_id', $this->ws->id)->where('site_connection_id', $this->site->id)->count())->toBe(15)
        ->and(collect($this->site->fresh()->fields)->pluck('type', 'key')->all())->toMatchArray(['last_login_at' => 'date', 'total_spent' => 'number', 'role' => 'text'])
        ->and(DB::table('contact_fields')->where('workspace_id', $this->ws->id)->count())->toBe(0)
        ->and(Contact::where('workspace_id', $this->ws->id)->count())->toBe(0);
    // Again (a reconnect): nothing duplicated, and the segments follow the new connection.
    $this->withToken($this->key)->postJson('/v1/connect/preset', ['preset' => 'creator_platform', 'group' => 'Lemon Micro'])->assertOk()
        ->assertJsonPath('segments_created', [])->assertJsonPath('segments_skipped', ['Opens your emails, not on the site for 14 days']);
    expect(Segment::where('workspace_id', $this->ws->id)->count())->toBe(15);
});

it('counts a site’s segments live, one request per site, and keeps the last count when the site is down', function () {
    $r = $this->getJson("/v1/workspaces/{$this->ws->id}/segments?page=1&per_page=100")->assertOk();
    expect(collect($r->json('segments'))->firstWhere('name', 'All creators'))->toMatchArray(['count' => 1, 'live' => true, 'site_connection_id' => $this->site->id])
        ->and(collect($r->json('segments'))->firstWhere('name', 'All subscribers')['count'])->toBe(3)
        ->and($this->asked)->toBe(['counts']);

    Segment::query()->update(['counted_at' => now()->subHour()]);
    $this->siteDown = true;
    $s = collect($this->getJson("/v1/workspaces/{$this->ws->id}/segments?page=1&per_page=100")->json('segments'))->firstWhere('name', 'All creators');
    expect($s['count'])->toBe(1)->and($s['live_error'])->toContain('couldn’t read Lemon Micro’s users');
});

it('previews and saves your own segments on a site, with only what a site has', function () {
    $url = "/v1/workspaces/{$this->ws->id}/segments";
    $rules = ['match' => 'all', 'conditions' => [['field' => 'field:total_spent', 'op' => 'gte', 'value' => 10]]];
    $p = $this->postJson("$url/preview", ['site_connection_id' => $this->site->id, 'rules' => $rules])->assertOk();
    expect($p->json())->toMatchArray(['count' => 2, 'subscribed' => 2, 'live' => true])->and(collect($p->json('sample'))->pluck('email')->all())->toBe(['bola@example.com', 'dayo@example.com']);

    $this->postJson("$url/preview", ['site_connection_id' => $this->site->id, 'rules' => ['match' => 'all', 'conditions' => [['field' => 'tag', 'op' => 'has', 'value' => 'vip']]]])
        ->assertStatus(422)->assertJsonPath('error.message', fn ($m) => str_contains($m, 'doesn’t have that'));
    $s = $this->postJson($url, ['name' => 'Spent 10+', 'site_connection_id' => $this->site->id, 'rules' => $rules])->assertCreated()->json('segment');
    expect($s)->toMatchArray(['count' => 2, 'live' => true, 'group' => 'Lemon Micro']);
    $this->getJson("/v1/workspaces/{$this->ws->id}/segments/catalog?site={$this->site->id}")->assertOk()->assertJsonPath('site.name', 'Lemon Micro')
        ->assertJsonFragment(['field' => 'field:total_spent', 'type' => 'number', 'label' => 'Total spent'])->assertJsonPath('tags', []);
    $this->getJson("/v1/workspaces/{$this->ws->id}/contacts?segment={$s['id']}")->assertStatus(422)->assertJsonPath('error.code', 'live_segment');
});

it('sends a campaign to a site’s segment: read live at the start, personalised, only who agreed, nothing kept', function () {
    $seg = liveSeg($this, 'All subscribers');
    $a = collect($this->getJson("/v1/workspaces/{$this->ws->id}/campaigns/audiences")->assertOk()->json('segments'))->firstWhere('id', $seg->id);
    expect($a)->toMatchArray(['total' => 2, 'live' => true]); // bola, dayo (chi didn't agree to marketing)

    config(['queue.default' => 'database']); // run each step by hand
    $id = $this->postJson("/v1/workspaces/{$this->ws->id}/campaigns", ['name' => 'Fans'])->json('campaign.id');
    $doc = json_decode(file_get_contents(base_path('tests/fixtures/design.json')), true);
    $this->patchJson("/v1/workspaces/{$this->ws->id}/campaigns/$id", ['audience_type' => 'segment', 'audience_id' => $seg->id, 'from_email' => 'news@acme.com', 'from_name' => 'Acme',
        'subject' => 'Hi {{ first_name | friend }}, you spent {{ total_spent }}', 'doc' => $doc])->assertOk()->assertJsonPath('campaign.estimate.total', 2);
    $this->postJson("/v1/workspaces/{$this->ws->id}/campaigns/$id/send", ['mode' => 'now'])->assertOk();
    (new StartCampaign($id))->handle(app(CampaignService::class));
    expect(DB::table('campaign_recipients')->where('campaign_id', $id)->whereNull('contact_id')->whereNotNull('vars')->count())->toBe(2);
    app()->call([new SendCampaignBatch($id), 'handle']);
    app()->call([new SendCampaignBatch($id), 'handle']); // nothing left: finished

    expect(Message::where('campaign_id', $id)->orderBy('to_email')->pluck('subject', 'to_email')->all())
        ->toBe(['bola@example.com' => 'Hi Bola, you spent 150', 'dayo@example.com' => 'Hi Dayo, you spent 20']);
    expect(DB::table('campaign_recipients')->where('campaign_id', $id)->whereNotNull('vars')->count())->toBe(0) // details gone once built
        ->and(Contact::where('workspace_id', $this->ws->id)->count())->toBe(0);                            // and nobody became a contact
});

it('reads a big audience page by page', function () {
    $this->people = array_map(fn ($i) => ['id' => (string) $i, 'email' => "fan$i@example.com", 'first_name' => "F$i", 'last_name' => null, 'subscribed' => true, 'fields' => ['role' => 'fan', 'total_spent' => 0]], range(1, 1203));
    config(['queue.default' => 'database']);
    $c = app(CampaignService::class);
    $id = $this->postJson("/v1/workspaces/{$this->ws->id}/campaigns", ['name' => 'Big'])->json('campaign.id');
    DB::table('campaigns')->where('id', $id)->update(['audience_type' => 'segment', 'audience_id' => liveSeg($this, 'All subscribers')->id, 'status' => 'sending']);
    (new StartCampaign($id))->handle($c);
    expect(DB::table('campaign_recipients')->where('campaign_id', $id)->count())->toBe(1203)->and(array_count_values($this->asked)['chunk'])->toBe(3);
});

it('pauses with the reason when the site doesn’t answer at the start, and reads it again on resume', function () {
    config(['queue.default' => 'database']);
    $id = $this->postJson("/v1/workspaces/{$this->ws->id}/campaigns", ['name' => 'Down'])->json('campaign.id');
    DB::table('campaigns')->where('id', $id)->update(['audience_type' => 'segment', 'audience_id' => liveSeg($this, 'All subscribers')->id, 'status' => 'sending']);
    $this->siteDown = true;
    (new StartCampaign($id))->handle(app(CampaignService::class));
    $row = DB::table('campaigns')->where('id', $id)->first();
    expect($row->status)->toBe('paused')->and($row->status_note)->toContain('couldn’t read Lemon Micro’s users')
        ->and(DB::table('campaign_recipients')->where('campaign_id', $id)->count())->toBe(0);

    $this->siteDown = false;
    $this->postJson("/v1/workspaces/{$this->ws->id}/campaigns/$id/resume")->assertOk();
    expect(DB::table('jobs')->where('payload', 'like', '%StartCampaign%')->count())->toBe(1); // reads the audience again
    (new StartCampaign($id))->handle(app(CampaignService::class));
    expect(DB::table('campaign_recipients')->where('campaign_id', $id)->pluck('email')->sort()->values()->all())->toBe(['bola@example.com', 'dayo@example.com']); // fans who agreed
});

it('lists a site’s people live, searched and inside a segment', function () {
    $url = "/v1/workspaces/{$this->ws->id}/connections/{$this->site->id}/people";
    expect($this->getJson($url)->assertOk()->json('meta.total'))->toBe(4)
        ->and(collect($this->getJson("$url?q=bola")->json('people'))->pluck('email')->all())->toBe(['bola@example.com'])
        ->and($this->getJson("$url?segment=".liveSeg($this, 'All creators')->id)->json('meta.total'))->toBe(1);
    $this->getJson("/v1/workspaces/{$this->ws->id}/connections")->assertOk()->assertJsonPath('sites.0.live', true);
});

it('won’t start a journey from a site’s segment (nobody is stored to join or leave it)', function () {
    $id = $this->postJson("/v1/workspaces/{$this->ws->id}/journeys", ['name' => 'Live'])->json('journey.id');
    $this->patchJson("/v1/workspaces/{$this->ws->id}/journeys/$id", ['graph' => ['nodes' => [['id' => 't1', 'type' => 'trigger', 'config' => ['trigger' => 'segment_joined', 'segment_id' => liveSeg($this, 'All creators')->id]],
        ['id' => 'k1', 'type' => 'tag', 'config' => ['tag' => 'x']]], 'edges' => [['from' => 't1', 'to' => 'k1']]]])->assertOk();
    expect($this->postJson("/v1/workspaces/{$this->ws->id}/journeys/$id/publish")->assertStatus(422)->json('error.details.problems.0.message'))->toContain('read live');
});

it('refuses rules a site can’t answer before asking it, and a forged signature is what the site rejects', function () {
    $this->postJson("/v1/workspaces/{$this->ws->id}/segments", ['name' => 'Opened', 'site_connection_id' => $this->site->id,
        'rules' => ['match' => 'all', 'conditions' => [['field' => 'last_opened_at', 'op' => 'within_days', 'value' => 7]]]])->assertStatus(422);
    expect($this->asked)->toBe([]);
    $this->secret = 'whsec_'.base64_encode(str_repeat('z', 32)); // the site now expects a different secret
    $this->getJson("/v1/workspaces/{$this->ws->id}/connections/{$this->site->id}/people")->assertStatus(502)->assertJsonPath('error.message', fn ($m) => str_contains($m, 'signature'));
});

it('sends a test of a site person’s own version, read live, to any address', function () {
    $seg = liveSeg($this, 'All subscribers');
    $id = $this->postJson("/v1/workspaces/{$this->ws->id}/campaigns", ['name' => 'Fans'])->json('campaign.id');
    $doc = json_decode(file_get_contents(base_path('tests/fixtures/design.json')), true);
    $this->patchJson("/v1/workspaces/{$this->ws->id}/campaigns/$id", ['audience_type' => 'segment', 'audience_id' => $seg->id, 'from_email' => 'news@acme.com', 'from_name' => 'Acme',
        'subject' => 'Hi {{ first_name | friend }}, you spent {{ total_spent }}', 'doc' => $doc])->assertOk();

    $this->postJson("/v1/workspaces/{$this->ws->id}/campaigns/$id/test", ['to' => 'me@acme.com', 'as' => 'Bola@example.com'])->assertStatus(202);
    expect(Message::where('to_email', 'me@acme.com')->latest('id')->value('subject'))->toBe('Hi Bola, you spent 150'); // their version: exactly as they'd get it
    $this->postJson("/v1/workspaces/{$this->ws->id}/campaigns/$id/test", ['to' => 'me@acme.com'])->assertStatus(202); // no one: the fallbacks
    expect(Message::where('to_email', 'me@acme.com')->latest('id')->value('subject'))->toBe('[Test] Hi friend, you spent ');
    $this->postJson("/v1/workspaces/{$this->ws->id}/campaigns/$id/test", ['to' => 'me@acme.com', 'as' => 'chi@example.com'])->assertStatus(422); // not in the audience
    expect(Contact::where('workspace_id', $this->ws->id)->count())->toBe(0);
});
