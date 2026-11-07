<?php

use App\Models\ApiKey;
use App\Models\Contact;
use App\Models\ContactList;
use App\Models\Message;
use App\Models\Suppression;
use App\Models\Topic;
use App\Services\Contacts\ContactService;
use App\Services\Sending\Mailer;
use App\Services\Ses\DnsLookup;
use App\Services\Ses\EventProcessor;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeAws;
use Tests\Support\FakeDns;

beforeEach(function () {
    FakeAws::install();
    $this->app->instance(DnsLookup::class, new FakeDns);
    [$this->ws, $this->people] = sesWorkspace($this, ['member']);
    sesConnect($this, $this->ws)->assertOk();
    $this->c = fn (array $d) => app(ContactService::class)->upsert($this->ws, $d, 'manual');
});

function cUrl($test, string $path = ''): string
{
    return "/v1/workspaces/{$test->ws->id}/".$path;
}

it('creates and updates contacts, merging custom fields', function () {
    $this->postJson(cUrl($this, 'contacts'), ['email' => 'Amara@Example.com', 'first_name' => 'Amara', 'fields' => ['plan' => 'Growth', 'seats' => 4], 'tags' => ['Customer']])->assertCreated()
        ->assertJsonPath('contact.email', 'amara@example.com')->assertJsonPath('contact.status', 'subscribed');
    $this->postJson(cUrl($this, 'contacts'), ['email' => 'amara@example.com'])->assertStatus(422)->assertJsonPath('error.code', 'contact_exists');
    $this->postJson(cUrl($this, 'contacts'), ['email' => 'x@example.com', 'fields' => ['email' => 'nope']])->assertStatus(422)->assertJsonPath('error.fields.fields', fn ($m) => str_contains($m, 'built-in'));

    $id = Contact::first()->id;
    $this->patchJson(cUrl($this, "contacts/$id"), ['fields' => ['seats' => 6, 'plan' => null, 'country' => 'NG'], 'add_tags' => ['vip'], 'remove_tags' => ['customer']])->assertOk()
        ->assertJsonPath('contact.fields', ['seats' => 6, 'country' => 'NG'])->assertJsonPath('contact.tags', ['vip']);
});

it('keeps contact status in step with the suppression list, both ways', function () {
    $c = ($this->c)(['email' => 'amara@example.com']);
    Suppression::add($this->ws->id, 'amara@example.com', 'hard_bounce');
    expect($c->refresh()->status)->toBe('bounced');
    // An upsert (e.g. from a form or import) never re-subscribes.
    ($this->c)(['email' => 'amara@example.com', 'first_name' => 'Amara']);
    expect($c->refresh())->status->toBe('bounced')->first_name->toBe('Amara');

    // Only owners/admins can resubscribe a bounce; doing so clears the suppression.
    switchTo($this, 'member@example.com');
    $this->patchJson(cUrl($this, "contacts/{$c->id}"), ['subscribed' => true])->assertForbidden();
    switchTo($this, 'owner@example.com');
    $this->patchJson(cUrl($this, "contacts/{$c->id}"), ['subscribed' => true])->assertOk()->assertJsonPath('contact.status', 'subscribed');
    expect(Suppression::check($this->ws->id, ['amara@example.com']))->toBe([]);

    $this->patchJson(cUrl($this, "contacts/{$c->id}"), ['subscribed' => false])->assertOk()->assertJsonPath('contact.status', 'unsubscribed');
});

