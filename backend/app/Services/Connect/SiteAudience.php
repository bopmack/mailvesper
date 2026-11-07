<?php

namespace App\Services\Connect;

use App\Exceptions\ApiException;
use App\Models\Segment;
use App\Models\SiteConnection;
use App\Services\Contacts\SegmentQuery;
use App\Services\Platform\Brand;
use App\Services\Webhooks\Signer;
use App\Services\Webhooks\UrlGuard;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * A connected site's users, read LIVE through its MailVesper package: how many match a segment, a page to browse, and a
 * campaign's recipients when it starts. Nothing is stored as a contact. Every request is signed with the secret the
 * site and MailVesper share, and goes to exactly the IP address that was checked to be public.
 */
class SiteAudience
{
    /** Built-in fields a site can answer (everything else is the site's own fields, "field:<key>"). */
    public const BUILT_IN = ['email' => 'text', 'first_name' => 'text', 'last_name' => 'text', 'status' => 'enum', 'created_at' => 'date'];

    /** Field types a site can declare. */
    public const TYPES = ['text', 'number', 'date', 'boolean'];

    public static function live(?SiteConnection $c): bool
    {
        return $c !== null && $c->revoked_at === null && $c->connected_at !== null && $c->audience_url !== null && $c->audience_secret !== null;
    }

    /** The live site a segment reads from, or null for an ordinary contacts segment. */
    public static function forSegment(Segment $s): ?SiteConnection
    {
        if (! $s->site_connection_id) {
            return null;
        }
        $c = SiteConnection::where('workspace_id', $s->workspace_id)->find($s->site_connection_id);
        if (! self::live($c)) {
            throw new ApiException('site_unavailable', 'This segment reads its people live from a site that is no longer connected. Connect the site again from its admin.', 422);
        }

        return $c;
    }

    /** @return array<string, string> key → type */
    public static function types(SiteConnection $c): array
    {
        return collect($c->fields ?? [])->mapWithKeys(fn ($f) => [(string) $f['key'] => (string) $f['type']])->all();
    }

    /**
     * Checks rules for a live site: the same rule language as contacts, limited to what a site has (no tags, lists,
     * events or engagement).
     *
     * @param  array<string, mixed>  $rules
     */
    public static function validate(SiteConnection $c, array $rules): void
    {
        $types = self::types($c);
        foreach ((array) ($rules['conditions'] ?? []) as $i => $cond) {
            $field = is_array($cond) ? (string) ($cond['field'] ?? '') : '';
            $ok = isset(self::BUILT_IN[$field]) || (str_starts_with($field, 'field:') && isset($types[substr($field, 6)]));
            if (! $ok) {
                throw new ApiException('invalid_rules', 'Condition '.((int) $i + 1).': '.$c->name.' doesn’t have that. Live site audiences can use email, name, marketing status, sign-up date and the site’s own fields.', 422);
            }
            if ($field === 'status' && ! in_array($cond['value'] ?? null, ['subscribed', 'unsubscribed'], true)) {
                throw new ApiException('invalid_rules', 'Condition '.((int) $i + 1).': on a live site, status is subscribed or unsubscribed.', 422);
            }
        }
        (new SegmentQuery($types))->validate($rules); // operators and values, as for contacts
    }

    /**
     * How many people match each set of rules.
     *
     * @param  array<string, array<string, mixed>>  $sets  id → rules
     * @return array<string, int>
     */
    public static function counts(SiteConnection $c, array $sets, bool $consentedOnly): array
    {
        if (! $sets) {
            return [];
        }
        $r = self::ask($c, ['op' => 'counts', 'sets' => array_map(fn ($rules) => $rules ?: (object) [], $sets), 'consented_only' => $consentedOnly]);

        return array_map('intval', (array) ($r['counts'] ?? []));
    }

