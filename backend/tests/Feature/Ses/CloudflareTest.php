<?php

use App\Models\DnsConnection;
use App\Models\Domain;
use App\Services\Ses\DnsLookup;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeAws;
use Tests\Support\FakeDns;

const CF_TOKEN = 'cf_test_token_0123456789abcdef';

beforeEach(function () {
    $this->aws = FakeAws::install();
    $this->aws->responses['ListHostedZonesByName'] = ['HostedZones' => []]; // DNS isn't on Route 53
    $this->app->instance(DnsLookup::class, new FakeDns);
    [$this->ws] = sesWorkspace($this, ['member']);
    sesConnect($this, $this->ws)->assertOk();
    // A small Cloudflare: one zone, with a DMARC record someone already chose and a stale DKIM CNAME.
    $this->cf = ['records' => [
        ['id' => 'r1', 'type' => 'TXT', 'name' => '_dmarc.shop.example.net', 'content' => '"v=DMARC1; p=reject; rua=mailto:d@example.net"'],
    ], 'writes' => []];
    Http::fake(function (Request $r) {
        $auth = $r->header('Authorization')[0] ?? '';
        $ok = fn ($result) => Http::response(['success' => true, 'errors' => [], 'result' => $result]);
        if ($auth !== 'Bearer '.CF_TOKEN) {
            return Http::response(['success' => false, 'errors' => [['code' => 1000, 'message' => 'Invalid API Token']]], 401);
        }
        $path = parse_url($r->url(), PHP_URL_PATH);
        parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);
        if ($path === '/client/v4/user/tokens/verify') {
            return $ok(['id' => 't1', 'status' => 'active']);
        }
        if ($path === '/client/v4/zones') {
            return $ok(($q['name'] ?? '') === 'example.net' ? [['id' => 'zone-1', 'name' => 'example.net']] : []);
        }
        if ($r->method() === 'GET' && $path === '/client/v4/zones/zone-1/dns_records') {
            return $ok(array_values(array_filter($this->cf['records'], fn ($x) => $x['type'] === $q['type'] && $x['name'] === $q['name'])));
        }
        if (in_array($r->method(), ['POST', 'PUT'], true)) {
            $this->cf['writes'][] = [$r->method(), $r->data()];
            if ($r->method() === 'POST') {
                $this->cf['records'][] = ['id' => 'n'.count($this->cf['writes']), 'type' => $r->data()['type'], 'name' => $r->data()['name'], 'content' => $r->data()['content']];
            }

            return $ok(['id' => 'x']);
        }

        return Http::response(['success' => false, 'errors' => [['code' => 7003, 'message' => 'No route']]], 404);
    });
});

function cfUrl($test, string $path): string
{
    return "/v1/workspaces/{$test->ws->id}/$path";
}

it('connects a token, finds the zone, and adds only the records that are missing, DNS-only', function () {
    $this->putJson(cfUrl($this, 'dns/cloudflare'), ['token' => 'wrong_token_0123456789abcdef'])->assertStatus(422)
        ->assertJsonPath('error.message', 'Cloudflare refused the token. Create one with the “Edit zone DNS” template and paste it again.');
    $this->putJson(cfUrl($this, 'dns/cloudflare'), ['token' => CF_TOKEN])->assertOk()->assertJsonPath('cloudflare.connected', true)->assertJsonMissingPath('cloudflare.token');
    expect(DnsConnection::first()->getRawOriginal('token'))->not->toBe(CF_TOKEN);

    // Adding a domain on Cloudflare publishes its records straight away.
    $r = $this->postJson(cfUrl($this, 'domains'), ['name' => 'shop.example.net'])->assertCreated();
    $d = Domain::where('name', 'shop.example.net')->firstOrFail();
    expect($d->dns_provider)->toBe('cloudflare')->and($d->cloudflare_zone_id)->toBe('zone-1')
        ->and($r->json('auto_dns'))->toBe(count($d->records()) - 1); // all but DMARC, which already exists
    $posted = collect($this->cf['writes'])->map(fn ($w) => $w[1]);
    expect($posted->every(fn ($b) => $b['proxied'] === false))->toBeTrue() // proxied CNAMEs would break DKIM
        ->and($posted->firstWhere('type', 'MX'))->toMatchArray(['priority' => 10])
        ->and($posted->pluck('name'))->not->toContain('_dmarc.shop.example.net');

    // Run again: everything is there, nothing is written.
    $this->postJson(cfUrl($this, "domains/{$d->id}/cloudflare"))->assertOk()->assertJsonPath('written', 0);
    $this->getJson(cfUrl($this, 'dns/cloudflare'))->assertJsonPath('cloudflare.domains', ['shop.example.net']);

    // Members can't change DNS; disconnecting forgets the zone.
    switchTo($this, 'member@example.com');
    $this->postJson(cfUrl($this, "domains/{$d->id}/cloudflare"))->assertForbidden();
    switchTo($this, 'owner@example.com');
    $this->deleteJson(cfUrl($this, 'dns/cloudflare'))->assertOk();
    expect($d->refresh()->dns_provider)->toBeNull();
    $this->postJson(cfUrl($this, "domains/{$d->id}/cloudflare"))->assertStatus(422)->assertJsonPath('error.code', 'cloudflare_not_found');
});

it('replaces a wrong DKIM record instead of adding a second one', function () {
    $this->putJson(cfUrl($this, 'dns/cloudflare'), ['token' => CF_TOKEN])->assertOk();
    $this->postJson(cfUrl($this, 'domains'), ['name' => 'shop.example.net'])->assertCreated();
    $d = Domain::where('name', 'shop.example.net')->firstOrFail();
    $first = collect($d->records())->firstWhere('key', 'dkim1');
    foreach ($this->cf['records'] as &$rec) {
        if ($rec['name'] === $first['host']) {
            $rec['content'] = 'stale.dkim.amazonses.com';
        }
    }
    unset($rec);
    $this->cf['writes'] = [];
    $this->postJson(cfUrl($this, "domains/{$d->id}/cloudflare"))->assertOk()->assertJsonPath('written', 1);
    expect($this->cf['writes'][0][0])->toBe('PUT')->and($this->cf['writes'][0][1]['content'])->toBe($first['value']);
});
