<?php

namespace App\Services\Migration\Providers;

use App\Services\Migration\MigrationService;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Mailchimp Marketing API v3 (mailchimp.com/developer). Data centre = the key's suffix (…-us21).
 * Suppressions: members with status unsubscribed (→ unsubscribe) and cleaned (→ hard bounce) in every audience,
 * plus each audience's abuse reports (→ spam complaint). Pending, transactional and archived members are not
 * imported as subscribed. Email HTML comes from sent campaigns (templates themselves carry no HTML in the API).
 */
class MailchimpProvider extends BaseProvider
{
    public const NAME = 'Mailchimp';

    public const PAGE = 1000;

    public const CAMPAIGNS = 25;

    /** Suppression passes per audience, in order. */
    public const PASSES = [['members', 'unsubscribed', 'unsubscribe'], ['members', 'cleaned', 'bounce'], ['abuse', null, 'complaint']];

    protected function host(): string
    {
        preg_match('/-([a-z]{2,3}\d{1,3})$/', $this->key(), $m);

        return ($m[1] ?? 'us1').'.api.mailchimp.com';
    }

    protected function http(): PendingRequest
    {
        $key = $this->key();
        if (! preg_match('/-([a-z]{2,3}\d{1,3})$/', $key, $m)) {
            throw new ProviderError('That doesn’t look like a Mailchimp API key: it should end in something like -us21.');
        }

        return Http::withBasicAuth('sendline', $key)->acceptJson()->timeout(110)->connectTimeout(10)->baseUrl('https://'.$m[1].'.api.mailchimp.com/3.0');
    }

    public function account(): array
    {
        $r = $this->get('/', ['fields' => 'account_name,email,total_subscribers']);

        return ['name' => (string) ($r['account_name'] ?? 'Mailchimp account')];
    }

    public function discover(): array
    {
        $lists = $this->audiences();
        $contacts = $suppressions = 0;
        foreach ($lists as $l) {
            $contacts += $l['members'] + $l['unsubscribed'] + $l['cleaned'];
            $suppressions += $l['unsubscribed'] + $l['cleaned'];
        }
        $sent = (int) ($this->get('/campaigns', ['status' => 'sent', 'count' => 1, 'fields' => 'total_items'])['total_items'] ?? 0);

        $notes = [
            'Unsubscribed and cleaned (bounced) members, and spam complaints, are imported first as suppressions.',
            'Pending (unconfirmed) and transactional-only members aren’t imported as subscribers: they never agreed to marketing.',
            'Tags come over as tags, and interest groups as tags named “group: …”. Saved segments aren’t imported; rebuild them as segments here.',
        ];
        if ($sent) {
            $notes[] = 'Your '.min(self::CAMPAIGNS, $sent).' most recent sent campaigns come over as templates, with merge tags converted (Mailchimp’s API doesn’t give template HTML).';
        }

        return ['contacts' => $contacts, 'suppressions' => $suppressions, 'templates' => min(self::CAMPAIGNS, $sent), 'notes' => $notes,
            'lists' => array_map(fn ($l) => ['ext_id' => $l['id'], 'name' => $l['name'], 'count' => $l['members']], $lists)];
    }

    public function stages(): array
    {
        return ['suppressions', 'lists', 'contacts', 'templates'];
    }

    public function fetch(string $stage, ?array $cursor, array $options): array
    {
        return match ($stage) {
            'suppressions' => $this->suppressionPage($cursor ?? ['l' => 0, 'p' => 0, 'o' => 0]),
            'lists' => ['items' => array_map(fn ($l) => ['ext_id' => $l['id'], 'name' => $l['name'], 'count' => $l['members']], $this->audiences()), 'cursor' => null],
            'contacts' => $this->contactPage($cursor ?? ['l' => 0, 'o' => 0], $options),
            'templates' => $this->campaignPage(),
            default => ['items' => [], 'cursor' => null],
        };
    }