    /**
     * A page of people to browse.
     *
     * @param  array<string, mixed>|null  $rules
     * @return array{people: list<array<string, mixed>>, total: int}
     */
    public static function page(SiteConnection $c, ?array $rules, string $q, int $page, int $per = 50, bool $consentedOnly = false): array
    {
        $r = self::ask($c, ['op' => 'page', 'rules' => $rules ?: null, 'q' => mb_substr($q, 0, 100), 'page' => max(1, $page), 'per_page' => max(1, min(100, $per)), 'consented_only' => $consentedOnly]);

        return ['people' => array_values((array) ($r['people'] ?? [])), 'total' => (int) ($r['total'] ?? 0)];
    }

    /**
     * The next people in a campaign's audience, in the site's id order.
     *
     * @param  array<string, mixed>  $rules
     * @return array{people: list<array<string, mixed>>, next: ?string}
     */
    public static function chunk(SiteConnection $c, array $rules, ?string $after, int $limit = 500): array
    {
        $r = self::ask($c, ['op' => 'chunk', 'rules' => $rules ?: (object) [], 'after' => $after, 'limit' => $limit, 'consented_only' => true], 60);

        return ['people' => array_values((array) ($r['people'] ?? [])), 'next' => isset($r['next']) && $r['next'] !== '' ? (string) $r['next'] : null];
    }

    /**
     * A person's merge tags ({{first_name}}, the site's fields…), as for a stored contact.
     *
     * @param  array<string, mixed>  $p
     * @return array<string, scalar>
     */
    public static function mergeVars(array $p): array
    {
        $vars = ['first_name' => $p['first_name'] ?? null, 'last_name' => $p['last_name'] ?? null, 'email' => $p['email'] ?? null] + (array) ($p['fields'] ?? []);

        return array_filter($vars, fn ($v) => is_scalar($v));
    }

    /**
     * One signed request to the site.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public static function ask(SiteConnection $c, array $body, int $timeout = 20): array
    {
        if (! self::live($c)) {
            throw new ApiException('site_unavailable', $c->name.' isn’t connected for live audiences yet. Its admin screen says why.', 422);
        }
        $fail = function (string $why) use ($c) {
            $c->forceFill(['audience_error' => mb_substr($why, 0, 255)])->save();

            return new ApiException('site_unavailable', Brand::name().' couldn’t read '.$c->name.'’s users: '.$why, 502);
        };
        [$problem, $ip] = UrlGuard::vet((string) $c->audience_url);
        if ($problem !== null) {
            throw $fail($problem);
        }
        $p = parse_url((string) $c->audience_url);
        $port = (int) ($p['port'] ?? 443);
        $json = (string) json_encode($body);
        try {
            $r = Http::withHeaders(Signer::headers((string) $c->audience_secret, 'aud_'.Str::random(20), time(), $json) + ['User-Agent' => 'MailVesper-Audience/1.0', 'Accept' => 'application/json'])
                ->withBody($json, 'application/json')->connectTimeout(5)->timeout($timeout)->withoutRedirecting()
                ->withOptions($ip ? ['curl' => [CURLOPT_RESOLVE => [$p['host'].':'.$port.':'.$ip]]] : [])
                ->post((string) $c->audience_url);
        } catch (Throwable $e) {
            throw $fail('the site didn’t answer ('.mb_substr($e->getMessage(), 0, 120).').');
        }
        if ($r->status() === 422) {
            throw new ApiException('invalid_rules', (string) ($r->json('error') ?: 'The site couldn’t use those rules.'), 422);
        }
        if (! $r->successful() || ! is_array($r->json())) {
            throw $fail($r->status() === 401 ? 'the site didn’t accept '.Brand::name().'’s signature (connect it again from its admin).' : 'the site answered HTTP '.$r->status().'.');
        }
        if ($c->audience_error !== null || $c->audience_ok_at === null || $c->audience_ok_at->lt(now()->subMinutes(5))) {
            $c->forceFill(['audience_error' => null, 'audience_ok_at' => now()])->save();
        }

        return (array) $r->json();
    }
}
