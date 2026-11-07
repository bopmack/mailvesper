<?php

namespace App\Services\Contacts;

use App\Exceptions\ApiException;
use App\Jobs\ProcessImport;
use App\Models\ContactImport;
use App\Models\ContactList;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Platform\Brand;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** CSV import: upload → suggested column mapping → background processing in chunks. */
class Importer
{
    public const MAX_ROWS = 100000;

    public const MAX_BYTES = 25 * 1024 * 1024;

    public const TARGETS = ['email', 'first_name', 'last_name', 'full_name', 'tags', 'unsubscribed', 'skip'];

    public function create(Workspace $ws, User $user, UploadedFile $file): ContactImport
    {
        if ($file->getSize() > self::MAX_BYTES) {
            throw new ApiException('file_too_large', 'Files can be up to 25 MB. Split bigger files.', 422, ['file' => 'Up to 25 MB.']);
        }
        $import = new ContactImport(['workspace_id' => $ws->id, 'user_id' => $user->id, 'status' => 'uploaded', 'filename' => mb_substr($file->getClientOriginalName(), 0, 255)]);
        $import->id = (string) Str::ulid();
        $import->path = 'imports/'.$import->id.'.csv';
        Storage::disk('local')->putFileAs('imports', $file, $import->id.'.csv');

        $reader = new CsvReader(Storage::disk('local')->path($import->path));
        $header = $reader->header();
        if (count(array_filter($header)) === 0) {
            throw new ApiException('empty_file', 'The file is empty or isn’t a CSV.', 422, ['file' => 'The file is empty or isn’t a CSV.']);
        }
        $sample = [];
        $rows = 0;
        while (($r = $reader->row()) !== null) {
            if ($rows < 5) {
                $sample[] = $r;
            }
            if (++$rows > self::MAX_ROWS) {
                Storage::disk('local')->delete($import->path);
                throw new ApiException('too_many_rows', 'Import up to 100,000 rows at a time. Split the file and import each part.', 422, ['file' => 'Up to 100,000 rows.']);
            }
        }
        $import->columns = $header;
        $import->total_rows = $rows;
        $import->mapping = $this->suggest($header, $sample);
        $import->options = ['sample' => $sample];
        $import->save();

        return $import;
    }

    /**
     * Guesses what each column is from its header (and samples, for the email column).
     *
     * @param  list<string>  $header
     * @param  list<list<string>>  $sample
     * @return array<int, string> column index → target ("email", "field:plan", "skip", …)
     */
    public function suggest(array $header, array $sample): array
    {
        $map = [];
        $used = [];
        foreach ($header as $i => $h) {
            $n = strtolower(preg_replace('/[^a-z0-9]+/i', ' ', $h) ?? '');
            $n = trim($n);
            $target = match (true) {
                in_array($n, ['email', 'e mail', 'email address', 'mail', 'e mail address', 'emailaddress'], true) => 'email',
                in_array($n, ['first name', 'firstname', 'given name', 'fname', 'first'], true) => 'first_name',
                in_array($n, ['last name', 'lastname', 'surname', 'family name', 'lname', 'last'], true) => 'last_name',
                in_array($n, ['name', 'full name', 'fullname', 'contact name'], true) => 'full_name',
                in_array($n, ['tags', 'tag', 'labels', 'label'], true) => 'tags',
                in_array($n, ['unsubscribed', 'opted out', 'unsubscribe', 'optout', 'opt out'], true) => 'unsubscribed',
                default => null,
            };
            if (! $target) {
                $key = trim((string) preg_replace('/_+/', '_', str_replace(' ', '_', $n)), '_');
                $key = preg_match('/^[a-z]/', $key) ? substr($key, 0, 40) : '';
                $target = ContactService::validKey($key) ? 'field:'.$key : 'skip';
            }
            if (in_array($target, ['email', 'first_name', 'last_name', 'full_name', 'tags', 'unsubscribed'], true) && isset($used[$target])) {
                $target = 'skip';
            }
            $used[$target] = true;
            $map[$i] = $target;
        }
        if (! in_array('email', $map, true)) { // no email header: pick the column whose samples look like addresses
            foreach ($header as $i => $_) {
                $hits = count(array_filter($sample, fn ($r) => filter_var(trim($r[$i] ?? ''), FILTER_VALIDATE_EMAIL)));
                if ($sample && $hits >= max(1, intdiv(count($sample), 2))) {
                    $map[$i] = 'email';
                    break;
                }
            }
        }

        return $map;
    }

