<?php

namespace App\Services\Migration\Providers;

use App\Services\Migration\MigrationService;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Twilio SendGrid v3. Suppressions: bounces, spam reports, global and group unsubscribes, invalid emails
 * (blocks are temporary and skipped). Contacts can only be listed in bulk through an export job:
 * POST /marketing/contacts/exports → poll → download the files (gzip or plain, JSON lines, JSON array or CSV).
 * SendGrid contacts carry no status: suppression state comes from the suppression lists.
 */
class SendgridProvider extends BaseProvider
{
    public const NAME = 'SendGrid';

    public const KINDS = ['bounces' => 'bounce', 'spam_reports' => 'complaint', 'unsubscribes' => 'unsubscribe', 'invalid_emails' => 'bounce', 'asm' => 'unsubscribe'];

    public const ROWS = 1000;

    protected function host(): string
    {
        return ($this->credentials['region'] ?? 'us') === 'eu' ? 'api.eu.sendgrid.com' : 'api.sendgrid.com';
    }

    protected function http(): PendingRequest
    {
        return Http::withToken($this->key())->acceptJson()->timeout(60)->connectTimeout(10)->baseUrl('https://'.$this->host());
    }

    public function account(): array
    {
        $r = $this->get('/v3/user/profile');

        return ['name' => (string) (($r['company'] ?? '') ?: trim(($r['first_name'] ?? '').' '.($r['last_name'] ?? '')) ?: 'SendGrid account')];
    }

    public function discover(): array
    {
        $count = null;
        try {
            $count = (int) ($this->get('/v3/marketing/contacts/count')['contact_count'] ?? 0);
        } catch (ProviderError) {
            // The key may lack Marketing access; contacts then arrive empty with a note.
        }

        return ['contacts' => $count, 'suppressions' => null, 'templates' => count((array) ($this->get('/v3/templates', ['generations' => 'dynamic', 'page_size' => 200])['result'] ?? [])),
            'lists' => array_map(fn ($l) => ['ext_id' => (string) $l['id'], 'name' => (string) $l['name'], 'count' => isset($l['contact_count']) ? (int) $l['contact_count'] : null], $this->allLists()),
            'notes' => ['Bounces, spam reports, global unsubscribes, invalid emails and unsubscribe-group opt-outs are imported first. Group opt-outs become full unsubscribes, to be safe.', 'Blocks are temporary rejections and aren’t imported.', 'Contacts come through a SendGrid export, which can take a few minutes to prepare.']];
    }

    public function stages(): array
    {
        return ['suppressions', 'lists', 'contacts', 'templates'];
    }

    public function fetch(string $stage, ?array $cursor, array $options): array
    {
        switch ($stage) {
            case 'suppressions':
                $kinds = array_keys(self::KINDS);
                $k = (int) ($cursor['k'] ?? 0);
                $o = (int) ($cursor['o'] ?? 0);
                if ($kinds[$k] === 'asm') {
                    $rows = (array) $this->get('/v3/asm/suppressions');

                    return ['items' => array_map(fn ($x) => ['email' => (string) ($x['email'] ?? ''), 'reason' => 'unsubscribe', 'detail' => 'group: '.($x['group_name'] ?? '')], $rows), 'cursor' => null];
                }
                $rows = (array) $this->get('/v3/suppression/'.$kinds[$k], ['limit' => 500, 'offset' => $o]);
                $items = array_map(fn ($x) => ['email' => (string) ($x['email'] ?? ''), 'reason' => self::KINDS[$kinds[$k]], 'detail' => $kinds[$k]], $rows);

                return ['items' => $items, 'cursor' => count($rows) === 500 ? ['k' => $k, 'o' => $o + 500] : ['k' => $k + 1]];
            case 'lists':
                return ['items' => array_map(fn ($l) => ['ext_id' => (string) $l['id'], 'name' => (string) $l['name']], $this->allLists()), 'cursor' => null];
            case 'contacts':
                return $this->contacts($cursor ?? [], $options);
            case 'templates':
                $items = [];
                foreach ((array) ($this->get('/v3/templates', ['generations' => 'dynamic', 'page_size' => 200])['result'] ?? []) as $t) {
                    $full = $this->get('/v3/templates/'.$t['id']);
                    $v = collect((array) ($full['versions'] ?? []))->firstWhere('active', 1) ?? ($full['versions'][0] ?? null);
                    if ($v && ! empty($v['html_content'])) {
                        $items[] = ['name' => (string) ($t['name'] ?? 'SendGrid template'), 'subject' => (string) ($v['subject'] ?? ''), 'html' => (string) $v['html_content']];
                    }
                }

                return ['items' => $items, 'cursor' => null];
        }

        return ['items' => [], 'cursor' => null];
    }

