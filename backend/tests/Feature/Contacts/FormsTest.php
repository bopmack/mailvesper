<?php

use App\Models\Contact;
use App\Models\ContactList;
use App\Models\FormSubmission;
use App\Models\JourneyRun;
use App\Models\Message;
use App\Models\Suppression;
use App\Models\Workspace;
use App\Services\Journeys\JourneyEvents;
use App\Services\Ops\Retention;
use App\Services\Ses\DnsLookup;
use Tests\Support\FakeAws;
use Tests\Support\FakeDns;

beforeEach(function () {
    FakeAws::install();
    $this->app->instance(DnsLookup::class, new FakeDns);
    [$this->ws] = sesWorkspace($this);
    sesConnect($this, $this->ws)->assertOk();
    $this->list = ContactList::create(['workspace_id' => $this->ws->id, 'name' => 'Newsletter']);
    JourneyEvents::flush();
});

function fUrl($test, string $path = ''): string
{
    return "/v1/workspaces/{$test->ws->id}/forms".($path ? "/$path" : '');
}

function newForm($test, array $over = []): array
{
    return $test->postJson(fUrl($test), $over + ['publish' => true, 'name' => 'Footer', 'heading' => 'Join us', 'list_id' => $test->list->id, 'tags' => ['Website'],
        'fields' => [['key' => 'first_name', 'label' => 'First name'], ['key' => 'company', 'label' => 'Company', 'required' => true]],
        'double_opt_in' => false])->assertCreated()->json('form');
}

/** A live journey started by a form trigger, ending in one tag step. */
function formJourney($test, string $trigger, ?string $formId = null): string
{
    $id = $test->postJson("/v1/workspaces/{$test->ws->id}/journeys", ['name' => $trigger])->json('journey.id');
    $test->patchJson("/v1/workspaces/{$test->ws->id}/journeys/$id", ['graph' => ['nodes' => [['id' => 't1', 'type' => 'trigger', 'config' => ['trigger' => $trigger, 'form_id' => $formId]],
        ['id' => 'k1', 'type' => 'tag', 'config' => ['tag' => 'from-form']]], 'edges' => [['from' => 't1', 'to' => 'k1']]], 'settings' => ['reentry' => 'always']])->assertOk();
    $test->postJson("/v1/workspaces/{$test->ws->id}/journeys/$id/publish")->assertOk();
    JourneyEvents::flush();

    return $id;
}

function confirmToken(string $email): string
{
    $html = (string) Message::where('to_email', $email)->latest('created_at')->value('html');
    preg_match('#/f/\?c=([A-Za-z0-9]{48})#', $html, $m);

    return $m[1] ?? '';
}

it('checks a form’s settings', function () {
    $this->postJson(fUrl($this), ['name' => ''])->assertStatus(422)->assertJsonPath('error.fields.name', 'Name the form.');
    $this->postJson(fUrl($this), ['name' => 'X', 'redirect_url' => 'javascript:alert(1)'])->assertStatus(422)->assertJsonPath('error.fields.redirect_url', 'Enter a full web address, starting with https://.');
    $this->postJson(fUrl($this), ['name' => 'X', 'fields' => [['key' => 'email']]])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    $this->postJson(fUrl($this), ['name' => 'X', 'double_opt_in' => true, 'from_email' => 'news@not-mine.com'])->assertStatus(422)->assertJsonPath('error.fields.from_email', 'That address isn’t on a verified domain.');
    $f = $this->postJson(fUrl($this), ['name' => 'X'])->assertCreated()->json('form');
    expect($f['key'])->toMatch('/^[a-z0-9]{20}$/')->and($f['double_opt_in'])->toBeTrue()->and($f['page_url'])->toBe("https://send.test/f/?k={$f['key']}")
        ->and($f['fields'])->toBe([['key' => 'first_name', 'label' => 'First name', 'required' => false]]);
});