    /**
     * @param  array<int|string, string>  $mapping
     * @param  array<string, mixed>  $options  consent, list_id|new_list, tags[], update_existing, include_roles
     */
    public function start(ContactImport $import, Workspace $ws, array $mapping, array $options): ContactImport
    {
        if ($import->status !== 'uploaded') {
            throw new ApiException('import_started', 'This import has already started.', 422);
        }
        if (($options['consent'] ?? false) !== true) {
            throw new ApiException('consent_required', 'Confirm that these people agreed to get email from you.', 422, ['consent' => 'Required.']);
        }
        $clean = [];
        foreach ($import->columns ?? [] as $i => $_) {
            $t = (string) ($mapping[$i] ?? $mapping[(string) $i] ?? 'skip');
            if (! in_array($t, self::TARGETS, true) && ! (str_starts_with($t, 'field:') && ContactService::validKey(substr($t, 6)))) {
                throw new ApiException('validation_failed', 'Column '.($i + 1).' has an unknown destination.', 422);
            }
            $clean[$i] = $t;
        }
        $counts = array_count_values($clean);
        if (($counts['email'] ?? 0) !== 1) {
            throw new ApiException('validation_failed', 'Choose exactly one column as Email.', 422, ['mapping' => 'Choose exactly one column as Email.']);
        }
        foreach (['first_name', 'last_name', 'full_name', 'tags', 'unsubscribed'] as $once) {
            if (($counts[$once] ?? 0) > 1) {
                throw new ApiException('validation_failed', 'Each '.Brand::name().' field can be used once.', 422, ['mapping' => 'Each '.Brand::name().' field can be used once.']);
            }
        }
        $tags = array_values(array_filter(array_map('strval', (array) ($options['tags'] ?? [])), fn ($t) => ContactService::validTag($t)));
        $listId = null;
        if (! empty($options['list_id'])) {
            $listId = ContactList::where('workspace_id', $ws->id)->where('id', $options['list_id'])->value('id') ?? throw new ApiException('validation_failed', 'That list doesn’t exist.', 422);
        } elseif (! empty($options['new_list']) && is_string($options['new_list'])) {
            $listId = ContactList::firstOrCreate(['workspace_id' => $ws->id, 'name' => mb_substr(trim($options['new_list']), 0, 100)])->id;
        }
        // Make sure every mapped custom field is defined, labelled from its column header.
        foreach ($clean as $i => $t) {
            if (str_starts_with($t, 'field:')) {
                DB::table('contact_fields')->insertOrIgnore(['workspace_id' => $ws->id, 'key' => substr($t, 6), 'label' => mb_substr(($import->columns ?? [])[$i] ?: substr($t, 6), 0, 80), 'type' => 'text', 'created_at' => now()]);
            }
        }
        $import->forceFill([
            'mapping' => $clean, 'status' => 'processing', 'started_at' => now(), 'processed_rows' => 0,
            'options' => ['list_id' => $listId, 'tags' => $tags, 'update_existing' => (bool) ($options['update_existing'] ?? true), 'include_roles' => (bool) ($options['include_roles'] ?? false), 'offset' => 0],
            'stats' => array_fill_keys(ProcessImport::STATS, 0),
        ])->save();
        app()->runningInConsole() ? ProcessImport::dispatch($import->id) : ProcessImport::dispatchAfterResponse($import->id);

        return $import;
    }

    public static function rejectedPath(ContactImport $import): string
    {
        return 'imports/'.$import->id.'-skipped.csv';
    }
}