    /** @return list<array{id: string, name: string, members: int, unsubscribed: int, cleaned: int}> */
    private function audiences(): array
    {
        $out = [];
        $offset = 0;
        do {
            $r = $this->get('/lists', ['count' => 1000, 'offset' => $offset, 'fields' => 'lists.id,lists.name,lists.stats.member_count,lists.stats.unsubscribe_count,lists.stats.cleaned_count,total_items']);
            foreach ((array) ($r['lists'] ?? []) as $l) {
                $out[] = ['id' => (string) $l['id'], 'name' => (string) $l['name'], 'members' => (int) ($l['stats']['member_count'] ?? 0),
                    'unsubscribed' => (int) ($l['stats']['unsubscribe_count'] ?? 0), 'cleaned' => (int) ($l['stats']['cleaned_count'] ?? 0)];
            }
            $offset += 1000;
        } while ($offset < (int) ($r['total_items'] ?? 0));

        return $out;
    }

    /**
     * @param  array<string, mixed>  $c  l = audience index, p = pass index, o = offset
     * @return array{items: list<array<string, mixed>>, cursor: array<string, mixed>|null}
     */
    private function suppressionPage(array $c): array
    {
        $lists = $this->audiences();
        $l = (int) $c['l'];
        if (! isset($lists[$l])) {
            return ['items' => [], 'cursor' => null];
        }
        [$kind, $status, $reason] = self::PASSES[(int) $c['p']];
        $id = $lists[$l]['id'];
        $r = $kind === 'members'
            ? $this->get("/lists/$id/members", ['status' => $status, 'count' => self::PAGE, 'offset' => $c['o'], 'fields' => 'members.email_address,members.unsubscribe_reason,total_items'])
            : $this->get("/lists/$id/abuse-reports", ['count' => self::PAGE, 'offset' => $c['o'], 'fields' => 'abuse_reports.email_address,total_items']);
        $rows = (array) ($kind === 'members' ? ($r['members'] ?? []) : ($r['abuse_reports'] ?? []));
        $items = array_map(fn ($m) => ['email' => (string) ($m['email_address'] ?? ''), 'reason' => $reason, 'detail' => $kind === 'abuse' ? 'spam complaint' : $status], $rows);
        $next = (int) $c['o'] + self::PAGE;
        if ($next < (int) ($r['total_items'] ?? 0)) {
            return ['items' => $items, 'cursor' => ['l' => $l, 'p' => $c['p'], 'o' => $next]];
        }
        $p = (int) $c['p'] + 1;

        return ['items' => $items, 'cursor' => isset(self::PASSES[$p]) ? ['l' => $l, 'p' => $p, 'o' => 0] : (isset($lists[$l + 1]) ? ['l' => $l + 1, 'p' => 0, 'o' => 0] : null)];
    }

