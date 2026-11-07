<?php

use App\Models\Contact;
use App\Models\ContactList;
use App\Models\FormSubmission;
use App\Models\JourneyRun;
use App\Services\Journeys\JourneyEvents;
use App\Services\Ses\DnsLookup;
use Tests\Support\FakeAws;
use Tests\Support\FakeDns;

beforeEach(function () {
    FakeAws::install();
    $this->app->instance(DnsLookup::class, new FakeDns);
    [$this->ws] = sesWorkspace($this);
    sesConnect($this, $this->ws)->assertOk();
    $this->list = ContactList::create(['workspace_id' => $this->ws->id, 'name' => 'Leads']);
    JourneyEvents::flush();
});

/** A lead form with every kind of field, over two steps, as a popup. */
function builderForm($test, array $over = []): array
{
    return $test->postJson("/v1/workspaces/{$test->ws->id}/forms", $over + ['publish' => true, 'name' => 'Lead form', 'heading' => 'Get a quote', 'list_id' => $test->list->id, 'double_opt_in' => false,
        'fields' => [
            ['key' => 'first_name', 'label' => 'First name', 'width' => 'half', 'required' => true],
            ['key' => 'last_name', 'label' => 'Last name', 'width' => 'half'],
            ['key' => 'company_size', 'type' => 'dropdown', 'label' => 'Company size', 'options' => ['1-10', '11-50', '51+', '1-10'], 'required' => true],
            ['key' => 'interests', 'type' => 'checkboxes', 'label' => 'Interested in', 'options' => ['Email', 'SMS', 'Journeys']],
            ['key' => 'budget', 'type' => 'number', 'label' => 'Monthly budget', 'step' => 2],
            ['key' => 'phone', 'type' => 'phone', 'label' => 'Phone', 'step' => 2],
            ['key' => 'start_date', 'type' => 'date', 'label' => 'Start date', 'step' => 2],
            ['key' => 'notes', 'type' => 'textarea', 'label' => 'Anything else?', 'step' => 2],
            ['key' => 'lead_source', 'type' => 'hidden', 'label' => 'Source', 'param' => 'utm_source', 'value' => 'direct'],
            ['key' => 'campaign', 'type' => 'hidden', 'label' => 'Campaign', 'value' => 'spring-sale'],
            ['key' => 'marketing_ok', 'type' => 'consent', 'label' => 'I agree to receive marketing emails from Acme.', 'step' => 2],
        ],
        'design' => ['accent' => '#0f766e', 'font' => 'Poppins', 'radius' => 40, 'layout' => 'image_left', 'image' => 'https://cdn.acme.com/hero.jpg', 'steps' => ['About you', 'Your project'], 'evil' => 'x'],
        'display' => ['mode' => 'popup', 'delay' => 8, 'scroll' => 150, 'exit_intent' => true, 'frequency' => 'days', 'days' => 14, 'position' => 'top']])->assertCreated()->json('form');
}

it('saves typed fields, a design and display rules, normalised', function () {
    $f = builderForm($this);
    expect(array_column($f['fields'], 'type'))->toBe(['text', 'text', 'dropdown', 'checkboxes', 'number', 'phone', 'date', 'textarea', 'hidden', 'hidden', 'consent'])
        ->and($f['fields'][2]['options'])->toBe(['1-10', '11-50', '51+'])
        ->and($f['fields'][0])->toMatchArray(['width' => 'half', 'step' => 1, 'required' => true])
        ->and($f['fields'][10])->toMatchArray(['required' => true, 'step' => 2])
        ->and($f['design'])->toMatchArray(['accent' => '#0F766E', 'font' => 'Poppins', 'radius' => 28, 'layout' => 'image_left', 'steps' => ['About you', 'Your project']])
        ->and($f['design'])->not->toHaveKey('evil')
        ->and($f['display'])->toBe(['mode' => 'popup', 'delay' => 8, 'scroll' => 100, 'exit_intent' => true, 'frequency' => 'days', 'days' => 14, 'position' => 'right']);

    // The public form never shows a hidden field's fixed value.
    $pub = $this->getJson("/v1/f/{$f['key']}")->assertOk()->json('form');
    expect($pub['fields'][9])->toBe(['key' => 'campaign', 'type' => 'hidden', 'label' => 'Campaign', 'required' => false, 'placeholder' => null, 'width' => 'full', 'step' => 1, 'param' => null])
        ->and($pub['display']['mode'])->toBe('popup')->and($pub['design']['font'])->toBe('Poppins');

    // Bad settings are refused with a reason.
    $bad = fn (array $fields) => $this->patchJson("/v1/workspaces/{$this->ws->id}/forms/{$f['id']}", ['fields' => $fields])->assertStatus(422)->json('error.message');
    expect($bad([['key' => 'plan', 'type' => 'dropdown', 'label' => 'Plan']]))->toBe('“Plan” needs 1 to 20 choices.')
        ->and($bad([['key' => 'first_name', 'type' => 'number']]))->toBe('First and last name are text fields.')
        ->and($bad([['key' => 'ref', 'type' => 'hidden', 'label' => 'Ref']]))->toContain('needs a link parameter')
        ->and($bad([['key' => 'a', 'type' => 'magic']]))->toBe('Field 1 has an unknown type.');
    $this->patchJson("/v1/workspaces/{$this->ws->id}/forms/{$f['id']}", ['design' => ['image' => 'http://insecure.example/x.jpg']])->assertStatus(422);
    $this->patchJson("/v1/workspaces/{$this->ws->id}/forms/{$f['id']}", ['design' => ['layout' => 'image_top']])->assertOk()->assertJsonPath('form.design.layout', 'card'); // no image: a card
});

