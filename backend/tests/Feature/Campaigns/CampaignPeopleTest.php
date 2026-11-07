<?php

use App\Models\Contact;
use App\Models\Message;
use App\Services\Contacts\ContactService;
use App\Services\Ses\DnsLookup;
use Tests\Support\FakeAws;
use Tests\Support\FakeDns;

beforeEach(function () {
    FakeAws::install();
    $this->app->instance(DnsLookup::class, new FakeDns);
    [$this->ws] = sesWorkspace($this);
    sesConnect($this, $this->ws)->assertOk();
    $this->doc = json_decode(file_get_contents(base_path('tests/fixtures/design.json')), true);
    $this->base = "/v1/workspaces/{$this->ws->id}/campaigns";
    $svc = app(ContactService::class);
    $this->amara = $svc->upsert($this->ws, ['email' => 'amara@example.com', 'first_name' => 'Amara', 'last_name' => 'Okafor'], 'import');
    $this->gone = $svc->upsert($this->ws, ['email' => 'gone@example.com', 'unsubscribed' => true], 'import');
    $svc->upsert($this->ws, ['email' => 'other@example.com'], 'import');
});

it('sends a campaign to people picked one by one, adding new addresses as contacts', function () {
    $id = $this->postJson($this->base, ['name' => 'Personal invite'])->json('campaign.id');
    $r = $this->patchJson("$this->base/$id", ['people' => [$this->amara->id, 'New.Person@Example.com', $this->gone->id, 'amara@example.com']])->assertOk();
    expect($r->json('campaign.audience_type'))->toBe('people')
        ->and($r->json('campaign.audience_name'))->toBe('3 people')
        ->and(collect($r->json('campaign.people'))->pluck('email')->all())->toBe(['amara@example.com', 'new.person@example.com', 'gone@example.com'])
        ->and($r->json('campaign.people.0.name'))->toBe('Amara Okafor')
        ->and($r->json('campaign.estimate.total'))->toBe(2) // unsubscribed people are skipped
        ->and(Contact::where('email', 'new.person@example.com')->value('source'))->toBe('campaign');

    $this->patchJson("$this->base/$id", ['from_email' => 'news@acme.com', 'subject' => 'Hi {{ first_name | friend }}', 'doc' => $this->doc])->assertOk();
    $this->postJson("$this->base/$id/send", ['mode' => 'now'])->assertOk();
    expect(Message::pluck('to_email')->sort()->values()->all())->toBe(['amara@example.com', 'new.person@example.com']);
});

it('needs at least one real person and checks each pick', function () {
    $id = $this->postJson($this->base, ['name' => 'Empty'])->json('campaign.id');
    $this->patchJson("$this->base/$id", ['audience_type' => 'people'])->assertOk()->assertJsonPath('campaign.audience_name', '0 people');
    expect(collect($this->getJson("$this->base/$id")->json('campaign.problems'))->firstWhere('key', 'audience')['message'])->toBe('Add at least one person.');

    $this->patchJson("$this->base/$id", ['people' => ['not an email@']])->assertStatus(422);
    $this->patchJson("$this->base/$id", ['people' => [str_repeat('Z', 26)]])->assertStatus(422)->assertJsonPath('error.message', 'One of the people picked is no longer a contact.');
    $this->patchJson("$this->base/$id", ['people' => array_map(fn ($i) => "p$i@example.com", range(1, 501))])->assertStatus(422);

    // Switching to a list keeps working as before.
    $this->patchJson("$this->base/$id", ['audience_type' => 'all'])->assertOk()->assertJsonPath('campaign.estimate.total', 2);
});
