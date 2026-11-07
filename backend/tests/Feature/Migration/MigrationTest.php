<?php

use App\Models\Contact;
use App\Models\EmailTemplate;
use App\Models\Journey;
use App\Models\JourneyRun;
use App\Models\ProviderImport;
use App\Models\Suppression;
use App\Services\Contacts\ContactService;
use App\Services\Migration\Providers\MailchimpProvider;
use App\Services\Migration\Providers\ProviderError;
use App\Services\Ses\DnsLookup;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeAws;
use Tests\Support\FakeDns;
use Tests\Support\FakeProvider;

beforeEach(function () {
    FakeAws::install();
    $this->app->instance(DnsLookup::class, new FakeDns);
    [$this->ws] = sesWorkspace($this, ['member']);
    $this->app->bind(MailchimpProvider::class, fn ($app, $p) => new FakeProvider($p['credentials'] ?? []));
    FakeProvider::$log = [];
    FakeProvider::$failOnce = null;
    FakeProvider::$badKey = false;
    FakeProvider::$pages = [
        'suppressions' => [[['email' => 'Gone@Example.com', 'reason' => 'unsubscribe'], ['email' => 'dead@example.com', 'reason' => 'bounce', 'detail' => 'cleaned']], [['email' => 'angry@example.com', 'reason' => 'complaint'], ['email' => 'not-an-email', 'reason' => 'unsubscribe']]],
        'lists' => [[['ext_id' => 'L1', 'name' => 'Newsletter'], ['ext_id' => 'L2', 'name' => 'Not chosen']]],
        'contacts' => [[
            ['email' => 'amara@example.com', 'first_name' => 'Amara', 'last_name' => 'Okafor', 'fields' => ['COMPANY' => 'Lumen', 'Plan Type' => 'pro'], 'tags' => ['VIP', 'group: Shoes'], 'lists' => ['L1', 'L2'], 'status' => 'subscribed'],
            ['email' => 'gone@example.com', 'first_name' => 'Gina', 'tags' => [], 'lists' => ['L1'], 'status' => 'subscribed'], // the old provider says subscribed; the suppression wins
            ['email' => 'quiet@example.com', 'status' => 'unsubscribed', 'lists' => ['L1']],
        ], [
            ['email' => 'existing@example.com', 'first_name' => 'New name', 'fields' => ['company' => 'Other'], 'tags' => ['imported'], 'lists' => ['L1'], 'status' => 'subscribed'],
        ]],
        'templates' => [[['name' => 'October news', 'subject' => 'Hi *|FNAME|*', 'html' => '<html><body><p>Hello *|FNAME|*</p><script>steal()</script><a href="*|UNSUB|*" onclick="x()">Unsubscribe</a></body></html>']]],
    ];
});

function mig($test, string $path = ''): string
{
    return "/v1/workspaces/{$test->ws->id}/migrations".($path ? "/$path" : '');
}

