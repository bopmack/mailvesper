<?php

namespace App\Services\Migration\Providers;

use App\Services\Migration\MigrationService;
use App\Services\Platform\Brand;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Klaviyo (developers.klaviyo.com, revision 2026-10-15). Suppression data needs additional-fields[profile]=subscriptions:
 * subscriptions.email.marketing.suppression[].reason (HARD_BOUNCE, INVALID_EMAIL, SPAM_COMPLAINT, UNSUBSCRIBE, USER_SUPPRESSED).
 * A profile that can't receive email marketing and isn't suppressed (never consented) isn't imported as subscribed.
 */
class KlaviyoProvider extends BaseProvider
{
    public const NAME = 'Klaviyo';

    public const REVISION = '2026-10-15';

    public const REASONS = ['HARD_BOUNCE' => 'bounce', 'INVALID_EMAIL' => 'bounce', 'SPAM_COMPLAINT' => 'complaint', 'UNSUBSCRIBE' => 'unsubscribe', 'USER_SUPPRESSED' => 'unsubscribe'];

    protected function host(): string
    {
        return 'a.klaviyo.com';
    }

    protected function http(): PendingRequest
    {
        return Http::withHeaders(['Authorization' => 'Klaviyo-API-Key '.$this->key(), 'revision' => self::REVISION, 'Accept' => 'application/vnd.api+json'])->timeout(60)->connectTimeout(10)->baseUrl('https://a.klaviyo.com/api');
    }

    public function account(): array
    {
        $r = $this->get('/accounts');

        return ['name' => (string) ($r['data'][0]['attributes']['contact_information']['organization_name'] ?? 'Klaviyo account')];
    }

    public function discover(): array
    {
        return ['contacts' => null, 'suppressions' => null, 'templates' => null, 'lists' => $this->allLists(),
            'notes' => ['Suppressed profiles (hard bounces, spam complaints, unsubscribes) are imported first.', 'Profiles that never agreed to email marketing aren’t imported as subscribers.', 'Profile properties become custom fields. Segments, flows, metrics and orders aren’t imported; send events through the '.Brand::name().' API instead.']];
    }

    public function stages(): array
    {
        return ['suppressions', 'lists', 'contacts', 'templates'];
    }

    public function fetch(string $stage, ?array $cursor, array $options): array
    {
        switch ($stage) {
            case 'suppressions':
                $reasons = array_keys(self::REASONS);
                $p = (int) ($cursor['p'] ?? 0);
                $r = isset($cursor['next']) ? $this->get((string) $cursor['next']) : $this->get('/profiles', ['additional-fields[profile]' => 'subscriptions', 'page[size]' => 100, 'fields[profile]' => 'email,subscriptions',
                    'filter' => 'equals(subscriptions.email.marketing.suppression.reason,"'.$reasons[$p].'")']);
                $items = array_map(fn ($d) => ['email' => (string) ($d['attributes']['email'] ?? ''), 'reason' => self::REASONS[$reasons[$p]], 'detail' => strtolower($reasons[$p])], (array) ($r['data'] ?? []));
                $next = $r['links']['next'] ?? null;

                return ['items' => $items, 'cursor' => $next ? ['p' => $p, 'next' => $next] : (isset($reasons[$p + 1]) ? ['p' => $p + 1] : null)];
            case 'lists':
                return ['items' => $this->allLists(), 'cursor' => null];
            case 'contacts':
                // Pass 1: every profile. Pass 2: each chosen list's profiles, for list membership.
                $phase = (string) ($cursor['phase'] ?? 'all');
                if ($phase === 'all') {
                    $r = isset($cursor['next']) ? $this->get((string) $cursor['next']) : $this->get('/profiles', ['additional-fields[profile]' => 'subscriptions', 'page[size]' => 100]);
                    $next = $r['links']['next'] ?? null;

                    return ['items' => array_map(fn ($d) => $this->profile($d, null), (array) ($r['data'] ?? [])), 'cursor' => $next ? ['phase' => 'all', 'next' => $next] : ['phase' => 'lists', 'l' => 0]];
                }
                $lists = array_values(array_filter($this->allLists(), fn ($l) => ! ($options['list_ids'] ?? null) || in_array($l['ext_id'], (array) $options['list_ids'], true)));
                $l = (int) ($cursor['l'] ?? 0);
                if (! isset($lists[$l])) {
                    return ['items' => [], 'cursor' => null];
                }
                $id = $lists[$l]['ext_id'];
                $r = isset($cursor['next']) ? $this->get((string) $cursor['next']) : $this->get("/lists/$id/profiles", ['additional-fields[profile]' => 'subscriptions', 'page[size]' => 100]);
                $next = $r['links']['next'] ?? null;

                return ['items' => array_map(fn ($d) => $this->profile($d, $id), (array) ($r['data'] ?? [])), 'cursor' => $next ? ['phase' => 'lists', 'l' => $l, 'next' => $next] : (isset($lists[$l + 1]) ? ['phase' => 'lists', 'l' => $l + 1] : null)];
            case 'templates':
                $r = isset($cursor['next']) ? $this->get((string) $cursor['next']) : $this->get('/templates', ['fields[template]' => 'name,html']);
                $items = array_map(fn ($d) => ['name' => (string) ($d['attributes']['name'] ?? 'Klaviyo template'), 'subject' => '', 'html' => (string) ($d['attributes']['html'] ?? '')], (array) ($r['data'] ?? []));
                $next = $r['links']['next'] ?? null;

                return ['items' => $items, 'cursor' => $next ? ['next' => $next] : null];
        }

        return ['items' => [], 'cursor' => null];
    }

