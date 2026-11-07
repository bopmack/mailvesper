<?php

use App\Models\Contact;
use App\Models\EmailTemplate;
use App\Models\ProviderImport;
use App\Models\Suppression;
use App\Services\Ses\DnsLookup;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeAws;
use Tests\Support\FakeDns;

beforeEach(function () {
    FakeAws::install();
    $this->app->instance(DnsLookup::class, new FakeDns);
    [$this->ws] = sesWorkspace($this);
});

/** Connects and runs a whole import synchronously; returns the finished import. */
function runImport($test, string $provider, array $creds = [], array $options = []): ProviderImport
{
    $id = $test->postJson("/v1/workspaces/{$test->ws->id}/migrations", ['provider' => $provider] + $creds)->assertCreated()->json('import.id');
    $test->postJson("/v1/workspaces/{$test->ws->id}/migrations/$id/start", $options)->assertOk();

    return ProviderImport::findOrFail($id);
}

function contactsByEmail($test): array
{
    return Contact::where('workspace_id', $test->ws->id)->get()->keyBy('email')->all();
}

it('imports a Mailchimp account: every audience, all pages, suppressions first, interests as tags, campaigns as templates', function () {
    $members = function (array $q) {
        $status = $q['status'] ?? null;
        if ($status === 'unsubscribed') {
            return ['members' => [['email_address' => 'left@example.com']], 'total_items' => 1];
        }
        if ($status === 'cleaned') {
            return ['members' => [['email_address' => 'dead@example.com']], 'total_items' => 1];
        }
        $page = (int) ($q['offset'] ?? 0) === 0
            ? [['email_address' => 'Amara@Example.com', 'status' => 'subscribed', 'merge_fields' => ['FNAME' => 'Amara', 'LNAME' => 'Okafor', 'COMPANY' => 'Lumen', 'ADDRESS' => ['addr1' => 'x']], 'tags' => [['id' => 1, 'name' => 'VIP']], 'interests' => ['i1' => true, 'i2' => false]],
                ['email_address' => 'left@example.com', 'status' => 'subscribed', 'merge_fields' => [], 'tags' => [], 'interests' => []],
                ['email_address' => 'unsure@example.com', 'status' => 'pending', 'merge_fields' => [], 'tags' => [], 'interests' => []]]
            : [['email_address' => 'last@example.com', 'status' => 'cleaned', 'merge_fields' => [], 'tags' => [], 'interests' => []]];

        return ['members' => $page, 'total_items' => 1001]; // forces a second page at offset 1000
    };
    Http::fake(function (Request $r) use ($members) {
        expect($r->url())->toStartWith('https://us21.api.mailchimp.com/3.0/')->and($r->header('Authorization')[0])->toBe('Basic '.base64_encode('sendline:abc123def-us21'));
        parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);
        $path = (string) parse_url($r->url(), PHP_URL_PATH);

        return Http::response(match (true) {
            $path === '/3.0/' => ['account_name' => 'Acme Shoes'],
            $path === '/3.0/lists' => ['lists' => [['id' => 'L1', 'name' => 'Main audience', 'stats' => ['member_count' => 3, 'unsubscribe_count' => 1, 'cleaned_count' => 1]]], 'total_items' => 1],
            $path === '/3.0/lists/L1/members' => $members($q),
            $path === '/3.0/lists/L1/abuse-reports' => ['abuse_reports' => [['email_address' => 'angry@example.com']], 'total_items' => 1],
            $path === '/3.0/lists/L1/interest-categories' => ['categories' => [['id' => 'C1']]],
            $path === '/3.0/lists/L1/interest-categories/C1/interests' => ['interests' => [['id' => 'i1', 'name' => 'Running shoes'], ['id' => 'i2', 'name' => 'Boots']]],
            $path === '/3.0/campaigns' => isset($q['sort_field']) ? ['campaigns' => [['id' => 'cmp1', 'settings' => ['subject_line' => 'Hi *|FNAME|*', 'title' => 'October news']]]] : ['total_items' => 1],
            $path === '/3.0/campaigns/cmp1/content' => ['html' => '<html><body>Hello *|FNAME|* from *|COMPANY|* <a href="*|UNSUB|*">Unsubscribe</a> *|LIST:ADDRESSLINE|*</body></html>'],
            default => ['detail' => 'not found '.$path],
        }, str_starts_with((string) ($path), '/3.0') ? 200 : 404);
    });
    $imp = runImport($this, 'mailchimp', ['api_key' => 'abc123def-us21']);
    expect($imp->status)->toBe('done')->and($imp->account_name)->toBe('Acme Shoes')->and($imp->discovery['contacts'])->toBe(5)->and($imp->discovery['suppressions'])->toBe(2);
    expect(Suppression::where('workspace_id', $this->ws->id)->pluck('reason', 'email')->all())->toEqual(['left@example.com' => 'unsubscribe', 'dead@example.com' => 'hard_bounce', 'angry@example.com' => 'complaint', 'last@example.com' => 'hard_bounce']);
    $c = contactsByEmail($this);
    expect(array_keys($c))->not->toContain('unsure@example.com') // pending: never confirmed
        ->and($c['left@example.com']->status)->toBe('unsubscribed') // listed as subscribed in the page, suppressed first: stays out
        ->and($c['amara@example.com']->first_name)->toBe('Amara')->and($c['amara@example.com']->fields)->toBe(['company' => 'Lumen'])
        ->and($c['last@example.com']->status)->toBe('bounced')
        ->and(DB::table('contact_tags')->where('contact_id', $c['amara@example.com']->id)->orderBy('tag')->pluck('tag')->all())->toBe(['group: running shoes', 'vip']);
    $t = EmailTemplate::where('workspace_id', $this->ws->id)->firstOrFail();
    expect($t->name)->toBe('October news')->and($t->subject)->toBe('Hi {{ first_name }}')->and($t->html)->toContain('Hello {{ first_name }} from {{ company }}')->toContain('href="{{unsubscribe_url}}"')->not->toContain('*|');
});