it('adds the contact at once without double opt-in, and answers any website', function () {
    $f = newForm($this);
    $journey = formJourney($this, 'form_submitted', $f['id']);
    $this->flushHeaders()->getJson("/v1/f/{$f['key']}")->assertOk()->assertHeader('Access-Control-Allow-Origin', '*')
        ->assertJsonPath('form.heading', 'Join us')->assertJsonPath('form.sender', 'Acme')->assertJsonMissingPath('form.list_id');
    $this->call('OPTIONS', "/v1/f/{$f['key']}")->assertNoContent()->assertHeader('Access-Control-Allow-Origin', '*');

    $this->postJson("/v1/f/{$f['key']}", ['email' => 'Ada@Example.com', 'first_name' => 'Ada'])->assertStatus(422)
        ->assertJsonPath('error.fields.company', 'Please fill in company.')->assertHeader('Access-Control-Allow-Origin', '*');
    $this->postJson("/v1/f/{$f['key']}", ['email' => 'Ada@Example.com', 'first_name' => 'Ada', 'company' => 'Analytical'])->assertOk()
        ->assertJsonPath('status', 'added')->assertJsonPath('message', 'Thanks for signing up!');
    $c = Contact::where('email', 'ada@example.com')->firstOrFail();
    expect($c->first_name)->toBe('Ada')->and($c->fields['company'])->toBe('Analytical')->and($c->source)->toBe('form')
        ->and($c->refresh()->status)->toBe('subscribed')
        ->and(DB::table('contact_list')->where('contact_id', $c->id)->value('list_id'))->toBe($this->list->id)
        ->and(DB::table('contact_tags')->where('contact_id', $c->id)->pluck('tag')->all())->toContain('website', 'from-form')
        ->and(JourneyRun::where('journey_id', $journey)->count())->toBe(1);

    // A bot fills the hidden field: it's told "thanks", nothing is stored.
    $this->postJson("/v1/f/{$f['key']}", ['email' => 'bot@example.com', 'company' => 'x', '_hp' => 'http://spam'])->assertOk();
    expect(Contact::where('email', 'bot@example.com')->exists())->toBeFalse();

    // A plain HTML form on someone's site: form-encoded, answered with a redirect.
    $this->post("/v1/f/{$f['key']}", ['email' => 'html@example.com', 'company' => 'Acme'], ['Accept' => 'text/html'])->assertStatus(303)
        ->assertRedirect("https://send.test/f/?k={$f['key']}&done=added");
    $this->patchJson(fUrl($this, $f['id']), ['redirect_url' => 'https://acme.com/thanks', 'publish' => true])->assertOk();
    $this->post("/v1/f/{$f['key']}", ['email' => 'html2@example.com', 'company' => 'Acme'], ['Accept' => 'text/html'])->assertRedirect('https://acme.com/thanks');
    $this->post("/v1/f/{$f['key']}", ['email' => 'nope'], ['Accept' => 'text/html'])->assertStatus(303)->assertRedirectContains('error=');

    // Without double opt-in, a form never resubscribes someone who unsubscribed.
    Suppression::add($this->ws->id, 'gone@example.com', 'unsubscribe');
    $this->postJson("/v1/f/{$f['key']}", ['email' => 'gone@example.com', 'company' => 'x'])->assertOk();
    expect(Suppression::check($this->ws->id, ['gone@example.com']))->toBe(['gone@example.com' => 'unsubscribe']);

    $this->patchJson(fUrl($this, $f['id']), ['enabled' => false])->assertOk();
    $this->postJson("/v1/f/{$f['key']}", ['email' => 'late@example.com', 'company' => 'x'])->assertStatus(410)->assertJsonPath('error.code', 'form_closed');
    $this->getJson(fUrl($this, $f['id']))->assertJsonPath('form.submissions', 4)->assertJsonCount(4, 'recent');
});

