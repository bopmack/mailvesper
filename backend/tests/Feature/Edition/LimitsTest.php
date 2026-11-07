<?php

use App\Exceptions\ApiException;
use App\Models\Contact;
use App\Models\Workspace;
use App\Services\Contacts\AddressCheck;
use App\Services\Sending\Mailer;
use App\Services\Ses\DnsLookup;
use App\Support\Edition;
use Illuminate\Http\UploadedFile;
use Tests\Support\FakeAws;
use Tests\Support\FakeDns;

// The core's plan-limit hook points, with a stand-in policy (Pro SaaS mode supplies the real one).

function limitTo(int $contacts, int $emails): void
{
    app(Edition::class)->limitPolicy(fn (Workspace $ws, string $what) => max(0, ($what === 'contacts' ? $contacts - Contact::where('workspace_id', $ws->id)->count() : $emails)),
        fn (Workspace $ws, string $what) => "Plan limit for $what.");
}

it('has no limits without a policy', function () {
    [$ws] = sesWorkspace($this);
    expect(app(Edition::class)->room($ws, 'contacts'))->toBeNull();
    foreach (range(1, 3) as $i) {
        $this->postJson("/v1/workspaces/{$ws->id}/contacts", ['email' => "c$i@x.com"])->assertCreated();
    }
});

it('refuses a new contact over the limit but still updates existing ones', function () {
    [$ws] = sesWorkspace($this);
    limitTo(1, 100);
    $this->postJson("/v1/workspaces/{$ws->id}/contacts", ['email' => 'a@x.com'])->assertCreated();
    $this->postJson("/v1/workspaces/{$ws->id}/contacts", ['email' => 'b@x.com'])->assertStatus(402)
        ->assertJsonPath('error.code', 'plan_limit')->assertJsonPath('error.message', 'Plan limit for contacts.')->assertJsonPath('error.details.limit', 'contacts');
    $id = Contact::where('email', 'a@x.com')->value('id');
    $this->patchJson("/v1/workspaces/{$ws->id}/contacts/$id", ['first_name' => 'Ada'])->assertOk();
});

it('imports what fits and counts the rest as over the limit', function () {
    [$ws] = sesWorkspace($this);
    AddressCheck::$resolver = fn (string $d) => true;
    limitTo(2, 100);
    $csv = "email\na@gmail.com\nb@gmail.com\nc@gmail.com\nd@gmail.com\n";
    $imp = $this->post("/v1/workspaces/{$ws->id}/imports", ['file' => UploadedFile::fake()->createWithContent('c.csv', $csv)])->assertCreated()->json('import');
    $this->postJson("/v1/workspaces/{$ws->id}/imports/{$imp['id']}/start", ['mapping' => ['email'], 'options' => ['consent' => true]])->assertOk();
    $done = $this->getJson("/v1/workspaces/{$ws->id}/imports/{$imp['id']}")->json('import');
    expect($done['stats']['created'])->toBe(2)->and($done['stats']['over_limit'])->toBe(2)->and(Contact::count())->toBe(2);
    AddressCheck::$resolver = null;
});

it('refuses sends over the monthly email room, but never test sends', function () {
    $this->aws = FakeAws::install();
    $this->app->instance(DnsLookup::class, new FakeDns);
    [$ws] = sesWorkspace($this);
    sesConnect($this, $ws)->assertOk(); // imports acme.com as verified
    limitTo(100, 0);
    $mailer = app(Mailer::class);
    $p = $mailer->validate($ws, ['from' => 'news@acme.com', 'to' => 'x@gmail.com', 'subject' => 'Hi', 'html' => '<p>Hi</p>']);
    expect(fn () => $mailer->submit($ws, [$p], 'api'))->toThrow(ApiException::class, 'Plan limit for emails.');
    expect($mailer->submit($ws, [$p], 'test'))->toHaveCount(1); // tests don't count
});