it('imports Brevo: blocked contacts by reason code, blocklisted contacts, attributes and lists', function () {
    Http::fake(function (Request $r) {
        expect($r->header('api-key')[0])->toBe('xkeysib-abcdefgh');
        parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);
        $path = (string) parse_url($r->url(), PHP_URL_PATH);

        return Http::response(match ($path) {
            '/v3/account' => ['companyName' => 'Lagos Bakes'],
            '/v3/contacts' => (int) $q['limit'] === 1 ? ['count' => 2] : ['contacts' => [['email' => 'ada@example.com', 'attributes' => ['FIRSTNAME' => 'Ada', 'LASTNAME' => 'Obi', 'CITY' => 'Lagos'], 'listIds' => [7], 'emailBlacklisted' => false],
                ['email' => 'no@example.com', 'attributes' => [], 'listIds' => [7], 'emailBlacklisted' => true]], 'count' => 2],
            '/v3/smtp/blockedContacts' => (int) $q['limit'] === 1 ? ['count' => 2] : ['contacts' => [['email' => 'spam@example.com', 'reason' => ['code' => 'contactFlaggedAsSpam']], ['email' => 'hb@example.com', 'reason' => ['code' => 'hardBounce']]], 'count' => 2],
            '/v3/smtp/templates' => (int) $q['limit'] === 1 ? ['count' => 1] : ['templates' => [['name' => 'Promo', 'subject' => 'Hi {{ contact.FIRSTNAME }}', 'htmlContent' => '<p>Hi {{ contact.FIRSTNAME | default : "friend" }} in {{ contact.CITY }}</p><a href="{{ unsubscribe }}">x</a>']], 'count' => 1],
            '/v3/contacts/lists' => ['lists' => [['id' => 7, 'name' => 'Customers', 'uniqueSubscribers' => 2]], 'count' => 1],
            default => [],
        });
    });
    $imp = runImport($this, 'brevo', ['api_key' => 'xkeysib-abcdefgh']);
    expect($imp->status)->toBe('done')->and($imp->account_name)->toBe('Lagos Bakes');
    expect(Suppression::where('workspace_id', $this->ws->id)->pluck('reason', 'email')->all())->toEqual(['spam@example.com' => 'complaint', 'hb@example.com' => 'hard_bounce', 'no@example.com' => 'unsubscribe']);
    $c = contactsByEmail($this);
    expect($c['ada@example.com']->fields)->toBe(['city' => 'Lagos'])->and($c['ada@example.com']->last_name)->toBe('Obi')->and($c['no@example.com']->status)->toBe('unsubscribed')
        ->and(DB::table('lists')->where('workspace_id', $this->ws->id)->value('name'))->toBe('Customers');
    expect(EmailTemplate::where('workspace_id', $this->ws->id)->value('html'))->toContain('Hi {{ first_name | friend }} in {{ city }}')->toContain('href="{{unsubscribe_url}}"');
});