it('adds people only after they confirm by email with double opt-in', function () {
    $f = newForm($this, ['double_opt_in' => true, 'from_email' => 'news@acme.com', 'from_name' => 'Acme News', 'success_message' => 'Welcome aboard!']);
    $submitted = formJourney($this, 'form_submitted');
    $confirmed = formJourney($this, 'optin_confirmed', $f['id']);

    $this->postJson("/v1/f/{$f['key']}", ['email' => 'grace@example.com', 'first_name' => 'Grace', 'company' => 'Navy'])->assertStatus(202)->assertJsonPath('status', 'pending');
    $this->postJson("/v1/f/{$f['key']}", ['email' => 'grace@example.com', 'company' => 'Navy'])->assertStatus(202); // again: no second email
    expect(Contact::where('email', 'grace@example.com')->exists())->toBeFalse()
        ->and(Message::where('to_email', 'grace@example.com')->count())->toBe(1);
    $m = Message::where('to_email', 'grace@example.com')->first();
    expect($m->subject)->toBe('Confirm your subscription to Acme')->and($m->type)->toBe('transactional')->and($m->from_email)->toBe('news@acme.com');
    $token = confirmToken('grace@example.com');
    expect($token)->not->toBe('')->and(FormSubmission::value('token_hash'))->toBe(hash('sha256', $token)); // only the hash is stored

    $this->postJson("/v1/f/confirm/$token")->assertOk()->assertJsonPath('message', 'Welcome aboard!');
    $this->postJson("/v1/f/confirm/$token")->assertOk(); // twice: added once
    $c = Contact::where('email', 'grace@example.com')->firstOrFail();
    expect($c->first_name)->toBe('Grace')->and(JourneyRun::where('journey_id', $submitted)->count())->toBe(1)
        ->and(JourneyRun::where('journey_id', $confirmed)->count())->toBe(1);
    $this->getJson(fUrl($this, $f['id']))->assertJsonPath('form.confirmed', 1);
    $this->postJson('/v1/f/confirm/'.str_repeat('a', 48))->assertNotFound();

    // Confirming by email is what brings an unsubscribed person back.
    Suppression::add($this->ws->id, 'back@example.com', 'unsubscribe');
    $this->postJson("/v1/f/{$f['key']}", ['email' => 'back@example.com', 'company' => 'x'])->assertStatus(202);
    $this->postJson('/v1/f/confirm/'.confirmToken('back@example.com'))->assertOk();
    expect(Suppression::check($this->ws->id, ['back@example.com']))->toBe([]);

    // A bounced address gets the same answer but no email; links expire after a week.
    Suppression::add($this->ws->id, 'bounced@example.com', 'hard_bounce');
    $this->postJson("/v1/f/{$f['key']}", ['email' => 'bounced@example.com', 'company' => 'x'])->assertStatus(202)->assertJsonPath('status', 'pending');
    expect(Message::where('to_email', 'bounced@example.com')->exists())->toBeFalse();
    $this->postJson("/v1/f/{$f['key']}", ['email' => 'slow@example.com', 'company' => 'x'])->assertStatus(202);
    $slow = confirmToken('slow@example.com');
    $this->travel(8)->days();
    $this->postJson("/v1/f/confirm/$slow")->assertStatus(410)->assertJsonPath('error.code', 'expired');

    // Unconfirmed sign-ups are deleted after a month; a form a journey starts from can't be deleted.
    $this->travel(30)->days();
    Retention::prune();
    expect(FormSubmission::where('status', 'pending')->count())->toBe(0)->and(FormSubmission::where('status', 'confirmed')->count())->toBe(2);
    $this->deleteJson(fUrl($this, $f['id']))->assertStatus(409)->assertJsonPath('error.code', 'in_use');
});

it('keeps forms to editors and to their own workspace', function () {
    $f = newForm($this);
    [$other] = [Workspace::create(['name' => 'Other', 'slug' => 'other', 'owner_id' => $this->ws->owner_id])];
    $this->getJson("/v1/workspaces/{$other->id}/forms/{$f['id']}")->assertNotFound(); // not a member
    $this->getJson(fUrl($this))->assertOk()->assertJsonCount(1, 'forms');
    $this->deleteJson(fUrl($this, $f['id']))->assertOk();
    $this->getJson("/v1/f/{$f['key']}")->assertNotFound();
});