it('checks each answer by its type, stores them on the contact and passes them to journeys', function () {
    $f = builderForm($this);
    $jid = $this->postJson("/v1/workspaces/{$this->ws->id}/journeys", ['name' => 'Leads'])->json('journey.id');
    $this->patchJson("/v1/workspaces/{$this->ws->id}/journeys/$jid", ['graph' => ['nodes' => [['id' => 't1', 'type' => 'trigger', 'config' => ['trigger' => 'form_submitted', 'form_id' => $f['id']]],
        ['id' => 'k', 'type' => 'tag', 'config' => ['tag' => 'size-{{ trigger.properties.answers.company_size }}']]], 'edges' => [['from' => 't1', 'to' => 'k']]]])->assertOk();
    $this->postJson("/v1/workspaces/{$this->ws->id}/journeys/$jid/publish")->assertOk();
    JourneyEvents::flush();
    $post = fn (array $body) => $this->postJson("/v1/f/{$f['key']}", $body);

    $errors = $post(['email' => 'ada@example.com', 'first_name' => 'Ada', 'company_size' => '1000+', 'budget' => 'lots', 'phone' => 'call me', 'start_date' => '2026-02-30'])->assertStatus(422)->json('error.fields');
    expect($errors)->toBe(['company_size' => 'Choose one of the options for company size.', 'budget' => 'Enter a number for monthly budget.', 'phone' => 'Enter a valid phone number.',
        'start_date' => 'Enter a date for start date.', 'marketing_ok' => 'Please tick the box to agree.']);

    $post(['email' => 'Ada@Example.com', 'first_name' => 'Ada', 'company_size' => '11-50', 'interests' => ['SMS', 'Email', 'Fax'], 'budget' => '2,500', 'phone' => '+44 20 7946 0958',
        'start_date' => '2026-12-01', 'notes' => "Line one\nLine two", 'lead_source' => 'newsletter', 'campaign' => 'hacked', 'marketing_ok' => 'on'])->assertOk()->assertJson(['status' => 'added']);
    $c = Contact::where('workspace_id', $this->ws->id)->where('email', 'ada@example.com')->firstOrFail();
    expect($c->first_name)->toBe('Ada')
        ->and($c->fields)->toMatchArray(['company_size' => '11-50', 'interests' => 'Email, SMS', 'budget' => '2500', 'phone' => '+44 20 7946 0958', 'start_date' => '2026-12-01',
            'notes' => "Line one\nLine two", 'lead_source' => 'newsletter', 'campaign' => 'spring-sale', 'marketing_ok' => 'yes'])
        ->and($c->fields)->not->toHaveKey('_consent');
    $sub = FormSubmission::where('email', 'ada@example.com')->firstOrFail();
    expect($sub->data['_consent']['text'])->toBe('I agree to receive marketing emails from Acme.');
    expect(JourneyRun::where('contact_id', $c->id)->firstOrFail()->context['properties']['answers'])->toMatchArray(['company_size' => '11-50', 'budget' => '2500'])
        ->and(DB::table('contact_tags')->where('contact_id', $c->id)->pluck('tag')->all())->toBe(['size-11-50']);

    // Without the link parameter, a hidden field falls back to its value.
    $post(['email' => 'bo@example.com', 'first_name' => 'Bo', 'company_size' => '51+', 'marketing_ok' => '1'])->assertOk();
    expect(Contact::where('email', 'bo@example.com')->firstOrFail()->fields)->toMatchArray(['lead_source' => 'direct', 'campaign' => 'spring-sale']);
});
