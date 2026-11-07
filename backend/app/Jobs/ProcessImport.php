<?php

namespace App\Jobs;

use App\Models\Contact;
use App\Models\ContactImport;
use App\Models\Suppression;
use App\Models\Workspace;
use App\Services\Contacts\AddressCheck;
use App\Services\Contacts\ContactService;
use App\Services\Contacts\CsvReader;
use App\Services\Contacts\Importer;
use App\Support\Edition;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Imports one chunk of rows, then queues the next chunk. Never re-subscribes an address on the suppression
 * list: those contacts are created with their suppressed status and are never emailed.
 */
class ProcessImport implements ShouldQueue
{
    use Queueable;

    public const CHUNK = 1000;

    public const STATS = ['created', 'updated', 'unchanged', 'duplicates', 'invalid', 'typo', 'role', 'no_mail_server', 'suppressed', 'marked_unsubscribed', 'over_limit'];

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public string $importId) {}

    public function handle(AddressCheck $check): void
    {
        $import = ContactImport::find($this->importId);
        if (! $import || $import->status !== 'processing') {
            return;
        }
        try {
            $done = $this->chunk($import, $check);
        } catch (Throwable $e) {
            report($e);
            $import->forceFill(['status' => 'failed', 'error' => 'Something went wrong while importing. Rows imported so far were kept.', 'finished_at' => now()])->save();
            DB::table('import_seen')->where('import_id', $import->id)->delete();

            return;
        }
        if ($done) {
            $import->forceFill(['status' => 'done', 'finished_at' => now()])->save();
            DB::table('import_seen')->where('import_id', $import->id)->delete();
        } else {
            self::dispatch($import->id); // next chunk
        }
    }

    /** @return bool true when the file is finished */
    private function chunk(ContactImport $import, AddressCheck $check): bool
    {
        $opt = $import->options ?? [];
        $map = $import->mapping ?? [];
        $stats = $import->stats ?? array_fill_keys(self::STATS, 0);
        $reader = new CsvReader(Storage::disk('local')->path($import->path));
        if ((int) ($opt['offset'] ?? 0) === 0) {
            $reader->header();
        } else {
            $reader->seek((int) $opt['offset']);
        }
        $emailCol = (int) array_search('email', $map, true);
        $rowNo = $import->processed_rows + 1; // data row number (header excluded)
        $valid = [];
        $skipped = [];
        $finished = false;

        for ($n = 0; $n < self::CHUNK; $n++) {
            $r = $reader->row();
            if ($r === null) {
                $finished = true;
                break;
            }
            $email = ContactService::normaliseEmail($r[$emailCol] ?? '');
            $problem = $check->problem($email, (bool) ($opt['include_roles'] ?? false));
            if ($problem) {
                $stats[$problem[0]]++;
                $skipped[] = [$rowNo, $r[$emailCol] ?? '', $problem[0], $problem[1]];
            } else {
                $valid[$email] = $this->rowData($r, $map, $valid[$email] ?? null);
            }
            $rowNo++;
        }
        $offset = $reader->offset(); // resume point, before looking ahead
        if (! $finished && $reader->row() === null) { // look ahead: was that the last row?
            $finished = true;
        }

        $this->save($import, $valid, $stats, $opt);
        if ($skipped) {
            $this->appendSkipped($import, $skipped);
        }
        $opt['offset'] = $offset;
        $import->forceFill(['stats' => $stats, 'options' => $opt, 'processed_rows' => $rowNo - 1])->save();

        return $finished;
    }

    /**
     * @param  list<string>  $r
     * @param  array<int, string>  $map
     * @param  array<string, mixed>|null  $prev  same address earlier in this chunk (later non-empty values win)
     * @return array<string, mixed>
     */
    private function rowData(array $r, array $map, ?array $prev): array
    {
        $d = $prev ?? ['fields' => [], 'tags' => [], 'dup' => false];
        if ($prev) {
            $d['dup'] = true;
        }
        foreach ($map as $i => $t) {
            $v = trim($r[$i] ?? '');
            if ($v === '' || $t === 'skip' || $t === 'email') {
                continue;
            }
            match (true) {
                $t === 'first_name', $t === 'last_name' => $d[$t] = mb_substr($v, 0, 100),
                $t === 'full_name' => [$d['first_name'], $d['last_name']] = array_pad(array_map(fn ($x) => mb_substr($x, 0, 100), preg_split('/\s+/', $v, 2) ?: []), 2, null),
                $t === 'tags' => $d['tags'] = array_merge($d['tags'], array_filter(array_map('trim', preg_split('/[;,|]/', $v) ?: []), fn ($x) => ContactService::validTag($x))),
                $t === 'unsubscribed' => $d['unsubscribed'] = in_array(strtolower($v), ['1', 'yes', 'y', 'true', 'unsubscribed', 'opted out', 'optout'], true),
                default => $d['fields'][substr($t, 6)] = mb_substr($v, 0, 1000),
            };
        }

        return $d;
    }

    /**
     * @param  array<string, array<string, mixed>>  $valid
     * @param  array<string, int>  $stats
     * @param  array<string, mixed>  $opt
     */
    private function save(ContactImport $import, array $valid, array &$stats, array $opt): void
    {
        if (! $valid) {
            return;
        }
        $ws = $import->workspace_id;
        $emails = array_keys($valid);

        // Duplicates within the file, across chunks.
        $hashes = array_combine($emails, array_map(fn ($e) => sha1($e), $emails));
        $seenBefore = array_flip(DB::table('import_seen')->where('import_id', $import->id)->whereIn('email_hash', array_values($hashes))->pluck('email_hash')->all());
        DB::table('import_seen')->insertOrIgnore(array_map(fn ($h) => ['import_id' => $import->id, 'email_hash' => $h], array_values($hashes)));
        $stats['duplicates'] += count($seenBefore) + count(array_filter($valid, fn ($d) => $d['dup']));

        $existing = Contact::where('workspace_id', $ws)->whereIn('email', $emails)->get()->keyBy('email');
        $suppressed = DB::table('suppressions')->where('workspace_id', $ws)->whereIn('email', $emails)->pluck('reason', 'email');
        $rows = [];
        $room = app(Edition::class)->room(Workspace::findOrFail($ws), 'contacts'); // plan limits (Pro SaaS); null = none
        foreach ($valid as $email => $d) {
            $c = $existing[$email] ?? null;
            if ($c && ! ($opt['update_existing'] ?? true)) {
                $stats['unchanged']++;

                continue;
            }
            if (! $c && $room !== null) {
                if ($room <= 0) {
                    $stats['over_limit'] = ($stats['over_limit'] ?? 0) + 1;

                    continue;
                }
                $room--;
            }
            $fields = array_merge($c ? ($c->fields ?? []) : [], $d['fields']);
            $status = isset($suppressed[$email]) ? Contact::FROM_SUPPRESSION[$suppressed[$email]] : 'subscribed';
            if (isset($suppressed[$email])) {
                $stats['suppressed']++;
            }
            $rows[] = [
                'id' => $c->id ?? strtolower((string) Str::ulid()), 'workspace_id' => $ws, 'email' => $email,
                'first_name' => $d['first_name'] ?? $c?->first_name, 'last_name' => $d['last_name'] ?? $c?->last_name,
                'fields' => $fields ? json_encode($fields, JSON_UNESCAPED_UNICODE) : null, 'status' => $status,
                'source' => $c ? $c->source : 'import', 'import_id' => $c ? $c->import_id : $import->id, 'created_at' => $c ? ($c->created_at ?? now()) : now(), 'updated_at' => now(),
            ];
            if (! isset($seenBefore[$hashes[$email]])) { // a repeat from an earlier chunk was already counted
                $c ? $stats['updated']++ : $stats['created']++;
            }
        }
        DB::transaction(function () use ($rows, $ws, $emails, $valid, $opt, &$stats) {
            foreach (array_chunk($rows, 500) as $part) {
                DB::table('contacts')->upsert($part, ['workspace_id', 'email'], ['first_name', 'last_name', 'fields', 'status', 'updated_at']);
            }
            $ids = DB::table('contacts')->where('workspace_id', $ws)->whereIn('email', $emails)->pluck('id', 'email');
            $tagRows = [];
            $listRows = [];
            foreach ($valid as $email => $d) {
                if (! isset($ids[$email])) {
                    continue;
                }
                foreach (array_unique(array_merge($d['tags'], $opt['tags'] ?? [])) as $t) {
                    $tagRows[] = ['workspace_id' => $ws, 'contact_id' => $ids[$email], 'tag' => mb_strtolower($t)];
                }
                if (! empty($opt['list_id'])) {
                    $listRows[] = ['list_id' => $opt['list_id'], 'contact_id' => $ids[$email], 'created_at' => now()];
                }
                if (($d['unsubscribed'] ?? false) === true) {
                    Suppression::add($ws, $email, 'unsubscribe', 'Unsubscribed in an imported file');
                    $stats['marked_unsubscribed']++;
                }
            }
            foreach (array_chunk($tagRows, 1000) as $part) {
                DB::table('contact_tags')->insertOrIgnore($part);
            }
            foreach (array_chunk($listRows, 1000) as $part) {
                DB::table('contact_list')->insertOrIgnore($part);
            }
        });
    }

    /** @param list<array{0: int, 1: string, 2: string, 3: string|null}> $rows */
    private function appendSkipped(ContactImport $import, array $rows): void
    {
        $path = Storage::disk('local')->path(Importer::rejectedPath($import));
        $new = ! file_exists($path);
        $fh = fopen($path, 'a');
        if (! $fh) {
            return;
        }
        if ($new) {
            fputcsv($fh, ['row', 'email', 'reason', 'suggestion'], ',', '"', '');
        }
        $why = ['invalid' => 'Not a valid address', 'typo' => 'Looks like a typo', 'role' => 'Role address (shared inbox)', 'no_mail_server' => 'Domain can’t receive email'];
        foreach ($rows as [$row, $email, $reason, $suggestion]) {
            fputcsv($fh, [$row + 1, $email, $why[$reason] ?? $reason, $suggestion], ',', '"', '');
        }
        fclose($fh);
    }
}
