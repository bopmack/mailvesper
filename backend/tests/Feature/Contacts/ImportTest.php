<?php

use App\Models\Contact;
use App\Models\ContactImport;
use App\Models\Suppression;
use App\Services\Contacts\AddressCheck;
use App\Services\Contacts\ContactService;
use App\Services\Ses\DnsLookup;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeAws;
use Tests\Support\FakeDns;

beforeEach(function () {
    Storage::fake('local');
    FakeAws::install();
    $this->app->instance(DnsLookup::class, new FakeDns);
    AddressCheck::$resolver = fn (string $d) => ! str_ends_with($d, '.invalid');
    [$this->ws] = sesWorkspace($this);
});

afterEach(fn () => AddressCheck::$resolver = null);

function upload($test, string $csv, string $name = 'customers.csv')
{
    return $test->post("/v1/workspaces/{$test->ws->id}/imports", ['file' => UploadedFile::fake()->createWithContent($name, $csv)], ['Accept' => 'application/json']);
}

function startImport($test, string $id, array $mapping, array $options = [])
{
    return $test->postJson("/v1/workspaces/{$test->ws->id}/imports/$id/start", ['mapping' => $mapping, 'options' => $options + ['consent' => true]]);
}

it('suggests a column mapping from the header', function () {
    $r = upload($this, "\xEF\xBB\xBFE-mail Address;First Name;Surname;Plan;Tags;Signup Date;123\namara@example.com;Amara;Okafor;Growth;vip;2026-01-02;x\n")->assertCreated();
    expect($r->json('import.mapping'))->toBe(['email', 'first_name', 'last_name', 'field:plan', 'tags', 'field:signup_date', 'skip'])
        ->and($r->json('import.total_rows'))->toBe(1)->and($r->json('import.sample.0.1'))->toBe('Amara');
    // No header names the email column: find it from the values.
    expect(upload($this, "who,contact\nAmara,amara@example.com\nBo,bo@example.com\n")->json('import.mapping'))->toBe(['field:who', 'email']); // unknown columns become custom fields
});

it('imports with checks, merges duplicates and never re-subscribes the suppressed', function () {
    app(ContactService::class)->upsert($this->ws, ['email' => 'old@example.com', 'first_name' => 'Old', 'fields' => ['plan' => 'Free', 'keep' => 'yes']], 'manual');
    Suppression::add($this->ws->id, 'gone@example.com', 'hard_bounce');
    $csv = implode("\n", [
        'email,name,plan,tags,unsubscribed',
        'Amara@Example.com,Amara Okafor,Growth,vip;beta,',
        'amara@example.com,,Pro,,',                  // duplicate: later non-empty values win
        'old@example.com,,Starter,,',                 // existing: updated, other fields kept
        'not-an-email,,,,',
        'bo@gmial.com,Bo,,,',                         // typo
        'info@acme.com,,,,',                          // role address
        'ceo@nowhere.invalid,,,,',                    // no mail server
        'gone@example.com,Gone,,,',                   // suppressed: imported but stays bounced
        'quit@example.com,Quit,,,yes',                // unsubscribed column
    ])."\n";
    $id = upload($this, $csv)->json('import.id');
    startImport($this, $id, ['email', 'full_name', 'field:plan', 'tags', 'unsubscribed'], ['consent' => false])->assertStatus(422)->assertJsonPath('error.code', 'consent_required');
    startImport($this, $id, ['email', 'full_name', 'field:plan', 'tags', 'unsubscribed'], ['new_list' => 'October import', 'tags' => ['import-oct']])->assertOk();

    $i = ContactImport::find($id);
    expect($i->status)->toBe('done')->and($i->processed_rows)->toBe(9)
        ->and($i->stats)->toMatchArray(['created' => 3, 'updated' => 1, 'duplicates' => 1, 'invalid' => 1, 'typo' => 1, 'role' => 1, 'no_mail_server' => 1, 'suppressed' => 1, 'marked_unsubscribed' => 1]);
    $amara = Contact::where('email', 'amara@example.com')->first();
    expect($amara)->first_name->toBe('Amara')->last_name->toBe('Okafor')->fields->toBe(['plan' => 'Pro'])
        ->and(DB::table('contact_tags')->where('contact_id', $amara->id)->pluck('tag')->sort()->values()->all())->toBe(['beta', 'import-oct', 'vip'])
        ->and(Contact::where('email', 'old@example.com')->first()->fields)->toBe(['plan' => 'Starter', 'keep' => 'yes'])
        ->and(Contact::where('email', 'gone@example.com')->value('status'))->toBe('bounced')
        ->and(Contact::where('email', 'quit@example.com')->value('status'))->toBe('unsubscribed')
        ->and(DB::table('contact_list')->count())->toBe(4) // amara, old, gone, quit
        ->and(DB::table('contact_fields')->where('key', 'plan')->exists())->toBeTrue();

    $skipped = $this->get("/v1/workspaces/{$this->ws->id}/imports/$id/skipped")->assertOk()->streamedContent();
    expect($skipped)->toContain('6,bo@gmial.com,"Looks like a typo",bo@gmail.com')->toContain('Role address')->toContain('can’t receive email');
});

it('processes big files in chunks and resumes exactly', function () {
    $rows = ['email,first_name,note'];
    foreach (range(1, 2500) as $n) {
        $rows[] = "person$n@example.com,P$n,".($n === 1500 ? '"multi
line note"' : 'ok');
    }
    $rows[] = 'person1@example.com,P1 again,late'; // repeat of row 1, in the third chunk
    $id = upload($this, implode("\n", $rows)."\n")->json('import.id');
    startImport($this, $id, ['email', 'first_name', 'field:note'])->assertOk();
    $i = ContactImport::find($id);
    expect($i->status)->toBe('done')->and($i->processed_rows)->toBe(2501)->and($i->stats['created'])->toBe(2500)
        ->and($i->stats['updated'])->toBe(0)->and($i->stats['duplicates'])->toBe(1)->and(Contact::where('email', 'person1@example.com')->value('first_name'))->toBe('P1 again')
        ->and(Contact::count())->toBe(2500)->and(Contact::where('email', 'person1500@example.com')->first()->fields['note'])->toBe("multi\nline note")
        ->and(Contact::where('email', 'person2500@example.com')->value('first_name'))->toBe('P2500')
        ->and(DB::table('import_seen')->count())->toBe(0);
});

it('refuses files that are too big or not CSV, and keeps updating optional', function () {
    upload($this, "a,b\n", 'x.exe')->assertStatus(422);
    $rows = "email\n".implode("\n", array_map(fn ($n) => "u$n@example.com", range(1, 100001)))."\n";
    upload($this, $rows)->assertStatus(422)->assertJsonPath('error.code', 'too_many_rows');

    app(ContactService::class)->upsert($this->ws, ['email' => 'a@example.com', 'first_name' => 'Ann'], 'manual');
    $id = upload($this, "email,first_name\na@example.com,Changed\n")->json('import.id');
    startImport($this, $id, ['email', 'first_name'], ['update_existing' => false])->assertOk();
    expect(Contact::where('email', 'a@example.com')->value('first_name'))->toBe('Ann')->and(ContactImport::find($id)->stats['unchanged'])->toBe(1);
});