    /**
     * @param  array<string, mixed>  $c
     * @param  array<string, mixed>  $options
     * @return array{items: list<array<string, mixed>>, cursor: array<string, mixed>|null, total?: int|null}
     */
    private function contacts(array $c, array $options): array
    {
        if (empty($c['export'])) {
            $body = ['file_type' => 'json'];
            if (! empty($options['list_ids'])) {
                $body['list_ids'] = array_values((array) $options['list_ids']);
            }
            $id = (string) ($this->post('/v3/marketing/contacts/exports', $body)->json('id') ?? '');
            if ($id === '') {
                throw new ProviderError('SendGrid didn’t start the contact export.');
            }

            return ['items' => [], 'cursor' => ['export' => $id]];
        }
        if (empty($c['files'])) {
            $st = $this->get('/v3/marketing/contacts/exports/'.$c['export']);
            if (($st['status'] ?? '') === 'failure') {
                throw new ProviderError('SendGrid couldn’t export the contacts: '.mb_substr((string) ($st['message'] ?? 'unknown error'), 0, 200));
            }
            if (($st['status'] ?? '') !== 'ready') {
                throw new ProviderError('SendGrid is still preparing the contact export.', true, 30);
            }
            $files = [];
            foreach ((array) ($st['urls'] ?? []) as $i => $url) {
                $files[] = $this->download((string) $url, (string) $c['export'], (int) $i);
            }

            return ['items' => [], 'cursor' => ['export' => $c['export'], 'files' => $files, 'f' => 0, 'line' => 0], 'total' => isset($st['contact_count']) ? (int) $st['contact_count'] : null];
        }
        $f = (int) $c['f'];
        $files = (array) $c['files'];
        if (! isset($files[$f])) {
            foreach ($files as $path) {
                Storage::disk('local')->delete((string) $path);
            }

            return ['items' => [], 'cursor' => null];
        }
        $file = new \SplFileObject(Storage::disk('local')->path((string) $files[$f]));
        $file->seek((int) $c['line']);
        $items = [];
        $read = 0;
        while (! $file->eof() && $read < self::ROWS) {
            $line = trim((string) $file->current());
            $file->next();
            $read++;
            $row = $line !== '' ? json_decode($line, true) : null;
            if (is_array($row)) {
                $items[] = $this->contactRow($row);
            }
        }

        return ['items' => $items, 'cursor' => $file->eof() ? ['export' => $c['export'], 'files' => $files, 'f' => $f + 1, 'line' => 0] : ['export' => $c['export'], 'files' => $files, 'f' => $f, 'line' => (int) $c['line'] + $read]];
    }

