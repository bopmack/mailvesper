<?php

use App\Models\Campaign;
use App\Models\ContactList;
use App\Models\EmailTemplate;
use App\Models\Segment;
use App\Models\Workspace;

beforeEach(function () {
    [$this->ws] = sesWorkspace($this);
});

it('pages a long list with a total, and searches across every page', function () {
    foreach (range(1, 120) as $i) {
        ContactList::forceCreate(['workspace_id' => $this->ws->id, 'name' => sprintf('List %03d', $i)]);
    }
    $p1 = $this->getJson("/v1/workspaces/{$this->ws->id}/lists?page=1")->assertOk()->json();
    expect($p1['lists'])->toHaveCount(50)->and($p1['meta'])->toBe(['page' => 1, 'per_page' => 50, 'total' => 120, 'has_more' => true]);
    $p3 = $this->getJson("/v1/workspaces/{$this->ws->id}/lists?page=3")->json();
    expect($p3['lists'])->toHaveCount(20)->and($p3['meta']['has_more'])->toBeFalse();
    // No row twice, none missing.
    $all = collect([1, 2, 3])->flatMap(fn ($p) => $this->getJson("/v1/workspaces/{$this->ws->id}/lists?page=$p")->json('lists'))->pluck('id');
    expect($all->unique())->toHaveCount(120);
    // Search reaches rows past the first page (List 110–119).
    $s = $this->getJson("/v1/workspaces/{$this->ws->id}/lists?page=1&q=List%2011")->json();
    expect($s['meta']['total'])->toBe(10)->and(collect($s['lists'])->pluck('name'))->toContain('List 119');
    // per_page is capped.
    expect($this->getJson("/v1/workspaces/{$this->ws->id}/lists?page=1&per_page=5000")->json('meta.per_page'))->toBe(100);
    // Pickers (no page) still get everything, in the old shape.
    $old = $this->getJson("/v1/workspaces/{$this->ws->id}/lists")->json();
    expect($old['lists'])->toHaveCount(120)->and($old)->not->toHaveKey('meta');
});

it('pages segments, templates and campaigns the same way', function () {
    foreach (range(1, 60) as $i) {
        Segment::forceCreate(['workspace_id' => $this->ws->id, 'name' => "Seg $i", 'rules' => ['match' => 'all', 'conditions' => []]]);
        EmailTemplate::forceCreate(['workspace_id' => $this->ws->id, 'name' => "Tpl $i", 'doc' => '{}', 'html' => '<p>x</p>']);
        Campaign::forceCreate(['workspace_id' => $this->ws->id, 'name' => "Camp $i"]);
    }
    foreach (['segments' => 50, 'templates' => 48, 'campaigns' => 50] as $key => $per) {
        $r = $this->getJson("/v1/workspaces/{$this->ws->id}/$key?page=1")->assertOk()->json();
        expect($r[$key])->toHaveCount($per)->and($r['meta']['total'])->toBe(60)->and($r['meta']['has_more'])->toBeTrue();
        expect($this->getJson("/v1/workspaces/{$this->ws->id}/$key?page=2")->json($key))->toHaveCount(60 - $per);
    }
    expect($this->getJson("/v1/workspaces/{$this->ws->id}/segments")->json('segments'))->toHaveCount(60);
    expect($this->getJson("/v1/workspaces/{$this->ws->id}/campaigns?page=1&q=Camp%206")->json('meta.total'))->toBe(2); // 6, 60
});

it('keeps paging inside the workspace', function () {
    ContactList::forceCreate(['workspace_id' => $this->ws->id, 'name' => 'Mine']);
    $other = Workspace::create(['name' => 'Other', 'slug' => 'other', 'owner_id' => $this->ws->owner_id]);
    ContactList::forceCreate(['workspace_id' => $other->id, 'name' => 'Theirs']);
    expect($this->getJson("/v1/workspaces/{$this->ws->id}/lists?page=1&q=")->json('meta.total'))->toBe(1);
});