    /**
     * @param  array<string, mixed>  $d
     * @return array<string, mixed>
     */
    private function profile(array $d, ?string $list): array
    {
        $a = (array) ($d['attributes'] ?? []);
        $mk = (array) ($a['subscriptions']['email']['marketing'] ?? []);
        $reason = null;
        foreach ((array) ($mk['suppression'] ?? []) as $s) {
            $reason ??= self::REASONS[$s['reason'] ?? ''] ?? null;
        }
        $status = match (true) {
            $reason === 'bounce' => 'bounced', $reason === 'complaint' => 'complained', $reason === 'unsubscribe' => 'unsubscribed',
            ($mk['consent'] ?? '') === 'UNSUBSCRIBED' => 'unsubscribed',
            (bool) ($mk['can_receive_email_marketing'] ?? false) => 'subscribed',
            default => 'pending', // never agreed to email marketing
        };

        return ['email' => (string) ($a['email'] ?? ''), 'first_name' => $a['first_name'] ?? null, 'last_name' => $a['last_name'] ?? null, 'fields' => (array) ($a['properties'] ?? []), 'tags' => [], 'lists' => $list ? [$list] : [], 'status' => $status];
    }

    /** @return list<array{ext_id: string, name: string, count: int|null}> */
    private function allLists(): array
    {
        $out = [];
        $r = $this->get('/lists');
        for ($i = 0; $i < 100; $i++) {
            foreach ((array) ($r['data'] ?? []) as $l) {
                $out[] = ['ext_id' => (string) $l['id'], 'name' => (string) ($l['attributes']['name'] ?? 'Klaviyo list'), 'count' => null];
            }
            $next = $r['links']['next'] ?? null;
            if (! $next) {
                break;
            }
            $r = $this->get((string) $next);
        }

        return $out;
    }

    public function convertMergeTags(string $html): string
    {
        $html = (string) preg_replace('/\{%\s*unsubscribe_link\s*%\}/', '{{unsubscribe_url}}', $html);
        $html = (string) preg_replace('/\{%\s*unsubscribe(?:\s+[^%]*)?\s*%\}/', '<a href="{{unsubscribe_url}}">Unsubscribe</a>', $html);
        $html = (string) preg_replace('/\{%\s*manage_preferences_link\s*%\}/', '{{preferences_url}}', $html);
        $html = (string) preg_replace('/\{%\s*web_view_link\s*%\}/', '{{web_version_url}}', $html);

        return (string) preg_replace_callback('/\{\{\s*(?:person\.)?([A-Za-z_][A-Za-z0-9_]*)\s*(?:\|\s*default\s*:\s*["\']([^"\']*)["\'])?\s*\}\}/', function ($m) {
            if (in_array($m[1], ['unsubscribe_url', 'preferences_url', 'web_version_url'], true)) {
                return '{{'.$m[1].'}}';
            }
            $key = in_array($m[1], ['first_name', 'last_name', 'email'], true) ? $m[1] : MigrationService::fieldKey($m[1]);

            return $key ? '{{ '.$key.(isset($m[2]) && $m[2] !== '' ? ' | '.$m[2] : '').' }}' : '';
        }, $html);
    }
}
