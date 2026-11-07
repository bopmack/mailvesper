<?php

namespace App\Services\Connect;

use App\Exceptions\ApiException;
use App\Models\Segment;
use App\Models\SiteConnection;
use App\Models\Workspace;

/**
 * What a connected site brings with it. The "creator_platform" preset (a creator or membership site): the fields the
 * MailVesper package can answer, and ready-made segments to email, grouped under the site's name and read live from the
 * site. Applying it again only adds the segments that are missing: ones someone edited are left as they are.
 */
class Presets
{
    /** key → [label, type]: the package's default fields (dates come as "Y-m-d H:i:s" in UTC). */
    public const CREATOR_FIELDS = [
        'role' => ['Role (creator or fan)', 'text'],
        'email_verified' => ['Email verified on the site', 'boolean'],
        'creator_verified' => ['Verified creator', 'boolean'],
        'account_status' => ['Account status', 'text'],
        'last_login_at' => ['Last login on the site', 'date'],
        'signed_up_at' => ['Signed up on the site', 'date'],
        'active_subscriptions' => ['Active paid subscriptions', 'number'],
        'next_subscription_ends_at' => ['Next paid subscription ends', 'date'],
        'total_spent' => ['Total spent', 'number'],
        'last_purchase_at' => ['Last purchase', 'date'],
        'country' => ['Country', 'text'],
    ];

    /**
     * The ready-made segments: key → {name, rules}.
     *
     * @return array<string, array{name: string, rules: array{match: string, conditions: list<array<string, mixed>>}}>
     */
    private static function creatorSegments(): array
    {
        $f = fn (string $key, string $op, mixed $value = null) => ['field' => 'field:'.$key, 'op' => $op] + ($value === null ? [] : ['value' => $value]);
        $active = $f('account_status', 'is', 'active');
        $segs = [
            'creators' => ['All creators', [$f('role', 'is', 'creator')]],
            'creators_verified' => ['Verified creators', [$f('role', 'is', 'creator'), $f('creator_verified', 'is_true')]],
            'creators_unverified' => ['Unverified creators', [$f('role', 'is', 'creator'), $f('creator_verified', 'is_false')]],
            'fans' => ['All subscribers', [$f('role', 'is', 'fan')]],
            'fans_verified' => ['Verified subscribers (email confirmed)', [$f('role', 'is', 'fan'), $f('email_verified', 'is_true')]],
            'fans_unverified' => ['Unverified subscribers (email not confirmed)', [$f('role', 'is', 'fan'), $f('email_verified', 'is_false')]],
            'fans_no_subscription' => ['Subscribers with no active subscription', [$f('role', 'is', 'fan'), $f('active_subscriptions', 'lte', 0)]],
            'away_1' => ['Not logged in for 24 hours', [$active, $f('last_login_at', 'not_within_days', 1)]],
            'away_2' => ['Not logged in for 2 days', [$active, $f('last_login_at', 'not_within_days', 2)]],
            'away_7' => ['Not logged in for 7 days', [$active, $f('last_login_at', 'not_within_days', 7)]],
            'away_30' => ['Not logged in for 30 days', [$active, $f('last_login_at', 'not_within_days', 30)]],
            'sub_ending_3' => ['Paid subscription ending in 3 days', [$f('next_subscription_ends_at', 'in_next_days', 3)]],
            'sub_ending_7' => ['Paid subscription ending in 7 days', [$f('next_subscription_ends_at', 'in_next_days', 7)]],
            'email_active_site_quiet' => ['Opens your emails, not on the site for 14 days', [$active, ['field' => 'last_opened_at', 'op' => 'within_days', 'value' => 30], $f('last_login_at', 'not_within_days', 14)]],
            'new_7' => ['New sign-ups this week', [$f('signed_up_at', 'within_days', 7)]],
            'big_spenders' => ['Big spenders (100+ spent)', [$f('total_spent', 'gte', 100)]],
        ];

        return array_map(fn ($x) => ['name' => $x[0], 'rules' => ['match' => 'all', 'conditions' => $x[1]]], $segs);
    }

    /**
     * Sets up a connected site as a live audience: its field types (stored on the connection, not as contact fields),
     * and the ready-made segments, read live from the site. Applying it again only adds missing segments and points
     * existing ones at the current connection (a reconnect).
     *
     * @param  array<string, string>  $types  field key → type, from the site's package
     * @return array{fields: list<string>, segments_created: list<string>, segments_skipped: list<string>}
     */
    public function apply(Workspace $ws, string $preset, string $group, SiteConnection $c, array $types = []): array
    {
        if ($preset !== 'creator_platform') {
            throw new ApiException('validation_failed', 'Unknown preset.', 422);
        }
        $group = mb_substr(trim($group), 0, 120) ?: 'Connected site';
        $fields = [];
        foreach (($types ?: array_map(fn ($f) => $f[1], self::CREATOR_FIELDS)) as $key => $type) {
            if (preg_match('/^[a-z][a-z0-9_]{0,39}$/', (string) $key) && in_array($type, SiteAudience::TYPES, true)) {
                $fields[] = ['key' => (string) $key, 'label' => self::CREATOR_FIELDS[$key][0] ?? ucfirst(str_replace('_', ' ', (string) $key)), 'type' => $type];
            }
        }
        $c->forceFill(['fields' => $fields])->save();

        $slug = substr(hash('sha256', mb_strtolower($group)), 0, 10); // one site's presets don't collide with another's
        Segment::where('workspace_id', $ws->id)->where('preset_key', 'like', 'creator_platform:'.$slug.':%')->update(['site_connection_id' => $c->id]);
        $created = [];
        $skipped = [];
        foreach (self::creatorSegments() as $key => $s) {
            $presetKey = 'creator_platform:'.$slug.':'.$key;
            if (Segment::where('workspace_id', $ws->id)->where('preset_key', $presetKey)->exists()) {
                continue;
            }
            try {
                SiteAudience::validate($c, $s['rules']);
            } catch (ApiException) { // the site doesn't have a field this segment needs (or not with that type)
                $skipped[] = $s['name'];

                continue;
            }
            Segment::create(['workspace_id' => $ws->id, 'site_connection_id' => $c->id, 'name' => mb_substr($s['name'], 0, 100), 'group' => $group, 'preset_key' => $presetKey, 'rules' => $s['rules']]);
            $created[] = $s['name'];
        }

        return ['fields' => array_column($fields, 'key'), 'segments_created' => $created, 'segments_skipped' => $skipped];
    }
}
