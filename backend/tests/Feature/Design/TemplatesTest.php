<?php

use App\Http\Controllers\Api\V1\Sending\WebViewController;
use App\Models\ApiKey;
use App\Models\EmailTemplate;
use App\Models\Message;
use App\Services\Sending\Compose;
use App\Services\Ses\DnsLookup;
use Tests\Support\FakeAws;
use Tests\Support\FakeDns;

beforeEach(function () {
    FakeAws::install();
    $this->app->instance(DnsLookup::class, new FakeDns);
    [$this->ws] = sesWorkspace($this, ['viewer']);
    sesConnect($this, $this->ws)->assertOk();
    $this->doc = json_decode(file_get_contents(base_path('tests/fixtures/design.json')), true);
});

function tUrl($test, string $path = ''): string
{
    return "/v1/workspaces/{$test->ws->id}/templates".($path ? "/$path" : '');
}

it('keeps the server renderer identical to the builder’s', function () {
    $app = base_path('../app/email-render.js');
    if (! file_exists($app)) {
        $this->markTestSkipped('Front-end source not present (deployed copy).');
    }
    expect(sha1_file(resource_path('email-render.cjs')))->toBe(sha1_file($app), 'Copy app/email-render.js to backend/resources/email-render.cjs');
});

it('saves a design and renders it on the server with checks', function () {
    $r = $this->postJson(tUrl($this), ['name' => 'Product update', 'subject' => 'What’s new, {{ first_name | there }}', 'doc' => $this->doc])->assertCreated();
    $t = EmailTemplate::first();
    expect($t->html)->toStartWith('<!doctype html>')
        ->toContain('https://send.test/app/img/eb/hero.png')           // hosted PNG, not an SVG data URI
        ->toContain('<!--[if mso]><v:roundrect')                       // Outlook buttons
        ->toContain('{{unsubscribe_url}}')                             // merged at send time
        ->not->toContain('data:image/svg');
    expect($r->json('template.size_kb'))->toBeGreaterThan(5)->toBeLessThan(30)
        ->and(collect($r->json('template.checks'))->pluck('status', 'name')->all())->toMatchArray(['Gmail clipping' => 'ok', 'Unsubscribe link & address' => 'ok', 'Preview text' => 'ok']);

    // The render endpoint returns exactly what was stored.
    expect($this->postJson(tUrl($this, 'render'), ['doc' => $this->doc, 'subject' => 'What’s new, {{ first_name | there }}'])->json('html'))->toBe($t->html);
});

it('refuses broken or unsafe designs with a clear message', function () {
    $bad = $this->doc;
    $bad['settings']['btn'] = 'red;}</style><script>alert(1)</script>';
    $this->postJson(tUrl($this), ['name' => 'x', 'doc' => $bad])->assertStatus(422)->assertJsonPath('error.message', 'The btn colour isn’t a valid colour.');
    $bad = $this->doc;
    $bad['settings']['font'] = 'Comic Sans; color:red';
    $this->postJson(tUrl($this), ['name' => 'x', 'doc' => $bad])->assertStatus(422)->assertJsonPath('error.code', 'invalid_design');
    $bad = $this->doc;
    $bad['rows'][1]['cells'][0][0]['type'] = 'iframe';
    $this->postJson(tUrl($this), ['name' => 'x', 'doc' => $bad])->assertStatus(422)->assertJsonPath('error.message', 'Row 2 has a block of an unknown type.');
    $bad = $this->doc;
    $bad['rows'][1]['cells'][0][3]['props']['href'] = 'javascript:alert(1)';
    $this->postJson(tUrl($this), ['name' => 'x', 'doc' => $bad])->assertStatus(422);
    $this->postJson(tUrl($this), ['name' => 'x', 'doc' => ['rows' => []]])->assertStatus(422);
    expect(EmailTemplate::count())->toBe(0);
});