it('imports suppressions first, never re-subscribes anyone, and brings contacts, lists, tags, fields and templates', function () {
    app(ContactService::class)->upsert($this->ws, ['email' => 'existing@example.com', 'first_name' => 'Kept', 'fields' => ['company' => 'Mine']], 'manual');
    // A live journey would fire on contact creation; an import must never start it.
    $j = $this->postJson("/v1/workspaces/{$this->ws->id}/journeys", ['name' => 'Welcome'])->json('journey.id');
    $this->patchJson("/v1/workspaces/{$this->ws->id}/journeys/$j", ['graph' => ['nodes' => [['id' => 't1', 'type' => 'trigger', 'config' => ['trigger' => 'contact_created']], ['id' => 'k1', 'type' => 'tag', 'config' => ['tag' => 'welcomed']]], 'edges' => [['from' => 't1', 'to' => 'k1']]]])->assertOk();
    $this->postJson("/v1/workspaces/{$this->ws->id}/journeys/$j/publish")->assertOk();
    $runsBefore = JourneyRun::count();

    $imp = $this->postJson(mig($this), ['provider' => 'mailchimp', 'api_key' => 'abc123-us21'])->assertCreated()->assertJsonPath('import.account_name', 'Acme Shoes (fake)')->json('import');
    expect($imp['discovery']['contacts'])->toBe(4)->and(ProviderImport::find($imp['id'])->credentials)->toBe(['api_key' => 'abc123-us21'])
        ->and(DB::table('provider_imports')->value('credentials'))->not->toContain('abc123');
    $this->postJson(mig($this, $imp['id'].'/start'), ['list_ids' => ['L1'], 'tag' => 'from-mailchimp', 'suppressions' => false])->assertOk();

    $done = $this->getJson(mig($this, $imp['id']))->json('import');
    expect($done['status'])->toBe('done')->and(FakeProvider::$log[0])->toBe('suppressions')->and(array_values(array_unique(FakeProvider::$log)))->toBe(['suppressions', 'lists', 'contacts', 'templates'])
        ->and($done['progress']['suppressions'])->toBe(['done' => 3, 'total' => 4, 'skipped' => 1])->and($done['progress']['contacts']['done'])->toBe(4);
    expect(Suppression::where('workspace_id', $this->ws->id)->pluck('reason', 'email')->all())->toEqual(['gone@example.com' => 'unsubscribe', 'dead@example.com' => 'hard_bounce', 'angry@example.com' => 'complaint', 'quiet@example.com' => 'unsubscribe']);
    $c = Contact::where('workspace_id', $this->ws->id)->get()->keyBy('email');
    expect($c['gone@example.com']->status)->toBe('unsubscribed')->and($c['quiet@example.com']->status)->toBe('unsubscribed')->and($c['amara@example.com']->status)->toBe('subscribed')
        ->and($c['amara@example.com']->fields)->toBe(['company' => 'Lumen', 'plan_type' => 'pro'])
        ->and($c['existing@example.com']->first_name)->toBe('Kept')->and($c['existing@example.com']->fields['company'])->toBe('Other');
    expect(DB::table('contact_tags')->where('contact_id', $c['amara@example.com']->id)->orderBy('tag')->pluck('tag')->all())->toBe(['from-mailchimp', 'group: shoes', 'vip'])
        ->and(DB::table('lists')->where('workspace_id', $this->ws->id)->pluck('name')->all())->toBe(['Newsletter'])
        ->and(DB::table('contact_list')->count())->toBe(4)
        ->and(DB::table('contact_fields')->where('workspace_id', $this->ws->id)->pluck('key')->sort()->values()->all())->toBe(['company', 'plan_type']);
    $t = EmailTemplate::where('workspace_id', $this->ws->id)->firstOrFail();
    expect($t->subject)->toBe('Hi {{ first_name }}')->and($t->html)->toContain('Hello {{ first_name }}')->toContain('href="{{unsubscribe_url}}"')->not->toContain('<script')->not->toContain('onclick')
        ->and(collect($t->checks)->firstWhere('name', 'Unsubscribe link & address')['status'])->toBe('ok')->and($t->category)->toBe('Mailchimp');
    expect(JourneyRun::count())->toBe($runsBefore)->and(ProviderImport::find($imp['id'])->credentials)->toBe([]); // no journeys fired; key wiped
});

it('retries rate limits later, stops on a bad key, and checks the key before anything', function () {
    FakeProvider::$badKey = true;
    $this->postJson(mig($this), ['provider' => 'mailchimp', 'api_key' => 'wrong-key-us1'])->assertStatus(422)->assertJsonPath('error.message', 'Mailchimp rejected the API key.');
    FakeProvider::$badKey = false;
    $id = $this->postJson(mig($this), ['provider' => 'mailchimp', 'api_key' => 'abc123-us21'])->json('import.id');
    config(['queue.default' => 'database']);
    FakeProvider::$failOnce = new ProviderError('Mailchimp is rate limiting us. We’ll continue shortly.', true, 30);
    $this->postJson(mig($this, $id.'/start'))->assertOk();
    $this->artisan('queue:work', ['--once' => true, '--queue' => 'default'])->assertSuccessful();
    $i = ProviderImport::find($id);
    expect($i->status)->toBe('importing')->and($i->attempts)->toBe(1)->and($i->error)->toContain('rate limiting')->and(Suppression::count())->toBe(0);
    $this->travel(2)->minutes();
    $this->artisan('queue:work', ['--stop-when-empty' => true, '--queue' => 'default'])->assertSuccessful();
    expect(ProviderImport::find($id)->status)->toBe('done')->and(Suppression::count())->toBe(4);

    $id2 = $this->postJson(mig($this), ['provider' => 'mailchimp', 'api_key' => 'abc123-us21'])->json('import.id');
    FakeProvider::$failOnce = new ProviderError('Mailchimp says this key was revoked.');
    $this->postJson(mig($this, $id2.'/start'))->assertOk();
    $this->artisan('queue:work', ['--stop-when-empty' => true, '--queue' => 'default'])->assertSuccessful();
    expect(ProviderImport::find($id2)->status)->toBe('failed')->and(ProviderImport::find($id2)->error)->toContain('revoked')->and(ProviderImport::find($id2)->credentials)->toBe([]);
});

it('keeps imports to owners and admins, and saves the savings estimate', function () {
    switchTo($this, 'member@example.com');
    $this->postJson(mig($this), ['provider' => 'mailchimp', 'api_key' => 'abc123-us21'])->assertForbidden();
    $s = $this->putJson(mig($this, 'savings'), ['provider' => 'Mailchimp', 'monthly_bill' => 299, 'emails_per_month' => 150000, 'contacts' => 20000])->assertOk()->json('savings');
    expect($s)->toMatchArray(['ses_monthly' => 15, 'plan' => 'Starter', 'plan_price' => 29, 'sendline_monthly' => 44, 'monthly_saving' => 255, 'yearly_saving' => 3060]);
    expect($this->getJson(mig($this))->json('savings.yearly_saving'))->toBe(3060);
});
