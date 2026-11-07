<?php

namespace App\Services\Migration\Providers;

use App\Services\Migration\MigrationService;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Mailgun (documentation.mailgun.com). US or EU host. Suppressions are per sending domain (bounces, complaints,
 * unsubscribes), paged by following paging.next. Mailing lists → lists; members → contacts (name split in two).
 */
class MailgunProvider extends BaseProvider
{
    public const NAME = 'Mailgun';

    public const KINDS = ['bounces' => 'bounce', 'complaints' => 'complaint', 'unsubscribes' => 'unsubscribe'];

    protected function host(): string
    {
        return ($this->credentials['region'] ?? 'us') === 'eu' ? 'api.eu.mailgun.net' : 'api.mailgun.net';
    }

    protected function http(): PendingRequest
    {
        $host = ($this->credentials['region'] ?? 'us') === 'eu' ? 'https://api.eu.mailgun.net' : 'https://api.mailgun.net';

        return Http::withBasicAuth('api', $this->key())->acceptJson()->timeout(60)->connectTimeout(10)->baseUrl($host);
    }

    private function domain(): string
    {
        $d = mb_strtolower(trim((string) ($this->credentials['domain'] ?? '')));
        if (! preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $d)) {
            throw new ProviderError('Enter your Mailgun sending domain (like mg.yourdomain.com).');
        }

        return $d;
    }

    public function account(): array
    {
        $d = $this->domain();
        $names = array_map(fn ($x) => (string) ($x['name'] ?? ''), (array) ($this->get('/v4/domains', ['limit' => 1000])['items'] ?? []));
        if (! in_array($d, $names, true)) {
            throw new ProviderError("Mailgun doesn’t have $d in this account and region. Check the domain, or switch between US and EU.");
        }

        return ['name' => $d];
    }

    public function discover(): array
    {
        $d = $this->domain();

        return ['contacts' => null, 'suppressions' => null, 'templates' => count((array) ($this->get("/v3/$d/templates", ['limit' => 100])['items'] ?? [])),
            'lists' => array_map(fn ($l) => ['ext_id' => (string) $l['address'], 'name' => (string) (($l['name'] ?? '') ?: $l['address']), 'count' => (int) ($l['members_count'] ?? 0)], $this->allLists()),
            'notes' => ["Bounces, spam complaints and unsubscribes for $d are imported first.", 'Mailing list members become contacts; routes and inbound mail aren’t imported.']];
    }

    public function stages(): array
    {
        return ['suppressions', 'lists', 'contacts', 'templates'];
    }

    public function fetch(string $stage, ?array $cursor, array $options): array
    {
        $d = $this->domain();
        switch ($stage) {
            case 'suppressions':
                $kinds = array_keys(self::KINDS);
                $k = (int) ($cursor['k'] ?? 0);
                $r = isset($cursor['next']) ? $this->get((string) $cursor['next']) : $this->get("/v3/$d/{$kinds[$k]}", ['limit' => 1000]);
                $rows = (array) ($r['items'] ?? []);
                $items = array_map(fn ($x) => ['email' => (string) ($x['address'] ?? ''), 'reason' => self::KINDS[$kinds[$k]], 'detail' => isset($x['code']) ? (string) $x['code'] : $kinds[$k]], $rows);
                $next = $rows && ! empty($r['paging']['next']) ? ['k' => $k, 'next' => $r['paging']['next']] : (isset($kinds[$k + 1]) ? ['k' => $k + 1] : null);

                return ['items' => $items, 'cursor' => $next];
            case 'lists':
                return ['items' => array_map(fn ($l) => ['ext_id' => (string) $l['address'], 'name' => (string) (($l['name'] ?? '') ?: $l['address'])], $this->allLists()), 'cursor' => null];
            case 'contacts':
                $lists = array_values(array_filter($this->allLists(), fn ($l) => ! ($options['list_ids'] ?? null) || in_array($l['address'], (array) $options['list_ids'], true)));
                $l = (int) ($cursor['l'] ?? 0);
                if (! isset($lists[$l])) {
                    return ['items' => [], 'cursor' => null];
                }
                $addr = (string) $lists[$l]['address'];
                $r = isset($cursor['next']) ? $this->get((string) $cursor['next']) : $this->get('/v3/lists/'.rawurlencode($addr).'/members/pages', ['limit' => 100]);
                $rows = (array) ($r['items'] ?? []);
                $items = array_map(function ($m) use ($addr) {
                    $name = trim((string) ($m['name'] ?? ''));
                    [$first, $last] = array_pad(explode(' ', $name, 2), 2, null);

                    return ['email' => (string) ($m['address'] ?? ''), 'first_name' => $first ?: null, 'last_name' => $last, 'fields' => (array) ($m['vars'] ?? []), 'tags' => [], 'lists' => [$addr],
                        'status' => ($m['subscribed'] ?? true) ? 'subscribed' : 'unsubscribed'];
                }, $rows);
                $next = $rows && ! empty($r['paging']['next']) ? ['l' => $l, 'next' => $r['paging']['next']] : (isset($lists[$l + 1]) ? ['l' => $l + 1] : null);

                return ['items' => $items, 'cursor' => $next];
            case 'templates':
                $items = [];
                foreach ((array) ($this->get("/v3/$d/templates", ['limit' => 100])['items'] ?? []) as $t) {
                    $v = $this->get("/v3/$d/templates/".rawurlencode((string) $t['name']), ['active' => 'yes'])['template']['version'] ?? [];
                    if (! empty($v['template'])) {
                        $items[] = ['name' => (string) $t['name'], 'subject' => (string) ($v['headers']['Subject'] ?? $v['headers']['subject'] ?? ''), 'html' => (string) $v['template']];
                    }
                }

                return ['items' => $items, 'cursor' => null];
        }

        return ['items' => [], 'cursor' => null];
    }

    /** @return list<array<string, mixed>> */
    private function allLists(): array
    {
        $out = [];
        for ($skip = 0; $skip < 10000; $skip += 100) {
            $rows = (array) ($this->get('/v3/lists', ['limit' => 100, 'skip' => $skip])['items'] ?? []);
            array_push($out, ...$rows);
            if (count($rows) < 100) {
                break;
            }
        }

        return $out;
    }

    public function convertMergeTags(string $html): string
    {
        $html = (string) str_replace(['%unsubscribe_url%', '%mailing_list_unsubscribe_url%', '%tag_unsubscribe_url%', '%recipient_fname%', '%recipient_lname%', '%recipient_email%', '%recipient%'],
            ['{{unsubscribe_url}}', '{{unsubscribe_url}}', '{{unsubscribe_url}}', '{{ first_name }}', '{{ last_name }}', '{{ email }}', '{{ email }}'], $html);

        return (string) preg_replace_callback('/%recipient\.([A-Za-z0-9_]+)%/', function ($m) {
            $key = in_array($m[1], ['first_name', 'last_name', 'email'], true) ? $m[1] : MigrationService::fieldKey($m[1]);

            return $key ? '{{ '.$key.' }}' : '';
        }, $html);
    }
}