    /**
     * @param  array<string, mixed>  $c  l = audience index, o = offset, g = interest id → "group: name"
     * @param  array<string, mixed>  $options
     * @return array{items: list<array<string, mixed>>, cursor: array<string, mixed>|null, total?: int|null}
     */
    private function contactPage(array $c, array $options): array
    {
        $lists = $this->audiences();
        $only = (array) ($options['list_ids'] ?? []);
        if ($only) {
            $lists = array_values(array_filter($lists, fn ($x) => in_array($x['id'], $only, true)));
        }
        $l = (int) $c['l'];
        if (! isset($lists[$l])) {
            return ['items' => [], 'cursor' => null];
        }
        $id = $lists[$l]['id'];
        $groups = $c['g'] ?? null;
        if (! is_array($groups)) {
            $groups = [];
            foreach ((array) ($this->get("/lists/$id/interest-categories", ['count' => 100, 'fields' => 'categories.id'])['categories'] ?? []) as $cat) {
                foreach ((array) ($this->get("/lists/$id/interest-categories/{$cat['id']}/interests", ['count' => 100, 'fields' => 'interests.id,interests.name'])['interests'] ?? []) as $in) {
                    $groups[(string) $in['id']] = 'group: '.mb_substr((string) $in['name'], 0, 55);
                }
            }
        }
        $r = $this->get("/lists/$id/members", ['count' => self::PAGE, 'offset' => $c['o'], 'fields' => 'members.email_address,members.status,members.merge_fields,members.tags,members.interests,total_items']);
        $items = [];
        foreach ((array) ($r['members'] ?? []) as $m) {
            $mf = (array) ($m['merge_fields'] ?? []);
            $tags = array_map(fn ($t) => (string) ($t['name'] ?? ''), (array) ($m['tags'] ?? []));
            foreach ((array) ($m['interests'] ?? []) as $iid => $on) {
                if ($on && isset($groups[$iid])) {
                    $tags[] = $groups[$iid];
                }
            }
            $items[] = ['email' => (string) ($m['email_address'] ?? ''), 'first_name' => $mf['FNAME'] ?? null, 'last_name' => $mf['LNAME'] ?? null,
                'fields' => array_diff_key($mf, ['FNAME' => 1, 'LNAME' => 1, 'EMAIL' => 1]), 'tags' => array_values(array_filter($tags)), 'lists' => [$id],
                'status' => ['subscribed' => 'subscribed', 'unsubscribed' => 'unsubscribed', 'cleaned' => 'bounced'][$m['status'] ?? ''] ?? 'pending'];
        }
        $next = (int) $c['o'] + self::PAGE;
        $total = array_sum(array_map(fn ($x) => $x['members'] + $x['unsubscribed'] + $x['cleaned'], $lists));
        if ($next < (int) ($r['total_items'] ?? 0)) {
            return ['items' => $items, 'cursor' => ['l' => $l, 'o' => $next, 'g' => $groups], 'total' => $total];
        }

        return ['items' => $items, 'cursor' => isset($lists[$l + 1]) ? ['l' => $l + 1, 'o' => 0] : null, 'total' => $total];
    }

    /** @return array{items: list<array<string, mixed>>, cursor: null} */
    private function campaignPage(): array
    {
        $r = $this->get('/campaigns', ['status' => 'sent', 'count' => self::CAMPAIGNS, 'sort_field' => 'send_time', 'sort_dir' => 'DESC', 'fields' => 'campaigns.id,campaigns.settings.subject_line,campaigns.settings.title']);
        $items = [];
        foreach ((array) ($r['campaigns'] ?? []) as $cmp) {
            $content = $this->get('/campaigns/'.$cmp['id'].'/content', ['fields' => 'html']);
            if (! empty($content['html'])) {
                $items[] = ['name' => (string) (($cmp['settings']['title'] ?? '') ?: ($cmp['settings']['subject_line'] ?? 'Mailchimp campaign')), 'subject' => (string) ($cmp['settings']['subject_line'] ?? ''), 'html' => (string) $content['html']];
            }
        }

        return ['items' => $items, 'cursor' => null];
    }

    public function convertMergeTags(string $html): string
    {
        $map = ['FNAME' => '{{ first_name }}', 'LNAME' => '{{ last_name }}', 'EMAIL' => '{{ email }}', 'UNSUB' => '{{unsubscribe_url}}', 'UPDATE_PROFILE' => '{{preferences_url}}', 'ARCHIVE' => '{{web_version_url}}'];
        $html = (string) preg_replace_callback('/\*\|([A-Z0-9_:]+)\|\*/', function ($m) use ($map) {
            if (isset($map[$m[1]])) {
                return $map[$m[1]];
            }
            // A custom merge field (*|COMPANY|*) → its MailVesper field; Mailchimp's own system tags (list address, rewards…) are dropped.
            $key = str_contains($m[1], ':') ? null : MigrationService::fieldKey($m[1]);

            return $key ? '{{ '.$key.' }}' : '';
        }, $html);

        return $html;
    }
}