    /**
     * Downloads one export file (a presigned link: no API key is sent) and stores it as one JSON object per line.
     */
    private function download(string $url, string $export, int $i): string
    {
        if (! preg_match('#^https://#i', $url)) {
            throw new ProviderError('SendGrid returned an unexpected export link.');
        }
        $r = $this->check($this->send(fn () => Http::timeout(110)->get($url)));
        $raw = $r->body();
        if (str_starts_with($raw, "\x1f\x8b")) {
            $raw = (string) gzdecode($raw);
        }
        $raw = ltrim($raw, "\xEF\xBB\xBF \n\r\t");
        $rows = [];
        if (str_starts_with($raw, '[')) {
            $rows = (array) json_decode($raw, true);
        } elseif (str_starts_with($raw, '{')) {
            foreach (preg_split("/\r?\n/", $raw) ?: [] as $l) {
                if (trim($l) !== '' && is_array($j = json_decode($l, true))) {
                    $rows[] = $j;
                }
            }
        } else { // CSV
            $lines = preg_split("/\r?\n/", $raw) ?: [];
            $head = str_getcsv((string) array_shift($lines));
            foreach ($lines as $l) {
                if (trim($l) !== '') {
                    // Pad short rows and trim long ones so the columns always line up with the header.
                    $rows[] = array_combine($head, array_slice(array_pad(str_getcsv($l), count($head), ''), 0, count($head)));
                }
            }
        }
        $path = 'imports/sendgrid-'.$export.'-'.$i.'.jsonl';
        Storage::disk('local')->put($path, implode("\n", array_map(fn ($x) => json_encode($x, JSON_UNESCAPED_UNICODE), $rows)));

        return $path;
    }

    /**
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    private function contactRow(array $r): array
    {
        $pick = function (array $keys) use ($r) {
            foreach ($keys as $k) {
                if (isset($r[$k]) && $r[$k] !== '') {
                    return $r[$k];
                }
            }

            return null;
        };
        $lists = $pick(['list_ids', 'LIST_IDS', 'list_id']);
        $fields = (array) ($r['custom_fields'] ?? []);
        foreach ($r as $k => $v) { // CSV exports flatten custom fields into columns
            if (is_scalar($v) && ! in_array(strtolower((string) $k), ['email', 'first_name', 'last_name', 'list_ids', 'contact_id', 'id', 'created_at', 'updated_at', 'segment_ids'], true) && ! isset($fields[$k])) {
                $fields[$k] = $v;
            }
        }

        return ['email' => (string) ($pick(['email', 'EMAIL', 'Email']) ?? ''), 'first_name' => $pick(['first_name', 'FIRST_NAME']), 'last_name' => $pick(['last_name', 'LAST_NAME']),
            'fields' => $fields, 'tags' => [], 'lists' => is_array($lists) ? array_map('strval', $lists) : array_filter(array_map('trim', explode(',', (string) $lists))),
            'status' => 'subscribed']; // SendGrid contacts have no status; the suppression stage already blocked the opted-out
    }

    /** @return list<array<string, mixed>> */
    private function allLists(): array
    {
        $out = [];
        $token = null;
        for ($i = 0; $i < 50; $i++) {
            $r = $this->get('/v3/marketing/lists', array_filter(['page_size' => 1000, 'page_token' => $token]));
            array_push($out, ...(array) ($r['result'] ?? []));
            $next = $r['_metadata']['next'] ?? null;
            if (! $next || ! preg_match('/page_token=([^&]+)/', (string) $next, $m)) {
                break;
            }
            $token = urldecode($m[1]);
        }

        return $out;
    }

    public function convertMergeTags(string $html): string
    {
        $html = (string) preg_replace('/\{\{\{\s*unsubscribe\s*\}\}\}/', '{{unsubscribe_url}}', $html);
        $html = (string) preg_replace('/\{\{\{\s*unsubscribe_preferences\s*\}\}\}/', '{{preferences_url}}', $html);
        $html = (string) str_replace(['<%asm_group_unsubscribe_raw_url%>', '<%asm_global_unsubscribe_raw_url%>', '<%asm_preferences_raw_url%>'], ['{{unsubscribe_url}}', '{{unsubscribe_url}}', '{{preferences_url}}'], $html);

        return (string) preg_replace_callback('/\{\{\{?\s*([A-Za-z_][A-Za-z0-9_]*)\s*\}?\}\}/', function ($m) {
            if (in_array($m[1], ['unsubscribe_url', 'preferences_url', 'web_version_url'], true)) {
                return '{{'.$m[1].'}}';
            }
            $key = in_array($m[1], ['first_name', 'last_name', 'email'], true) ? $m[1] : MigrationService::fieldKey($m[1]);

            return $key ? '{{ '.$key.' }}' : $m[0];
        }, $html);
    }
}