it('imports Kit: subscriber states, cursor pages, tags and broadcasts', function () {
    Http::fake(function (Request $r) {
        expect($r->header('X-Kit-Api-Key')[0])->toBe('kit_abcdefgh');
        parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);
        $path = (string) parse_url($r->url(), PHP_URL_PATH);
        $sub = fn ($e, $state, $tags = []) => ['email_address' => $e, 'first_name' => 'X', 'state' => $state, 'fields' => ['last_name' => 'Y', 'city' => 'Abuja'], 'tags' => array_map(fn ($t) => ['name' => $t], $tags)];

        return Http::response(match (true) {
            $path === '/v4/account' => ['account' => ['name' => 'Creator Co']],
            $path === '/v4/subscribers' && ($q['status'] ?? '') === 'all' && ! isset($q['include']) => ['subscribers' => [], 'pagination' => ['total_count' => 4, 'has_next_page' => false]],
            $path === '/v4/subscribers' && ($q['status'] ?? '') === 'cancelled' => ['subscribers' => [$sub('gone@example.com', 'cancelled')], 'pagination' => ['has_next_page' => false]],
            $path === '/v4/subscribers' && in_array($q['status'] ?? '', ['bounced', 'complained'], true) => ['subscribers' => [], 'pagination' => ['has_next_page' => false]],
            $path === '/v4/subscribers' && ! isset($q['after']) => ['subscribers' => [$sub('fan@example.com', 'active', ['Superfan']), $sub('new@example.com', 'inactive')], 'pagination' => ['has_next_page' => true, 'end_cursor' => 'CUR2']],
            $path === '/v4/subscribers' => ['subscribers' => [$sub('gone@example.com', 'cancelled')], 'pagination' => ['has_next_page' => false]],
            $path === '/v4/broadcasts' => ['broadcasts' => [['id' => 9, 'subject' => 'News', 'content' => '<p>Hey {{ subscriber.first_name | default: "there" }}</p>{{ unsubscribe_link }}']], 'pagination' => ['has_next_page' => false]],
            default => [],
        });
    });
    $imp = runImport($this, 'kit', ['api_key' => 'kit_abcdefgh']);
    expect($imp->status)->toBe('done')->and($imp->progress['contacts']['skipped'])->toBe(1);
    $c = contactsByEmail($this);
    expect(array_keys($c))->not->toContain('new@example.com')->and($c['fan@example.com']->last_name)->toBe('Y')->and($c['gone@example.com']->status)->toBe('unsubscribed')
        ->and(DB::table('contact_tags')->where('contact_id', $c['fan@example.com']->id)->pluck('tag')->sort()->values()->all())->toBe(['superfan']);
    expect(EmailTemplate::where('workspace_id', $this->ws->id)->value('html'))->toContain('Hey {{ first_name | there }}')->toContain('<a href="{{unsubscribe_url}}">Unsubscribe</a>');
});