it('lists, searches and filters contacts, with a summary', function () {
    $list = ContactList::create(['workspace_id' => $this->ws->id, 'name' => 'Customers']);
    ($this->c)(['email' => 'amara@example.com', 'first_name' => 'Amara', 'tags' => ['vip'], 'lists' => [$list->id]]);
    ($this->c)(['email' => 'bo@example.com', 'first_name' => 'Bo']);
    Suppression::add($this->ws->id, 'bo@example.com', 'unsubscribe');
    expect($this->getJson(cUrl($this, 'contacts'))->json('total'))->toBe(2)
        ->and($this->getJson(cUrl($this, 'contacts?q=AMA'))->json('contacts.0.email'))->toBe('amara@example.com')
        ->and($this->getJson(cUrl($this, 'contacts?status=unsubscribed'))->json('contacts.0.email'))->toBe('bo@example.com')
        ->and($this->getJson(cUrl($this, 'contacts?tag=VIP'))->json('total'))->toBe(1)
        ->and($this->getJson(cUrl($this, "contacts?list={$list->id}"))->json('total'))->toBe(1);
    $this->getJson(cUrl($this, 'contacts/summary'))->assertJson(['total' => 2, 'subscribed' => 1, 'not_subscribed' => 1, 'tags' => ['vip' => 1]]);
    $this->getJson(cUrl($this, 'lists'))->assertJsonPath('lists.0', ['id' => $list->id, 'name' => 'Customers', 'description' => null, 'total' => 1, 'subscribed' => 1]);
    $csv = $this->get(cUrl($this, 'contacts/export'))->streamedContent();
    expect($csv)->toContain('amara@example.com,Amara,,subscribed,manual')->toContain('vip')->toContain('Customers');
});

it('shows a contact with their real email history and engagement dates', function () {
    $c = ($this->c)(['email' => 'amara@example.com', 'first_name' => 'Amara', 'fields' => ['plan' => 'Growth']]);
    $mailer = app(Mailer::class);
    $m = $mailer->submit($this->ws, [$mailer->validate($this->ws, ['from' => 'news@acme.com', 'to' => 'amara@example.com', 'subject' => 'Hi {{ first_name }}', 'text' => 'Plan: {{ plan }}'])], 'api')[0]->refresh();
    expect($m->subject)->toBe('Hi Amara')->and($m->text)->toBe('Plan: Growth'); // contact fields fill merge tags

    app(EventProcessor::class)->apply($this->ws->id, ['eventType' => 'Send', 'mail' => ['messageId' => $m->ses_message_id, 'tags' => ['sl_message' => [$m->id]]], 'send' => []]);
    app(EventProcessor::class)->apply($this->ws->id, ['eventType' => 'Open', 'mail' => ['messageId' => $m->ses_message_id], 'open' => ['timestamp' => now()->addMinutes(5)->toIso8601ZuluString(), 'userAgent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/130.0']]);
    $r = $this->getJson(cUrl($this, "contacts/{$c->id}"))->assertOk();
    expect($r->json('contact.messages'))->toHaveCount(1)->and($r->json('contact.stats'))->toBe(['sent' => 1, 'opened' => 1, 'clicked' => 0])
        ->and($r->json('contact.last_opened_at'))->not->toBeNull()->and($r->json('contact.last_sent_at'))->not->toBeNull();
});

it('exports and erases a contact (GDPR), keeping the address suppressed', function () {
    $c = ($this->c)(['email' => 'amara@example.com', 'first_name' => 'Amara']);
    $mailer = app(Mailer::class);
    $mailer->submit($this->ws, [$mailer->validate($this->ws, ['from' => 'news@acme.com', 'to' => 'amara@example.com', 'subject' => 'Hi', 'html' => '<p>Hi Amara</p>'])], 'api');

    $export = $this->get(cUrl($this, "contacts/{$c->id}/export"))->assertOk()->assertHeader('Content-Disposition')->json();
    expect($export['contact']['email'])->toBe('amara@example.com')->and($export['emails'])->toHaveCount(1);

    switchTo($this, 'member@example.com');
    $this->deleteJson(cUrl($this, "contacts/{$c->id}"))->assertForbidden();
    switchTo($this, 'owner@example.com');
    $this->deleteJson(cUrl($this, "contacts/{$c->id}"))->assertOk();
    expect(Contact::count())->toBe(0)
        ->and(Message::first())->to_email->toStartWith('erased-')->html->toBeNull()->subject->toBe('[erased]')
        ->and(Suppression::check($this->ws->id, ['amara@example.com']))->toBe(['amara@example.com' => 'manual']);
});

