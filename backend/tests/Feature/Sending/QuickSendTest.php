<?php

use App\Models\EmailTemplate;
use App\Models\Message;
use App\Services\Contacts\ContactService;
use App\Services\Ses\DnsLookup;
use Tests\Support\FakeAws;
use Tests\Support\FakeDns;

beforeEach(function () {
    FakeAws::install();
    $this->app->instance(DnsLookup::class, new FakeDns);
    [$this->ws] = sesWorkspace($this, ['viewer']);
    sesConnect($this, $this->ws)->assertOk();
    $this->doc = json_decode(file_get_contents(base_path('tests/fixtures/design.json')), true);
    $this->url = "/v1/workspaces/{$this->ws->id}/emails/quick";
});

/** The design with its first text block's words replaced. */
function editedDoc(array $doc, string $html): array
{
    foreach ($doc['rows'] as $r => $row) {
        foreach ($row['cells'] as $c => $cell) {
            foreach ($cell as $b => $block) {
                if ($block['type'] === 'text') {
                    $doc['rows'][$r]['cells'][$c][$b]['props']['html'] = $html;

                    return $doc;
                }
            }
        }
    }

    return $doc;
}

it('sends a saved template, edited for this one person, without changing the template', function () {
    $id = $this->postJson("/v1/workspaces/{$this->ws->id}/templates", ['name' => 'Update', 'subject' => 'News for you, {{ first_name | there }}', 'doc' => $this->doc])->json('template.id');
    $before = EmailTemplate::find($id)->html;
    app(ContactService::class)->upsert($this->ws, ['email' => 'amara@example.com', 'first_name' => 'Amara'], 'import');

    $this->postJson($this->url, ['from' => 'Acme <news@acme.com>', 'to' => 'amara@example.com', 'template' => $id,
        'doc' => editedDoc($this->doc, '<p>Just for you, {{ first_name }}: your order ships {{ ship_day | soon }}.</p>'), 'variables' => ['ship_day' => 'Friday', 'first_name' => '']])
        ->assertStatus(202)->assertJsonPath('to', 'amara@example.com');

    $m = Message::sole();
    expect($m->subject)->toBe('News for you, Amara')                       // the template's subject, merged from the contact
        ->and($m->html)->toContain('Just for you, Amara: your order ships Friday.') // a blank variable keeps the contact's value
        ->and($m->source)->toBe('quick')->and($m->type)->toBe('transactional')->and($m->from_name)->toBe('Acme')
        ->and(EmailTemplate::find($id)->html)->toBe($before)
        ->and(EmailTemplate::find($id)->last_used_at)->not->toBeNull();
});

it('sends a gallery design with no saved template, and a plain message', function () {
    $this->postJson($this->url, ['from' => 'news@acme.com', 'to' => 'new@example.com', 'subject' => 'Hello', 'doc' => $this->doc])->assertStatus(202);
    expect(Message::sole()->html)->toStartWith('<!doctype html>');

    $this->postJson($this->url, ['from' => 'news@acme.com', 'to' => 'new@example.com', 'subject' => 'Quick note', 'message' => "Hi {{ first_name | there }},\n\nSee you <b>tomorrow</b>.\nBest", 'marketing' => true])->assertStatus(202);
    $m = Message::orderByDesc('id')->first();
    expect($m->html)->toContain('<p style="margin:0 0 14px">Hi there,</p>')->toContain("See you &lt;b&gt;tomorrow&lt;/b&gt;.<br>\nBest")
        ->and($m->text)->toContain('Hi there,')->and($m->type)->toBe('marketing')->and($m->unsubscribe_token)->not->toBeNull();
});

it('explains what is missing, sends to one person only, and keeps viewers out', function () {
    $this->postJson($this->url, ['from' => 'news@acme.com', 'to' => 'a@example.com', 'subject' => 'Hi'])->assertStatus(422)->assertJsonPath('error.fields.message', 'Write a message.');
    $this->postJson($this->url, ['from' => 'news@acme.com', 'to' => 'a@example.com', 'message' => 'Hi'])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['subject']]]);
    $this->postJson($this->url, ['from' => 'news@acme.com', 'to' => ['a@example.com', 'b@example.com'], 'subject' => 'Hi', 'message' => 'Hi'])->assertStatus(422)->assertJsonPath('error.fields.to', 'One address only.');
    $this->postJson($this->url, ['from' => 'news@acme.com', 'to' => 'a@example.com', 'template' => str_repeat('A', 26)])->assertStatus(422)->assertJsonPath('error.fields.template', 'Unknown template.');
    $this->postJson($this->url, ['from' => 'news@gmail.com', 'to' => 'a@example.com', 'subject' => 'Hi', 'message' => 'Hi'])->assertStatus(422);

    switchTo($this, 'viewer@example.com');
    $this->postJson($this->url, ['from' => 'news@acme.com', 'to' => 'a@example.com', 'subject' => 'Hi', 'message' => 'Hi'])->assertForbidden();
    expect(Message::count())->toBe(0);
});
