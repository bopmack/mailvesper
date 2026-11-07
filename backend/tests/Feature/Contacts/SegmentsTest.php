<?php

use App\Models\ContactList;
use App\Services\Contacts\ContactService;
use App\Services\Ses\DnsLookup;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeAws;
use Tests\Support\FakeDns;

beforeEach(function () {
    FakeAws::install();
    $this->app->instance(DnsLookup::class, new FakeDns);
    [$this->ws] = sesWorkspace($this, ['viewer']);
    $svc = app(ContactService::class);
    $this->list = ContactList::create(['workspace_id' => $this->ws->id, 'name' => 'Customers']);
    DB::table('contact_fields')->insert(['workspace_id' => $this->ws->id, 'key' => 'seats', 'label' => 'Seats', 'type' => 'number', 'created_at' => now()]);
    $svc->upsert($this->ws, ['email' => 'amara@gmail.com', 'first_name' => 'Amara', 'fields' => ['plan' => 'Growth', 'seats' => 12], 'tags' => ['vip'], 'lists' => [$this->list->id]], 'import');
    $svc->upsert($this->ws, ['email' => 'bo@acme.io', 'first_name' => 'Bo', 'fields' => ['plan' => 'Starter', 'seats' => 2]], 'api');
    $svc->upsert($this->ws, ['email' => 'chen@gmail.com', 'fields' => ['plan' => 'Growth']], 'manual');
    DB::table('contacts')->where('email', 'amara@gmail.com')->update(['last_opened_at' => now()->subDays(3)]);
    DB::table('contacts')->where('email', 'chen@gmail.com')->update(['last_opened_at' => now()->subDays(60)]);
});

function preview($test, array $conditions, string $match = 'all')
{
    return $test->postJson("/v1/workspaces/{$test->ws->id}/segments/preview", ['rules' => ['match' => $match, 'conditions' => $conditions]]);
}

it('matches text, numbers, dates, tags and lists', function () {
    $count = fn (array $c, string $m = 'all') => preview($this, $c, $m)->assertOk()->json('count');
    expect($count([['field' => 'email', 'op' => 'ends_with', 'value' => '@GMAIL.com']]))->toBe(2)
        ->and($count([['field' => 'field:plan', 'op' => 'is', 'value' => 'Growth']]))->toBe(2)
        ->and($count([['field' => 'field:seats', 'op' => 'gt', 'value' => 5]]))->toBe(1)
        ->and($count([['field' => 'last_opened_at', 'op' => 'within_days', 'value' => 30]]))->toBe(1)
        ->and($count([['field' => 'last_opened_at', 'op' => 'not_within_days', 'value' => 30]]))->toBe(2) // includes never opened
        ->and($count([['field' => 'tag', 'op' => 'has', 'value' => 'VIP']]))->toBe(1)
        ->and($count([['field' => 'list', 'op' => 'not_in', 'value' => $this->list->id]]))->toBe(2)
        ->and($count([['field' => 'first_name', 'op' => 'is_not_set']]))->toBe(1)
        ->and($count([['field' => 'source', 'op' => 'is', 'value' => 'api'], ['field' => 'tag', 'op' => 'has', 'value' => 'vip']], 'any'))->toBe(2)
        ->and($count([['field' => 'field:plan', 'op' => 'is', 'value' => 'Growth'], ['field' => 'last_opened_at', 'op' => 'within_days', 'value' => 14]]))->toBe(1);
    expect(preview($this, [['field' => 'field:plan', 'op' => 'is', 'value' => 'Growth']])->json('sample.0.email'))->toBeIn(['amara@gmail.com', 'chen@gmail.com']);
});

it('explains incomplete rules', function () {
    preview($this, [['field' => 'field:plan', 'op' => 'gt', 'value' => 'x']])->assertStatus(422)->assertJsonPath('error.message', 'Condition 1 isn’t complete. Pick a field and how to compare it.');
    preview($this, [['field' => 'created_at', 'op' => 'within_days', 'value' => 'soon']])->assertStatus(422);
    preview($this, [['field' => 'nope', 'op' => 'is', 'value' => 1]])->assertStatus(422);
    preview($this, [['field' => 'field:Bad Key', 'op' => 'is', 'value' => 1]])->assertStatus(422);
    preview($this, array_fill(0, 21, ['field' => 'email', 'op' => 'is_set']))->assertStatus(422);
});

it('saves segments with counts, filters contacts by segment, and limits viewers', function () {
    $s = $this->postJson("/v1/workspaces/{$this->ws->id}/segments", ['name' => 'Growth plan', 'rules' => ['match' => 'all', 'conditions' => [['field' => 'field:plan', 'op' => 'is', 'value' => 'Growth']]]])
        ->assertCreated()->assertJsonPath('segment.count', 2)->json('segment');
    expect($this->getJson("/v1/workspaces/{$this->ws->id}/contacts?segment={$s['id']}")->json('total'))->toBe(2);
    $cat = $this->getJson("/v1/workspaces/{$this->ws->id}/segments/catalog")->assertOk();
    expect(collect($cat->json('fields'))->pluck('field'))->toContain('field:seats', 'tag', 'list')->and($cat->json('tags'))->toBe(['vip']);
    switchTo($this, 'viewer@example.com');
    $this->postJson("/v1/workspaces/{$this->ws->id}/segments", ['name' => 'x', 'rules' => ['match' => 'all', 'conditions' => []]])->assertForbidden();
    $this->getJson("/v1/workspaces/{$this->ws->id}/segments")->assertOk()->assertJsonCount(1, 'segments');
});