it('imports Klaviyo: suppression reasons, consent, list membership; refuses next links to other hosts', function () {
    Http::fake(function (Request $r) {
        expect($r->header('Authorization')[0])->toBe('Klaviyo-API-Key pk_abcdefgh')->and($r->header('revision')[0])->toBe('2026-10-15');
        parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);
        $path = (string) parse_url($r->url(), PHP_URL_PATH);
        $p = fn ($e, $mk) => ['attributes' => ['email' => $e, 'first_name' => 'K', 'last_name' => 'L', 'properties' => ['Shoe size' => '42'], 'subscriptions' => ['email' => ['marketing' => $mk]]]];

        return Http::response(match (true) {
            $path === '/api/accounts' => ['data' => [['attributes' => ['contact_information' => ['organization_name' => 'Klav Shop']]]]],
            $path === '/api/lists' => ['data' => [['id' => 'LST', 'attributes' => ['name' => 'VIPs']]], 'links' => ['next' => null]],
            $path === '/api/profiles' && isset($q['filter']) => str_contains($q['filter'], 'SPAM_COMPLAINT') ? ['data' => [$p('spam@example.com', [])], 'links' => ['next' => null]] : ['data' => [], 'links' => ['next' => null]],
            $path === '/api/profiles' => ['data' => [$p('yes@example.com', ['can_receive_email_marketing' => true, 'consent' => 'SUBSCRIBED']), $p('never@example.com', ['can_receive_email_marketing' => false, 'consent' => 'NEVER_SUBSCRIBED']),
                $p('unsub@example.com', ['can_receive_email_marketing' => false, 'consent' => 'UNSUBSCRIBED'])], 'links' => ['next' => null]],
            $path === '/api/lists/LST/profiles' => ['data' => [$p('yes@example.com', ['can_receive_email_marketing' => true])], 'links' => ['next' => null]],
            $path === '/api/templates' => ['data' => [['attributes' => ['name' => 'Flow email', 'html' => '<p>Hi {{ person.first_name|default:"pal" }}</p>{% unsubscribe %}']]], 'links' => ['next' => null]],
            default => [],
        });
    });
    $imp = runImport($this, 'klaviyo', ['api_key' => 'pk_abcdefgh']);
    expect($imp->status)->toBe('done');
    expect(Suppression::where('workspace_id', $this->ws->id)->pluck('reason', 'email')->all())->toEqual(['spam@example.com' => 'complaint', 'unsub@example.com' => 'unsubscribe']);
    $c = contactsByEmail($this);
    expect(array_keys($c))->not->toContain('never@example.com')->and($c['yes@example.com']->fields)->toBe(['shoe_size' => '42'])
        ->and(DB::table('contact_list')->where('contact_id', $c['yes@example.com']->id)->count())->toBe(1);
    expect(EmailTemplate::where('workspace_id', $this->ws->id)->value('html'))->toContain('Hi {{ first_name | pal }}')->toContain('<a href="{{unsubscribe_url}}">Unsubscribe</a>');

});

it('refuses to follow a next-page link to another host (the API key would go with it)', function () {
    Http::fake(['a.klaviyo.com/api/accounts*' => Http::response(['data' => [['attributes' => ['contact_information' => ['organization_name' => 'X']]]]]), 'a.klaviyo.com/api/lists*' => Http::response(['data' => [], 'links' => ['next' => 'https://evil.example/steal']])]);
    $this->postJson("/v1/workspaces/{$this->ws->id}/migrations", ['provider' => 'klaviyo', 'api_key' => 'pk_abcdefgh'])->assertStatus(422)->assertJsonPath('error.message', 'Klaviyo returned a link to another host, so we stopped rather than send your key there.');
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'evil.example'));
});

it('imports Mailgun from the EU region: domain check, suppressions by kind, list members', function () {
    Http::fake(function (Request $r) {
        expect($r->url())->toStartWith('https://api.eu.mailgun.net/')->and($r->header('Authorization')[0])->toBe('Basic '.base64_encode('api:key-abcdefgh'));
        $path = rawurldecode((string) parse_url($r->url(), PHP_URL_PATH));

        return Http::response(match (true) {
            $path === '/v4/domains' => ['items' => [['name' => 'mg.acme.com', 'state' => 'active']]],
            $path === '/v3/mg.acme.com/bounces' => str_contains($r->url(), 'page=next') ? ['items' => [], 'paging' => []] : ['items' => [['address' => 'b@example.com', 'code' => '550']], 'paging' => ['next' => 'https://api.eu.mailgun.net/v3/mg.acme.com/bounces?page=next&address=b']],
            $path === '/v3/mg.acme.com/complaints' => ['items' => [['address' => 'c@example.com']], 'paging' => []],
            $path === '/v3/mg.acme.com/unsubscribes' => ['items' => [], 'paging' => []],
            $path === '/v3/lists' => ['items' => [['address' => 'news@mg.acme.com', 'name' => 'News', 'members_count' => 1]]],
            $path === '/v3/lists/news@mg.acme.com/members/pages' => ['items' => [['address' => 'm@example.com', 'name' => 'Musa Bello', 'subscribed' => true, 'vars' => ['tier' => 'gold']]], 'paging' => []],
            $path === '/v3/mg.acme.com/templates' => ['items' => []],
            default => [],
        });
    });
    $imp = runImport($this, 'mailgun', ['api_key' => 'key-abcdefgh', 'domain' => 'mg.acme.com', 'region' => 'eu']);
    expect($imp->status)->toBe('done')->and(Suppression::where('workspace_id', $this->ws->id)->pluck('reason', 'email')->all())->toEqual(['b@example.com' => 'hard_bounce', 'c@example.com' => 'complaint']);
    $m = contactsByEmail($this)['m@example.com'];
    expect([$m->first_name, $m->last_name, $m->fields])->toBe(['Musa', 'Bello', ['tier' => 'gold']]);
    $this->postJson("/v1/workspaces/{$this->ws->id}/migrations", ['provider' => 'mailgun', 'api_key' => 'key-abcdefgh', 'domain' => 'other.com', 'region' => 'eu'])->assertStatus(422)->assertJsonPath('error.code', 'provider_error');
});