it('skips people who opted out of a topic, and respects opt-in topics', function () {
    $news = Topic::create(['workspace_id' => $this->ws->id, 'name' => 'Newsletter']);
    $beta = Topic::create(['workspace_id' => $this->ws->id, 'name' => 'Beta invites', 'default_subscribed' => false]);
    $a = ($this->c)(['email' => 'a@example.com']);
    $b = ($this->c)(['email' => 'b@example.com']);
    $this->patchJson(cUrl($this, "contacts/{$a->id}"), ['topics' => [$news->id => false, $beta->id => true]])->assertOk();

    $mailer = app(Mailer::class);
    $send = fn ($topic) => collect($mailer->submit($this->ws, [$mailer->validate($this->ws, ['from' => 'news@acme.com', 'to' => ['a@example.com', 'b@example.com', 'new@example.com'], 'subject' => 'x', 'text' => 'x', 'topic' => $topic->id])], 'api'))->mapWithKeys(fn ($m) => [$m->to_email => $m->refresh()->status])->all();
    expect($send($news))->toBe(['a@example.com' => 'suppressed', 'b@example.com' => 'sent', 'new@example.com' => 'sent'])
        ->and($send($beta))->toBe(['a@example.com' => 'sent', 'b@example.com' => 'suppressed', 'new@example.com' => 'suppressed']);
    expect(Message::where('to_email', 'a@example.com')->where('status', 'suppressed')->value('error'))->toBe('Not sent: this person opted out of this topic.');
});

it('lets recipients choose topics on the unsubscribe page', function () {
    $news = Topic::create(['workspace_id' => $this->ws->id, 'name' => 'Newsletter']);
    $product = Topic::create(['workspace_id' => $this->ws->id, 'name' => 'Product updates']);
    $mailer = app(Mailer::class);
    $m = $mailer->submit($this->ws, [$mailer->validate($this->ws, ['from' => 'news@acme.com', 'to' => 'stranger@example.com', 'subject' => 'x', 'text' => 'x', 'topic' => $news->id])], 'api')[0];

    $this->getJson("/v1/unsubscribe/{$m->unsubscribe_token}")->assertJsonPath('topic', 'Newsletter')->assertJsonCount(2, 'topics');
    $this->postJson("/v1/unsubscribe/{$m->unsubscribe_token}", ['topic_id' => $news->id])->assertOk()->assertJsonPath('unsubscribed', false)
        ->assertJsonPath('topics.0.subscribed', false)->assertJsonPath('topics.1.subscribed', true);
    expect(Contact::where('email', 'stranger@example.com')->value('source'))->toBe('form'); // the choice is remembered
    $this->postJson("/v1/unsubscribe/{$m->unsubscribe_token}/preferences", ['topics' => [$news->id => true, $product->id => false]])->assertOk()
        ->assertJsonPath('topics.0.subscribed', true)->assertJsonPath('topics.1.subscribed', false);
});

it('offers a contacts API to full-access keys only', function () {
    [, $send] = ApiKey::generate($this->ws->id, 'send', 'send', null);
    [, $full] = ApiKey::generate($this->ws->id, 'full', 'full', null);
    $this->flushHeaders()->withHeaders(['Authorization' => "Bearer $send", 'Accept' => 'application/json'])->postJson('/v1/contacts', ['email' => 'a@example.com'])->assertForbidden();
    $h = ['Authorization' => "Bearer $full", 'Accept' => 'application/json'];
    $this->flushHeaders()->withHeaders($h)->postJson('/v1/contacts', ['email' => 'a@example.com', 'fields' => ['plan' => 'Pro'], 'tags' => ['api']])->assertCreated();
    $this->flushHeaders()->withHeaders($h)->postJson('/v1/contacts', ['email' => 'A@example.com', 'first_name' => 'Ada'])->assertOk()->assertJsonPath('contact.first_name', 'Ada')->assertJsonPath('contact.fields.plan', 'Pro');
    $this->flushHeaders()->withHeaders($h)->getJson('/v1/contacts/a%40example.com')->assertOk()->assertJsonPath('contact.tags', ['api']);
    $this->flushHeaders()->withHeaders($h)->deleteJson('/v1/contacts/a@example.com')->assertOk();
    expect(Contact::count())->toBe(0);
});

