<?php

use App\Jobs\SendCampaignBatch;
use App\Jobs\StartCampaign;
use App\Models\Contact;
use App\Models\ContactImport;
use App\Models\ContactList;
use App\Models\Domain;
use App\Models\Message;
use App\Services\Campaigns\CampaignService;
use App\Services\Contacts\ContactService;
use App\Services\Copilot\Diagnosis;
use App\Services\Ses\DnsLookup;
use App\Services\Webhooks\UrlGuard;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FakeAws;
use Tests\Support\FakeDns;

beforeEach(function () {
    $this->aws = FakeAws::install();
    $this->dns = new FakeDns;
    $this->app->instance(DnsLookup::class, $this->dns);
    [$this->ws, $this->people] = sesWorkspace($this, ['viewer']);
    sesConnect($this, $this->ws)->assertOk();
    UrlGuard::$resolver = fn (string $host) => ['93.184.216.34'];
});

afterEach(function () {
    UrlGuard::$resolver = null;
});

function cop($test, string $path = ''): string
{
    return "/v1/workspaces/{$test->ws->id}/".($path ?: 'copilot');
}

/** Sent mail for the diagnosis: $n messages with the given outcome. */
function sentMail($test, int $n, array $attrs = [], ?callable $each = null): void
{
    for ($i = 0; $i < $n; $i++) {
        $m = Message::forceCreate(array_merge([
            'id' => strtolower((string) Str::ulid()), 'workspace_id' => $test->ws->id, 'tracking_code' => 'SL-'.strtoupper(Str::random(4)).'-'.strtoupper(Str::random(4)),
            'source' => 'api', 'type' => 'marketing', 'from_email' => 'news@acme.com', 'to_email' => Str::random(8).'@gmail.com', 'subject' => 'Hello', 'status' => 'delivered',
            'provider' => 'google', 'sent_at' => now(), 'delivered_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
        if ($each) {
            $each($m, $i);
        }
    }
}

it('finds real problems from real data, scores them, and lists what passed', function () {
    $this->dns->records['TXT _dmarc.acme.com'] = ['v=DMARC1; p=none; rua=mailto:reports@acme.com'];
    $import = ContactImport::create(['workspace_id' => $this->ws->id, 'filename' => 'old-customers-2019.csv', 'path' => 'x', 'status' => 'done']);
    sentMail($this, 180);
    // 12 hard bounces (6%): 10 from one old import, 2 from the API.
    sentMail($this, 12, ['status' => 'bounced', 'delivered_at' => null], function (Message $m, int $i) use ($import) {
        Contact::forceCreate(['id' => strtolower((string) Str::ulid()), 'workspace_id' => $this->ws->id, 'email' => $m->to_email, 'status' => 'bounced', 'source' => $i < 10 ? 'import' : 'api', 'import_id' => $i < 10 ? $import->id : null]);
    });
    // Outlook: 60 sent, 3 delayed with 421 rate limits.
    sentMail($this, 60, ['provider' => 'microsoft', 'to_email' => 'x@outlook.com'], function (Message $m, int $i) {
        if ($i < 3) {
            DB::table('message_events')->insert(['workspace_id' => $this->ws->id, 'message_id' => $m->id, 'type' => 'delayed', 'machine' => false, 'data' => json_encode(['diagnostic' => '421 4.7.0 Server busy, try again later']), 'occurred_at' => now()]);
        }
    });

    $c = $this->getJson(cop($this))->assertOk()->json('copilot');
    $ids = collect($c['findings'])->pluck('id')->all();
    expect($ids)->toContain('bounce_rate', 'dmarc_none:acme.com', 'deferrals:microsoft', 'import_bounces:'.$import->id)
        ->and(collect($c['findings'])->firstWhere('id', 'bounce_rate')['title'])->toBe('Bounce rate is 4.8%')
        ->and(collect($c['findings'])->firstWhere('id', 'dmarc_none:acme.com')['evidence']['proposed'])->toBe('v=DMARC1; p=quarantine; rua=mailto:reports@acme.com;')
        ->and(collect($c['findings'])->firstWhere('id', 'import_bounces:'.$import->id)['title'])->toBe('10 of 12 hard bounces came from one import')
        ->and(collect($c['findings'])->firstWhere('id', 'deferrals:microsoft')['evidence']['rows'])->toBe(['421 4.7.0 Server busy, try again later' => 3])
        ->and($c['score'])->toBe(100 - array_sum(array_column($c['findings'], 'gain')))
        ->and($c['summary']['sent'])->toBe(252)
        ->and(collect($c['passed'])->pluck('id')->all())->toContain('unsubscribe', 'blocklists')
        ->and(collect($c['passed'])->firstWhere('id', 'blocklists')['title'])->toBe('Domain blocklists not checked'); // the fake DNS answers no list's test entry
    expect(DB::table('copilot_scans')->count())->toBe(1);
    $this->getJson(cop($this))->assertOk();
    expect(DB::table('copilot_scans')->count())->toBe(1); // cached for 6 hours
});

it('gives no reputation verdict on a small sample, and spots blocklists and missing DMARC', function () {
    sentMail($this, 20, ['status' => 'bounced', 'delivered_at' => null]); // 100% bounces, but only 20 emails
    sentMail($this, 500, ['status' => 'complained', 'to_email' => 'complaint@simulator.amazonses.com']); // simulator: not reputation
    // Spamhaus and SURBL answer their test entries; URIBL refuses us (127.0.0.1, even for its own test entry).
    $this->dns->records['A dbltest.com.dbl.spamhaus.org'] = ['127.0.1.2'];
    $this->dns->records['A test.surbl.org.multi.surbl.org'] = ['127.0.0.254'];
    $this->dns->records['A test.uribl.com.multi.uribl.com'] = ['127.0.0.1'];
    $this->dns->records['A acme.com.multi.uribl.com'] = ['127.0.0.1'];
    $this->dns->records['A acme.com.dbl.spamhaus.org'] = ['127.0.1.2'];
    $c = $this->postJson(cop($this, 'copilot/rescan'))->assertOk()->json('copilot');
    $ids = collect($c['findings'])->pluck('id')->all();
    expect($ids)->not->toContain('bounce_rate')->toContain('dmarc_missing:acme.com', 'blocklist:acme.com:dbl.spamhaus.org')
        ->not->toContain('blocklist:acme.com:multi.uribl.com')
        ->and($c['summary']['enough_data'])->toBeFalse()->and($c['summary']['complained'])->toBe(0)->and($c['summary']['sent'])->toBe(20);
    $this->postJson(cop($this, 'copilot/rescan'))->assertStatus(429);
    expect(Diagnosis::listed('dbl.spamhaus.org', '127.255.255.254'))->toBeFalse()
        ->and(Diagnosis::listed('multi.uribl.com', '127.0.0.1'))->toBeFalse()
        ->and(Diagnosis::listed('multi.uribl.com', '127.0.0.2'))->toBeTrue()
        ->and(Diagnosis::listed('multi.surbl.org', '127.0.0.64'))->toBeTrue();
    $this->travel(2)->minutes();
    unset($this->dns->records['A acme.com.dbl.spamhaus.org']);
    $p = collect($this->postJson(cop($this, 'copilot/rescan'))->json('copilot.passed'))->firstWhere('id', 'blocklists');
    expect($p['detail'])->toBe('Not listed on Spamhaus DBL, SURBL. URIBL refused our lookup, so it wasn’t checked');
});

it('publishes the DMARC fix through Route 53, with the record decided on the server', function () {
    Domain::where('workspace_id', $this->ws->id)->update(['route53_zone_id' => 'Z123']);
    $f = collect($this->getJson(cop($this))->json('copilot.findings'))->firstWhere('id', 'dmarc_missing:acme.com');
    expect(collect($f['fix']['actions'])->pluck('type')->all())->toBe(['copy', 'fix']);

    $this->postJson(cop($this, 'copilot/fix'), ['fix' => 'dmarc', 'domain' => 'acme.com', 'value' => 'v=DMARC1; p=reject'])->assertOk()
        ->assertJsonPath('published', 'v=DMARC1; p=none; rua=mailto:dmarc@acme.com');
    $change = collect($this->aws->argsFor('ChangeResourceRecordSets'))->last();
    expect($change['HostedZoneId'])->toBe('Z123')->and($change['ChangeBatch']['Changes'][0]['ResourceRecordSet'])->toMatchArray(['Name' => '_dmarc.acme.com', 'Type' => 'TXT'])
        ->and($change['ChangeBatch']['Changes'][0]['ResourceRecordSet']['ResourceRecords'][0]['Value'])->toBe('"v=DMARC1; p=none; rua=mailto:dmarc@acme.com"');

    $this->dns->records['TXT _dmarc.acme.com'] = ['v=DMARC1; p=reject'];
    $this->postJson(cop($this, 'copilot/fix'), ['fix' => 'dmarc', 'domain' => 'acme.com'])->assertStatus(422)->assertJsonPath('error.code', 'nothing_to_fix');
    switchTo($this, 'viewer@example.com');
    $this->postJson(cop($this, 'copilot/fix'), ['fix' => 'gentle_microsoft', 'on' => true])->assertForbidden();
});

it('turns on gentle Outlook pacing, which campaigns then use', function () {
    $this->postJson(cop($this, 'copilot/fix'), ['fix' => 'gentle_microsoft', 'on' => true])->assertOk();
    expect($this->ws->refresh()->settings['gentle_microsoft'])->toBeTrue();
    $list = ContactList::create(['workspace_id' => $this->ws->id, 'name' => 'All']);
    foreach (range(1, 50) as $i) {
        app(ContactService::class)->upsert($this->ws, ['email' => "p$i@outlook.com", 'lists' => [$list->id]], 'import');
    }
    $doc = json_decode(file_get_contents(base_path('tests/fixtures/design.json')), true);
    $id = $this->postJson("/v1/workspaces/{$this->ws->id}/campaigns", ['name' => 'Pacing'])->json('campaign.id');
    $this->patchJson("/v1/workspaces/{$this->ws->id}/campaigns/$id", ['audience_type' => 'list', 'audience_id' => $list->id, 'from_email' => 'news@acme.com', 'subject' => 'Hi', 'doc' => $doc])->assertOk();
    config(['queue.default' => 'database']);
    $this->postJson("/v1/workspaces/{$this->ws->id}/campaigns/$id/send", ['mode' => 'now'])->assertOk();
    (new StartCampaign($id))->handle(app(CampaignService::class));
    app()->call([new SendCampaignBatch($id), 'handle']);
    expect(Message::where('campaign_id', $id)->count())->toBe(SendCampaignBatch::GENTLE_MICROSOFT_PER_BATCH);
});

it('reads DMARC aggregate reports as XML, gzip or zip, once each, for its own domains only', function () {
    $xml = fn (string $domain, string $id) => '<?xml version="1.0"?><feedback><report_metadata><org_name>google.com</org_name><report_id>'.$id.'</report_id><date_range><begin>'.now()->subDay()->timestamp.'</begin><end>'.now()->timestamp.'</end></date_range></report_metadata>'
        .'<policy_published><domain>'.$domain.'</domain><p>none</p></policy_published>'
        .'<record><row><source_ip>54.240.1.1</source_ip><count>40</count><policy_evaluated><disposition>none</disposition><dkim>pass</dkim><spf>pass</spf></policy_evaluated></row><identifiers><header_from>acme.com</header_from></identifiers><auth_results><dkim><domain>acme.com</domain><result>pass</result></dkim><spf><domain>bounce.acme.com</domain><result>pass</result></spf></auth_results></record>'
        .'<record><row><source_ip>203.0.113.9</source_ip><count>5</count><policy_evaluated><disposition>none</disposition><dkim>fail</dkim><spf>fail</spf></policy_evaluated></row><identifiers><header_from>acme.com</header_from></identifiers><auth_results><spf><domain>evil.example</domain><result>fail</result></spf></auth_results></record></feedback>';
    $upload = fn (string $name, string $body) => $this->post(cop($this, 'copilot/dmarc'), ['file' => UploadedFile::fake()->createWithContent($name, $body)], ['Accept' => 'application/json']);

    $upload('r1.xml', $xml('acme.com', 'r-1'))->assertCreated()->assertJsonPath('report.messages', 45)->assertJsonPath('report.passed', 40);
    $upload('r2.xml.gz', gzencode($xml('acme.com', 'r-2')))->assertCreated();
    $zip = tempnam(sys_get_temp_dir(), 'z');
    $za = new ZipArchive;
    $za->open($zip, ZipArchive::OVERWRITE);
    $za->addFromString('report.xml', $xml('acme.com', 'r-3'));
    $za->close();
    $upload('r3.zip', (string) file_get_contents($zip))->assertCreated();
    $upload('r1-again.xml', $xml('acme.com', 'r-1'))->assertCreated()->assertJsonPath('report.duplicate', true);
    $upload('other.xml', $xml('stranger.org', 'r-9'))->assertStatus(422)->assertJsonPath('error.code', 'foreign_domain');
    $upload('junk.xml', '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><x>&e;</x>')->assertStatus(422);

    $s = $this->getJson(cop($this, 'copilot/dmarc'))->assertOk()->json('dmarc')['acme.com'];
    expect($s['messages'])->toBe(135)->and($s['passed'])->toBe(120)->and($s['sources'][0]['ip'])->toBe('54.240.1.1');
    $this->dns->records['TXT _dmarc.acme.com'] = ['v=DMARC1; p=none'];
    $f = collect($this->getJson(cop($this))->json('copilot.findings'))->firstWhere('id', 'dmarc_none:acme.com');
    expect($f['evidence']['reports'])->toBe(['messages' => 135, 'passed' => 120, 'sources' => 2]);
});

it('reports real usage for the sidebar', function () {
    sentMail($this, 7);
    sentMail($this, 3, ['source' => 'test']);
    sentMail($this, 2, ['created_at' => now()->subMonths(2)]);
    $this->getJson(cop($this, 'usage'))->assertOk()->assertJsonPath('usage.month', 7)->assertJsonStructure(['usage' => ['ses' => ['sent_24h', 'max_24h']]]);
});
