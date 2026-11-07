<?php

namespace App\Services\Migration\Providers;

use App\Services\Migration\MigrationService;
use App\Services\Platform\Brand;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Brevo API v3 (developers.brevo.com). Suppressions: /smtp/blockedContacts (reason.code) and contacts with
 * emailBlacklisted. Names live in attributes FIRSTNAME / LASTNAME; other attributes become custom fields.
 */
class BrevoProvider extends BaseProvider
{
    public const NAME = 'Brevo';

    public const REASONS = ['hardBounce' => 'bounce', 'contactFlaggedAsSpam' => 'complaint', 'unsubscribedViaEmail' => 'unsubscribe', 'unsubscribedViaApi' => 'unsubscribe', 'unsubscribedViaMA' => 'unsubscribe', 'adminBlocked' => 'unsubscribe'];

    protected function host(): string
    {
        return 'api.brevo.com';
    }

    protected function http(): PendingRequest
    {
        return Http::withHeaders(['api-key' => $this->key()])->acceptJson()->timeout(60)->connectTimeout(10)->baseUrl('https://api.brevo.com/v3');
    }

    public function account(): array
    {
        $r = $this->get('/account');

        return ['name' => (string) (($r['companyName'] ?? '') ?: ($r['email'] ?? 'Brevo account'))];
    }

    public function discover(): array
    {
        return ['contacts' => (int) ($this->get('/contacts', ['limit' => 1])['count'] ?? 0), 'suppressions' => (int) ($this->get('/smtp/blockedContacts', ['limit' => 1])['count'] ?? 0),
            'templates' => (int) ($this->get('/smtp/templates', ['limit' => 1])['count'] ?? 0), 'lists' => $this->allLists(),
            'notes' => ['Blocked contacts (hard bounces, spam complaints, unsubscribes) and blocklisted contacts are imported first as suppressions.', 'FIRSTNAME and LASTNAME become first and last name; other attributes become custom fields.', 'SMS and WhatsApp data aren’t imported: '.Brand::name().' is email only.']];
    }

    public function stages(): array
    {
        return ['suppressions', 'lists', 'contacts', 'templates'];
    }

    public function fetch(string $stage, ?array $cursor, array $options): array
    {
        $o = (int) ($cursor['o'] ?? 0);
        switch ($stage) {
            case 'suppressions':
                $r = $this->get('/smtp/blockedContacts', ['limit' => 100, 'offset' => $o]);
                $rows = (array) ($r['contacts'] ?? []);

                return ['items' => array_map(fn ($b) => ['email' => (string) ($b['email'] ?? ''), 'reason' => self::REASONS[$b['reason']['code'] ?? ''] ?? 'unsubscribe', 'detail' => (string) ($b['reason']['code'] ?? '')], $rows),
                    'cursor' => count($rows) === 100 ? ['o' => $o + 100] : null, 'total' => isset($r['count']) ? (int) $r['count'] : null];
            case 'lists':
                return ['items' => $this->allLists(), 'cursor' => null];
            case 'contacts':
                $r = $this->get('/contacts', ['limit' => 1000, 'offset' => $o, 'sort' => 'asc']);
                $rows = (array) ($r['contacts'] ?? []);
                $items = array_map(function ($c) {
                    $a = (array) ($c['attributes'] ?? []);

                    return ['email' => (string) ($c['email'] ?? ''), 'first_name' => $a['FIRSTNAME'] ?? null, 'last_name' => $a['LASTNAME'] ?? null,
                        'fields' => array_diff_key($a, ['FIRSTNAME' => 1, 'LASTNAME' => 1, 'EMAIL' => 1, 'SMS' => 1]), 'tags' => [], 'lists' => array_map('strval', (array) ($c['listIds'] ?? [])),
                        'status' => ! empty($c['emailBlacklisted']) ? 'unsubscribed' : 'subscribed'];
                }, $rows);

                return ['items' => $items, 'cursor' => count($rows) === 1000 ? ['o' => $o + 1000] : null, 'total' => isset($r['count']) ? (int) $r['count'] : null];
            case 'templates':
                $r = $this->get('/smtp/templates', ['limit' => 50, 'offset' => $o]);
                $rows = (array) ($r['templates'] ?? []);

                return ['items' => array_map(fn ($t) => ['name' => (string) ($t['name'] ?? 'Brevo template'), 'subject' => (string) ($t['subject'] ?? ''), 'html' => (string) ($t['htmlContent'] ?? '')], $rows),
                    'cursor' => count($rows) === 50 ? ['o' => $o + 50] : null, 'total' => isset($r['count']) ? (int) $r['count'] : null];
        }

        return ['items' => [], 'cursor' => null];
    }

    /** @return list<array{ext_id: string, name: string, count: int|null}> */
    private function allLists(): array
    {
        $out = [];
        for ($o = 0; $o < 5000; $o += 50) {
            $rows = (array) ($this->get('/contacts/lists', ['limit' => 50, 'offset' => $o])['lists'] ?? []);
            foreach ($rows as $l) {
                $out[] = ['ext_id' => (string) $l['id'], 'name' => (string) $l['name'], 'count' => isset($l['uniqueSubscribers']) ? (int) $l['uniqueSubscribers'] : null];
            }
            if (count($rows) < 50) {
                break;
            }
        }

        return $out;
    }

    public function convertMergeTags(string $html): string
    {
        $html = (string) preg_replace_callback('/\{\{\s*contact\.([A-Za-z0-9_]+)\s*(?:\|\s*default\s*:\s*["\']([^"\']*)["\'])?\s*\}\}/', function ($m) {
            $key = ['FIRSTNAME' => 'first_name', 'LASTNAME' => 'last_name', 'EMAIL' => 'email'][$m[1]] ?? MigrationService::fieldKey($m[1]);

            return $key ? '{{ '.$key.(isset($m[2]) && $m[2] !== '' ? ' | '.$m[2] : '').' }}' : '';
        }, $html);

        return (string) preg_replace(['/\{\{\s*unsubscribe\s*\}\}/', '/\{\{\s*update_profile\s*\}\}/', '/\{\{\s*mirror\s*\}\}/'], ['{{unsubscribe_url}}', '{{preferences_url}}', '{{web_version_url}}'], $html);
    }
}
