<?php

use App\Models\ContactList;
use App\Models\Form;
use App\Services\Ses\DnsLookup;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeAws;
use Tests\Support\FakeDns;

beforeEach(function () {
    FakeAws::install();
    $this->app->instance(DnsLookup::class, new FakeDns);
    [$this->ws] = sesWorkspace($this);
    sesConnect($this, $this->ws)->assertOk();
    $this->list = ContactList::create(['workspace_id' => $this->ws->id, 'name' => 'Newsletter']);
});

function dUrl($test, string $path = ''): string
{
    return "/v1/workspaces/{$test->ws->id}/forms".($path ? "/$path" : '');
}

it('starts a form as a draft that shows nothing publicly until it’s published', function () {
    $f = $this->postJson(dUrl($this), ['name' => 'Footer', 'heading' => 'Join us', 'double_opt_in' => false])->assertCreated()->json('form');
    expect($f['state'])->toBe('draft')->and($f['published_at'])->toBeNull();
    $this->getJson("/v1/f/{$f['key']}")->assertNotFound()->assertJsonPath('error.code', 'not_published');
    $this->postJson("/v1/f/{$f['key']}", ['email' => 'ada@example.com'])->assertNotFound();

    // Before the first publish, edits apply in place (nothing is live yet).
    $this->patchJson(dUrl($this, $f['id']), ['heading' => 'Join the list'])->assertOk()->assertJsonPath('form.state', 'draft');
    $this->postJson(dUrl($this, "{$f['id']}/discard"))->assertStatus(422)->assertJsonPath('error.code', 'not_published');

    $this->postJson(dUrl($this, "{$f['id']}/publish"))->assertOk()->assertJsonPath('form.state', 'live');
    $this->getJson("/v1/f/{$f['key']}")->assertOk()->assertJsonPath('form.heading', 'Join the list');
    $this->postJson("/v1/f/{$f['key']}", ['email' => 'ada@example.com'])->assertOk()->assertJsonPath('status', 'added');
});

it('keeps the live form unchanged while the draft is edited, then publishes or discards it', function () {
    $f = $this->postJson(dUrl($this), ['publish' => true, 'name' => 'Footer', 'heading' => 'Join us', 'button' => 'Subscribe', 'double_opt_in' => false,
        'design' => ['accent' => '#111111']])->assertCreated()->json('form');
    expect($f['state'])->toBe('live');

    $r = $this->patchJson(dUrl($this, $f['id']), ['heading' => 'New heading', 'design' => ['accent' => '#0f766e'], 'list_id' => $this->list->id])->assertOk()->json('form');
    expect($r['state'])->toBe('changed')->and($r['heading'])->toBe('New heading')->and($r['design']['accent'])->toBe('#0F766E'); // the builder sees the draft
    $live = $this->getJson("/v1/f/{$f['key']}")->assertOk()->json('form');
    expect($live['heading'])->toBe('Join us')->and($live['design']['accent'])->toBe('#111111'); // sites still get the live one
    $this->postJson("/v1/f/{$f['key']}", ['email' => 'early@example.com'])->assertOk();
    expect(DB::table('contact_list')->where('list_id', $this->list->id)->count())->toBe(0); // the live form adds to no list yet

    // Undoing an edit by hand leaves only the other changes.
    $this->patchJson(dUrl($this, $f['id']), ['heading' => 'Join us'])->assertOk()->assertJsonPath('form.state', 'changed');
    expect(Form::find($f['id'])->draft)->not->toHaveKey('heading');

    $this->postJson(dUrl($this, "{$f['id']}/publish"))->assertOk()->assertJsonPath('form.state', 'live');
    expect($this->getJson("/v1/f/{$f['key']}")->json('form.design.accent'))->toBe('#0F766E');
    $this->postJson("/v1/f/{$f['key']}", ['email' => 'later@example.com'])->assertOk();
    expect(DB::table('contact_list')->where('list_id', $this->list->id)->count())->toBe(1);

    $this->patchJson(dUrl($this, $f['id']), ['button' => 'Join'])->assertOk()->assertJsonPath('form.state', 'changed');
    $this->postJson(dUrl($this, "{$f['id']}/discard"))->assertOk()->assertJsonPath('form.state', 'live')->assertJsonPath('form.button', 'Subscribe');
});

it('applies the name and open / closed at once, and saves and publishes in one call when asked', function () {
    $f = $this->postJson(dUrl($this), ['publish' => true, 'name' => 'Footer', 'double_opt_in' => false])->json('form');
    $this->patchJson(dUrl($this, $f['id']), ['name' => 'Footer form', 'enabled' => false])->assertOk()->assertJsonPath('form.state', 'live')->assertJsonPath('form.name', 'Footer form');
    $this->getJson("/v1/f/{$f['key']}")->assertJsonPath('form.open', false);
    $this->patchJson(dUrl($this, $f['id']), ['enabled' => true, 'heading' => 'Straight live', 'publish' => true])->assertOk()->assertJsonPath('form.state', 'live');
    $this->getJson("/v1/f/{$f['key']}")->assertJsonPath('form.heading', 'Straight live')->assertJsonPath('form.open', true);
});

it('publishes a draft whose list was deleted meanwhile without breaking sign-ups', function () {
    $f = $this->postJson(dUrl($this), ['publish' => true, 'name' => 'Footer', 'double_opt_in' => false])->json('form');
    $gone = ContactList::create(['workspace_id' => $this->ws->id, 'name' => 'Old']);
    $this->patchJson(dUrl($this, $f['id']), ['list_id' => $gone->id])->assertOk();
    $gone->delete();
    $this->postJson(dUrl($this, "{$f['id']}/publish"))->assertOk()->assertJsonPath('form.list_id', null);
    $this->postJson("/v1/f/{$f['key']}", ['email' => 'ok@example.com'])->assertOk();
});

it('keeps publishing and discarding to editors', function () {
    $f = $this->postJson(dUrl($this), ['name' => 'Footer', 'double_opt_in' => false])->json('form');
    $viewer = verifiedUser(['email' => 'viewer@example.com']);
    $this->ws->members()->attach($viewer->id, ['role' => 'viewer']);
    switchTo($this, 'viewer@example.com');
    $this->postJson(dUrl($this, "{$f['id']}/publish"))->assertForbidden();
    $this->postJson(dUrl($this, "{$f['id']}/discard"))->assertForbidden();
});
