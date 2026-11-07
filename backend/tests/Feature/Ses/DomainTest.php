<?php

use App\Models\Domain;
use App\Models\Workspace;
use App\Services\Ses\DnsLookup;
use Tests\Support\FakeAws;
use Tests\Support\FakeDns;

beforeEach(function () {
    $this->aws = FakeAws::install();
    $this->app->instance(DnsLookup::class, $this->dns = new FakeDns);
    $this->aws->responses['ListEmailIdentities'] = ['EmailIdentities' => []];
});

it('adds a domain with 2048-bit DKIM, a custom MAIL FROM and the records to publish', function () {
    [$ws] = sesWorkspace($this);
    sesConnect($this, $ws)->assertOk();
    $this->postJson("/v1/workspaces/{$ws->id}/domains", ['name' => 'not a domain'])->assertStatus(422)->assertJsonPath('error.fields.name', 'Enter a domain like mail.yourcompany.com.');

    $d = $this->postJson("/v1/workspaces/{$ws->id}/domains", ['name' => ' Mail.Example.COM. '])->assertCreated()->json('domain');
    expect($d['name'])->toBe('mail.example.com')->and($d['status'])->toBe('pending')->and($d['mail_from_domain'])->toBe('bounce.mail.example.com');

    $create = $this->aws->argsFor('CreateEmailIdentity')[0];
    expect($create['DkimSigningAttributes'])->toBe(['NextSigningKeyLength' => 'RSA_2048_BIT'])->and($create['ConfigurationSetName'])->toBe('sendline-events');

    $t = FakeAws::tokens('mail.example.com');
    expect(collect($d['records'])->map(fn ($r) => [$r['type'], $r['host'], $r['value'], $r['live']])->all())->toBe([
        ['CNAME', "{$t[0]}._domainkey.mail.example.com", "{$t[0]}.dkim.amazonses.com", 'missing'],
        ['CNAME', "{$t[1]}._domainkey.mail.example.com", "{$t[1]}.dkim.amazonses.com", 'missing'],
        ['CNAME', "{$t[2]}._domainkey.mail.example.com", "{$t[2]}.dkim.amazonses.com", 'missing'],
        ['MX', 'bounce.mail.example.com', '10 feedback-smtp.eu-west-1.amazonses.com', 'missing'],
        ['TXT', 'bounce.mail.example.com', 'v=spf1 include:amazonses.com ~all', 'missing'],
        ['TXT', '_dmarc.mail.example.com', 'v=DMARC1; p=none;', 'missing'],
    ]);
});

it('turns records green as DNS publishes them and verifies when SES does', function () {
    [$ws] = sesWorkspace($this);
    sesConnect($this, $ws)->assertOk();
    $d = $this->postJson("/v1/workspaces/{$ws->id}/domains", ['name' => 'example.com'])->assertCreated()->json('domain');

    $this->dns->publish(array_slice($d['records'], 0, 3));
    $this->dns->records['TXT bounce.example.com'] = ['v=spf1 include:_spf.google.com ~all']; // an SPF without SES
    $this->travel(11)->seconds();
    $d = $this->postJson("/v1/workspaces/{$ws->id}/domains/{$d['id']}/check")->assertOk()->json('domain');
    expect(collect($d['records'])->pluck('live', 'key')->all())->toMatchArray(['dkim1' => 'found', 'dkim3' => 'found', 'mx' => 'missing', 'spf' => 'mismatch', 'dmarc' => 'missing']);

    $this->aws->responses['GetEmailIdentity'] = fn () => FakeAws::identity('example.com', true);
    $this->travel(11)->seconds();
    $d = $this->postJson("/v1/workspaces/{$ws->id}/domains/{$d['id']}/check")->assertOk()->json('domain');
    expect($d['status'])->toBe('verified')->and($d['verified_at'])->not->toBeNull()->and($d['dkim_status'])->toBe('SUCCESS');
});

