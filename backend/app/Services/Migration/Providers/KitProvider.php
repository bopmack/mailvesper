<?php

namespace App\Services\Migration\Providers;

use App\Services\Migration\MigrationService;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Kit (ConvertKit) API v4 (developers.kit.com). Subscriber state: active → subscribed, cancelled → unsubscribed,
 * bounced, complained; inactive (never confirmed) isn't imported as subscribed. Broadcast HTML becomes templates.
 */
class KitProvider extends BaseProvider
{
    public const NAME = 'Kit';

    public const STATES = ['active' => 'subscribed', 'cancelled' => 'unsubscribed', 'bounced' => 'bounced', 'complained' => 'complained'];

    protected function host(): string
    {
        return 'api.kit.com';
    }

    protected function http(): PendingRequest
    {
        return Http::withHeaders(['X-Kit-Api-Key' => $this->key()])->acceptJson()->timeout(60)->connectTimeout(10)->baseUrl('https://api.kit.com/v4');
    }

    public function account(): array
    {
        $r = $this->get('/account');

        return ['name' => (string) ($r['account']['name'] ?? 'Kit account')];
    }

    public function discover(): array
    {
        $r = $this->get('/subscribers', ['status' => 'all', 'per_page' => 1, 'include_total_count' => 'true']);

        return ['contacts' => isset($r['pagination']['total_count']) ? (int) $r['pagination']['total_count'] : null, 'suppressions' => null, 'templates' => null, 'lists' => [],
            'notes' => ['Cancelled, bounced and complained subscribers are imported first as suppressions; unconfirmed (inactive) ones aren’t imported as subscribers.', 'Tags come over as tags. Kit has no last name field: a custom “last_name” field is used when you have one.', 'Your sent broadcasts come over as templates.']];
    }

    public function stages(): array
    {
        return ['suppressions', 'contacts', 'templates'];
    }

    public function fetch(string $stage, ?array $cursor, array $options): array
    {
        if ($stage === 'suppressions') {
            $passes = ['cancelled' => 'unsubscribe', 'bounced' => 'bounce', 'complained' => 'complaint'];
            $keys = array_keys($passes);
            $p = (int) ($cursor['p'] ?? 0);
            $r = $this->get('/subscribers', array_filter(['status' => $keys[$p], 'per_page' => 1000, 'slim' => 'true', 'after' => $cursor['a'] ?? null]));
            $items = array_map(fn ($s) => ['email' => (string) ($s['email_address'] ?? ''), 'reason' => $passes[$keys[$p]], 'detail' => $keys[$p]], (array) ($r['subscribers'] ?? []));
            $next = ! empty($r['pagination']['has_next_page']) ? ['p' => $p, 'a' => $r['pagination']['end_cursor']] : (isset($keys[$p + 1]) ? ['p' => $p + 1] : null);

            return ['items' => $items, 'cursor' => $next];
        }
        if ($stage === 'contacts') {
            $r = $this->get('/subscribers', array_filter(['status' => 'all', 'per_page' => 1000, 'include' => 'tags', 'after' => $cursor['a'] ?? null]));
            $items = array_map(function ($s) {
                $f = (array) ($s['fields'] ?? []);

                return ['email' => (string) ($s['email_address'] ?? ''), 'first_name' => $s['first_name'] ?? null, 'last_name' => $f['last_name'] ?? null,
                    'fields' => array_diff_key($f, ['last_name' => 1]), 'tags' => array_map(fn ($t) => (string) ($t['name'] ?? ''), (array) ($s['tags'] ?? [])), 'lists' => [],
                    'status' => self::STATES[$s['state'] ?? ''] ?? 'pending'];
            }, (array) ($r['subscribers'] ?? []));

            return ['items' => $items, 'cursor' => ! empty($r['pagination']['has_next_page']) ? ['a' => $r['pagination']['end_cursor']] : null];
        }
        if ($stage === 'templates') {
            $r = $this->get('/broadcasts', array_filter(['per_page' => 50, 'after' => $cursor['a'] ?? null]));
            $items = [];
            foreach ((array) ($r['broadcasts'] ?? []) as $b) {
                $html = $b['content'] ?? null;
                if (! $html && isset($b['id'])) {
                    $html = $this->get('/broadcasts/'.$b['id'])['broadcast']['content'] ?? null;
                }
                if ($html) {
                    $items[] = ['name' => (string) (($b['subject'] ?? '') ?: 'Kit broadcast'), 'subject' => (string) ($b['subject'] ?? ''), 'html' => (string) $html];
                }
            }

            return ['items' => $items, 'cursor' => ! empty($r['pagination']['has_next_page']) && count($items) < 200 ? ['a' => $r['pagination']['end_cursor']] : null];
        }

        return ['items' => [], 'cursor' => null];
    }

    public function convertMergeTags(string $html): string
    {
        $html = (string) preg_replace('/\{\{\s*unsubscribe_link\s*\}\}/', '<a href="{{unsubscribe_url}}">Unsubscribe</a>', $html);
        $html = (string) preg_replace('/\{\{\s*unsubscribe_url\s*\}\}/', '{{unsubscribe_url}}', $html);

        return (string) preg_replace_callback('/\{\{\s*subscriber\.([a-z_]+)\s*(?:\|\s*default\s*:\s*["\']([^"\']*)["\'])?\s*\}\}/', function ($m) {
            $key = ['first_name' => 'first_name', 'email_address' => 'email'][$m[1]] ?? MigrationService::fieldKey($m[1]);

            return $key ? '{{ '.$key.(isset($m[2]) && $m[2] !== '' ? ' | '.$m[2] : '').' }}' : '';
        }, $html);
    }
}