it('updates, re-renders, duplicates and deletes; viewers can only look', function () {
    $id = $this->postJson(tUrl($this), ['name' => 'Welcome', 'doc' => $this->doc])->json('template.id');
    $doc = $this->doc;
    $doc['settings']['btn'] = '#DB2777';
    $this->patchJson(tUrl($this, $id), ['doc' => $doc, 'name' => 'Welcome v2'])->assertOk()->assertJsonPath('template.name', 'Welcome v2');
    expect(EmailTemplate::find($id)->html)->toContain('fillcolor="#DB2777"');
    $copy = $this->postJson(tUrl($this, "$id/duplicate"))->assertCreated()->json('template.id');
    expect(EmailTemplate::find($copy)->name)->toBe('Welcome v2 (copy)');
    $this->getJson(tUrl($this))->assertOk()->assertJsonCount(2, 'templates')->assertJsonMissingPath('templates.0.html');

    switchTo($this, 'viewer@example.com');
    $this->getJson(tUrl($this, $id))->assertOk()->assertJsonPath('template.doc.settings.btn', '#DB2777');
    $this->patchJson(tUrl($this, $id), ['name' => 'nope'])->assertForbidden();
    $this->deleteJson(tUrl($this, $id))->assertForbidden();
    switchTo($this, 'owner@example.com');
    $this->deleteJson(tUrl($this, $copy))->assertOk();
    expect(EmailTemplate::count())->toBe(1);
});

it('sends a template: the email is the saved HTML with merge tags filled, and every footer link works', function () {
    $id = $this->postJson(tUrl($this), ['name' => 'Product update', 'subject' => 'What’s new, {{ first_name | there }}', 'doc' => $this->doc])->json('template.id');
    [, $key] = ApiKey::generate($this->ws->id, 'k', 'send', null);
    $this->flushHeaders()->withHeaders(['Authorization' => "Bearer $key", 'Accept' => 'application/json'])
        ->postJson('/v1/emails', ['from' => 'news@acme.com', 'to' => 'amara@example.com', 'template' => $id, 'variables' => ['first_name' => 'Amara']])->assertStatus(202);

    $m = Message::first();
    $t = EmailTemplate::find($id);
    expect($m->subject)->toBe('What’s new, Amara')
        ->and($m->unsubscribe_token)->not->toBeNull() // transactional, but the design has an unsubscribe link
        ->and($m->html)->toBe(Compose::merge($t->html, ['first_name' => 'Amara', 'email' => 'amara@example.com', 'tracking_code' => $m->tracking_code,
            'unsubscribe_url' => 'https://send.test/u/?t='.$m->unsubscribe_token, 'preferences_url' => 'https://send.test/u/?t='.$m->unsubscribe_token,
            'web_version_url' => WebViewController::url($m->id)], true))
        ->and($m->html)->not->toContain('{{')
        ->and($t->refresh()->last_used_at)->not->toBeNull();

    // "View in browser" shows that exact email, sandboxed.
    preg_match('#https://send\.test/api(/v1/view/[^"]+)#', $m->html, $v);
    $this->get($v[1])->assertOk()->assertHeader('Content-Security-Policy')->assertSee('Your dashboard just got faster', false);
    $this->get(preg_replace('#/[0-9a-f]{20}$#', '/'.str_repeat('0', 20), $v[1]))->assertNotFound();
});

it('sends a test of a saved template', function () {
    $id = $this->postJson(tUrl($this), ['name' => 'Welcome', 'doc' => $this->doc])->json('template.id');
    $this->postJson(tUrl($this, "$id/test"), ['from' => 'news@acme.com', 'to' => 'me@example.com'])->assertStatus(202)->assertJsonPath('subject', '[Test] Welcome')->assertJsonPath('status', 'queued');
    expect(Message::first()->status)->toBe('sent');
    $this->postJson('/v1/workspaces/'.$this->ws->id.'/emails/test', ['from' => 'news@acme.com', 'to' => 'x@example.com', 'template' => 'nope'])->assertStatus(202); // plain test ignores unknown keys
});