it('finds the Route 53 zone and writes the records there, keeping an existing DMARC policy', function () {
    [$ws] = sesWorkspace($this);
    sesConnect($this, $ws)->assertOk();
    $this->aws->responses['ListHostedZonesByName'] = fn (array $a) => ['IsTruncated' => false, 'MaxItems' => '1', 'HostedZones' => $a['DNSName'] === 'example.com'
        ? [['Id' => '/hostedzone/Z123', 'Name' => 'example.com.', 'CallerReference' => 'x', 'Config' => ['PrivateZone' => false]]]
        : [['Id' => '/hostedzone/Z999', 'Name' => 'zzz.org.', 'CallerReference' => 'y']]];
    $this->dns->records['TXT _dmarc.mail.example.com'] = ['v=DMARC1; p=reject'];

    $d = $this->postJson("/v1/workspaces/{$ws->id}/domains", ['name' => 'mail.example.com'])->assertCreated()->json('domain');
    expect($d['dns_provider'])->toBe('route53');

    $this->postJson("/v1/workspaces/{$ws->id}/domains/{$d['id']}/route53")->assertOk()->assertJsonPath('written', 5);
    $batch = $this->aws->argsFor('ChangeResourceRecordSets')[0];
    expect($batch['HostedZoneId'])->toBe('Z123');
    $sets = collect($batch['ChangeBatch']['Changes'])->pluck('ResourceRecordSet');
    expect($sets->pluck('Name')->all())->not->toContain('_dmarc.mail.example.com')
        ->and($sets->firstWhere('Type', 'TXT')['ResourceRecords'][0]['Value'])->toBe('"v=spf1 include:amazonses.com ~all"');
});

it('falls back to manual DNS when the keys can’t use Route 53', function () {
    [$ws] = sesWorkspace($this);
    sesConnect($this, $ws)->assertOk();
    $this->aws->fail('ListHostedZonesByName', 'AccessDenied');
    $d = $this->postJson("/v1/workspaces/{$ws->id}/domains", ['name' => 'example.com'])->assertCreated()->json('domain');
    expect($d['dns_provider'])->toBeNull();
    $this->postJson("/v1/workspaces/{$ws->id}/domains/{$d['id']}/route53")->assertStatus(422)->assertJsonPath('error.code', 'route53_not_found');
});

it('reuses an identity that already exists in SES', function () {
    [$ws] = sesWorkspace($this);
    sesConnect($this, $ws)->assertOk();
    $this->aws->fail('CreateEmailIdentity', 'AlreadyExistsException');
    $this->aws->responses['GetEmailIdentity'] = fn () => FakeAws::identity('acme.com', true);
    $this->postJson("/v1/workspaces/{$ws->id}/domains", ['name' => 'acme.com'])->assertCreated()->assertJsonPath('domain.status', 'verified');
});

it('needs a connection, keeps workspaces apart and limits changes to owners and admins', function () {
    [$ws, $p] = sesWorkspace($this, ['member']);
    $this->postJson("/v1/workspaces/{$ws->id}/domains", ['name' => 'example.com'])->assertStatus(422)->assertJsonPath('error.code', 'ses_not_connected');
    sesConnect($this, $ws)->assertOk();
    $id = $this->postJson("/v1/workspaces/{$ws->id}/domains", ['name' => 'example.com'])->json('domain.id');

    $other = Workspace::create(['name' => 'Other', 'slug' => 'other', 'owner_id' => $p['owner']->id]);
    $other->members()->attach($p['owner']->id, ['role' => 'owner']);
    $this->getJson("/v1/workspaces/{$other->id}/domains/$id")->assertNotFound();
    $this->deleteJson("/v1/workspaces/{$other->id}/domains/$id")->assertNotFound();

    app('auth')->forgetGuards();
    $this->postJson('/v1/auth/logout');
    app('auth')->forgetGuards();
    $this->postJson('/v1/auth/login', ['email' => 'member@example.com', 'password' => STRONG_PASSWORD])->assertOk();
    $this->getJson("/v1/workspaces/{$ws->id}/domains")->assertOk()->assertJsonCount(1, 'domains');
    $this->postJson("/v1/workspaces/{$ws->id}/domains", ['name' => 'two.com'])->assertForbidden();
    $this->deleteJson("/v1/workspaces/{$ws->id}/domains/$id")->assertForbidden();
});

it('removes a domain from MailVesper only', function () {
    [$ws] = sesWorkspace($this);
    sesConnect($this, $ws)->assertOk();
    $id = $this->postJson("/v1/workspaces/{$ws->id}/domains", ['name' => 'example.com'])->json('domain.id');
    $this->deleteJson("/v1/workspaces/{$ws->id}/domains/$id")->assertOk();
    expect(Domain::count())->toBe(0)->and($this->aws->argsFor('DeleteEmailIdentity'))->toBe([]);
});

it('re-checks waiting domains on a schedule', function () {
    [$ws] = sesWorkspace($this);
    sesConnect($this, $ws)->assertOk();
    $this->postJson("/v1/workspaces/{$ws->id}/domains", ['name' => 'example.com'])->assertCreated();
    $this->aws->responses['GetEmailIdentity'] = fn () => FakeAws::identity('example.com', true);
    $this->artisan('domains:check')->assertSuccessful();
    expect(Domain::first()->status)->toBe('verified');
});