it('imports SendGrid: five suppression lists, the contact export (waits, then reads gzip JSON lines), templates', function () {
    config(['queue.default' => 'database']);
    $state = (object) ['polls' => 0];
    Http::fake(function (Request $r) use ($state) {
        $path = (string) parse_url($r->url(), PHP_URL_PATH);
        if (str_starts_with($r->url(), 'https://exports.example-s3.com/')) {
            expect($r->hasHeader('Authorization'))->toBeFalse(); // the presigned file link never gets the key

            return Http::response(gzencode(json_encode(['email' => 'one@example.com', 'first_name' => 'One', 'list_ids' => ['LA'], 'custom_fields' => ['plan' => 'pro']])."\n".json_encode(['email' => 'spam@example.com', 'list_ids' => []])));
        }
        expect($r->header('Authorization')[0])->toBe('Bearer SG.abcdefgh');

        return Http::response(match (true) {
            $path === '/v3/user/profile' => ['company' => 'Grid Co'],
            $path === '/v3/marketing/contacts/count' => ['contact_count' => 2],
            $path === '/v3/marketing/lists' => ['result' => [['id' => 'LA', 'name' => 'All', 'contact_count' => 2]], '_metadata' => []],
            $path === '/v3/suppression/spam_reports' => [['email' => 'spam@example.com']],
            $path === '/v3/suppression/bounces' => [['email' => 'bounce@example.com']],
            str_starts_with($path, '/v3/suppression/') => [],
            $path === '/v3/asm/suppressions' => [['email' => 'group@example.com', 'group_name' => 'Weekly']],
            $path === '/v3/marketing/contacts/exports' => ['id' => 'EXP1'],
            $path === '/v3/marketing/contacts/exports/EXP1' => ++$state->polls < 2 ? ['status' => 'pending'] : ['status' => 'ready', 'urls' => ['https://exports.example-s3.com/f1.json.gz'], 'contact_count' => 2],
            $path === '/v3/templates' => ['result' => [['id' => 'd-1', 'name' => 'Receipt']]],
            $path === '/v3/templates/d-1' => ['versions' => [['active' => 1, 'subject' => 'Thanks {{first_name}}', 'html_content' => '<p>Hi {{first_name}}</p><a href="{{{unsubscribe}}}">Unsub</a>']]],
            default => [],
        });
    });
    $id = $this->postJson("/v1/workspaces/{$this->ws->id}/migrations", ['provider' => 'sendgrid', 'api_key' => 'SG.abcdefgh'])->assertCreated()->json('import.id');
    $this->postJson("/v1/workspaces/{$this->ws->id}/migrations/$id/start")->assertOk();
    $this->artisan('queue:work', ['--once' => true, '--queue' => 'default'])->assertSuccessful();
    $i = ProviderImport::find($id);
    expect($i->status)->toBe('importing')->and($i->error)->toBe('SendGrid is still preparing the contact export.')->and(Suppression::count())->toBe(3);
    $this->travel(2)->minutes();
    $this->artisan('queue:work', ['--stop-when-empty' => true, '--queue' => 'default'])->assertSuccessful();
    expect(ProviderImport::find($id)->status)->toBe('done');
    $c = contactsByEmail($this);
    expect($c['one@example.com']->fields)->toBe(['plan' => 'pro'])->and($c['spam@example.com']->status)->toBe('complained') // SendGrid contacts have no status; the suppression decides
        ->and(Suppression::where('email', 'group@example.com')->value('reason'))->toBe('unsubscribe')
        ->and(EmailTemplate::where('workspace_id', $this->ws->id)->value('html'))->toContain('Hi {{ first_name }}')->toContain('href="{{unsubscribe_url}}"')
        ->and(Storage::disk('local')->exists('imports/sendgrid-EXP1-0.jsonl'))->toBeFalse();
});