it('scores engagement and flags dormant contacts', function () {
    $active = ($this->c)(['email' => 'active@example.com']);
    $sleepy = ($this->c)(['email' => 'sleepy@example.com']);
    foreach (range(1, 4) as $i) {
        foreach (['active@example.com', 'sleepy@example.com'] as $to) {
            Message::create(['workspace_id' => $this->ws->id, 'tracking_code' => Message::newTrackingCode(), 'source' => 'api', 'from_email' => 'news@acme.com', 'to_email' => $to, 'subject' => 'x',
                'status' => 'delivered', 'created_at' => now()->subDays($i * 10), 'opened_at' => $to === 'active@example.com' ? now()->subDays($i * 10) : null]);
        }
    }
    $active->forceFill(['last_opened_at' => now()->subDays(3)])->save();
    $this->artisan('contacts:engagement')->assertSuccessful();
    expect($active->refresh())->engagement->toBe(80)->dormant->toBeFalse()
        ->and($sleepy->refresh())->engagement->toBe(0)->dormant->toBeTrue();
});

it('registers fields first seen through the API, typed from the value, so segments compare them properly', function () {
    [, $full] = ApiKey::generate($this->ws->id, 'full', 'full', null);
    $h = ['Authorization' => "Bearer $full", 'Accept' => 'application/json'];
    $this->flushHeaders()->withHeaders($h)->postJson('/v1/contacts', ['email' => 'a@example.com', 'fields' => ['total_spent' => 120.5, 'creator' => 'Lola', 'is_vip' => true, 'subscribed_since' => '2026-09-01', 'note' => '2026 plans']])->assertCreated();
    $this->flushHeaders()->withHeaders($h)->postJson('/v1/contacts', ['email' => 'b@example.com', 'fields' => ['total_spent' => 'lots']])->assertCreated(); // an existing definition is never retyped
    $types = DB::table('contact_fields')->where('workspace_id', $this->ws->id)->pluck('type', 'key')->all();
    ksort($types);
    expect($types)->toBe(['creator' => 'text', 'is_vip' => 'boolean', 'note' => 'text', 'subscribed_since' => 'date', 'total_spent' => 'number'])
        ->and(DB::table('contact_fields')->where('workspace_id', $this->ws->id)->where('key', 'total_spent')->value('label'))->toBe('Total spent');
});

it('upserts contacts in batches: all checked first, the plan fits as a whole, repeats merged', function () {
    [, $full] = ApiKey::generate($this->ws->id, 'full', 'full', null);
    $h = ['Authorization' => "Bearer $full", 'Accept' => 'application/json'];
    ($this->c)(['email' => 'old@example.com', 'first_name' => 'Old']);
    $this->flushHeaders()->withHeaders($h)->postJson('/v1/contacts/batch', [['email' => 'ok@example.com'], ['email' => 'not-an-email']])
        ->assertStatus(422)->assertJsonPath('error.fields', ['1.email' => 'Enter a valid email address.']);
    expect(Contact::where('email', 'ok@example.com')->exists())->toBeFalse(); // nothing saved

    $r = $this->flushHeaders()->withHeaders($h)->postJson('/v1/contacts/batch', [
        ['email' => 'OLD@example.com', 'fields' => ['total_spent' => 40]],
        ['email' => 'new@example.com', 'first_name' => 'New', 'tags' => ['fan']],
        ['email' => 'new@example.com', 'first_name' => 'Newer'],
    ])->assertOk()->json();
    expect($r['created'])->toBe(1)->and($r['updated'])->toBe(1)->and(array_column($r['data'], 'status', 'email'))->toBe(['old@example.com' => 'updated', 'new@example.com' => 'created'])
        ->and(Contact::where('email', 'new@example.com')->value('first_name'))->toBe('Newer')
        ->and(Contact::where('email', 'old@example.com')->first()->fields)->toBe(['total_spent' => 40]);
    $this->flushHeaders()->withHeaders($h)->postJson('/v1/contacts/batch', array_fill(0, 501, ['email' => 'x@example.com']))->assertStatus(422);
    $this->flushHeaders()->withHeaders($h)->postJson('/v1/contacts/batch', ['email' => 'x@example.com'])->assertStatus(422)->assertJsonPath('error.message', 'Send a JSON array of contacts.');
});
