<?php

namespace App\Services\Contacts;

use App\Exceptions\ApiException;
use App\Models\Contact;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Segment rules → SQL. Rules: {"match": "all"|"any", "conditions": [{"field", "op", "value"}]}.
 * Fields are built-ins, "tag", "list", "field:<custom key>", or "event:<name>" (did / didn't do it in the last
 * `value` days, from the contact's event history). Every value is bound, never interpolated.
 */
class SegmentQuery
{
    public const MAX_CONDITIONS = 20;

    /** Built-in field → type. */
    public const FIELDS = [
        'email' => 'text', 'first_name' => 'text', 'last_name' => 'text', 'status' => 'enum', 'source' => 'enum',
        'created_at' => 'date', 'last_sent_at' => 'date', 'last_opened_at' => 'date', 'last_clicked_at' => 'date',
        'engagement' => 'number', 'dormant' => 'boolean', 'tag' => 'tag', 'list' => 'list',
    ];

    public const OPS = [
        'text' => ['is', 'is_not', 'contains', 'not_contains', 'starts_with', 'ends_with', 'is_set', 'is_not_set'],
        'number' => ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'is_set', 'is_not_set'],
        'date' => ['before', 'after', 'within_days', 'not_within_days', 'in_next_days', 'is_set', 'is_not_set'],
        'boolean' => ['is_true', 'is_false'],
        'enum' => ['is', 'is_not'],
        'tag' => ['has', 'has_not'],
        'list' => ['in', 'not_in'],
        'event' => ['performed', 'not_performed'],
    ];

    /** @param array<string, string> $customTypes key → type for this workspace's custom fields */
    public function __construct(private array $customTypes = []) {}

    /**
     * @param  Builder<Contact>  $q
     * @param  array<string, mixed>  $rules
     * @return Builder<Contact>
     */
    public function apply(Builder $q, array $rules): Builder
    {
        $conds = $this->validate($rules);
        if (! $conds) {
            return $q;
        }
        $any = ($rules['match'] ?? 'all') === 'any';

        return $q->where(function (Builder $w) use ($conds, $any) {
            foreach ($conds as $c) {
                $any ? $w->orWhere(fn (Builder $x) => $this->one($x, $c)) : $w->where(fn (Builder $x) => $this->one($x, $c));
            }
        });
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return list<array{field: string, op: string, value: mixed, type: string, column: string}>
     */
    public function validate(array $rules): array
    {
        $conds = $rules['conditions'] ?? [];
        if (! is_array($conds) || count($conds) > self::MAX_CONDITIONS || ! in_array($rules['match'] ?? 'all', ['all', 'any'], true)) {
            throw new ApiException('invalid_rules', 'A segment can have up to '.self::MAX_CONDITIONS.' conditions, matching all or any.', 422);
        }
        $out = [];
        foreach (array_values($conds) as $i => $c) {
            $field = is_array($c) ? (string) ($c['field'] ?? '') : '';
            $op = is_array($c) ? (string) ($c['op'] ?? '') : '';
            if (str_starts_with($field, 'event:')) {
                $name = substr($field, 6);
                if (! preg_match('/^[A-Za-z0-9_.:-]{1,100}$/', $name)) {
                    throw new ApiException('invalid_rules', 'Condition '.($i + 1).' needs an event name.', 422);
                }
                $type = 'event';
                $column = $name;
            } elseif (str_starts_with($field, 'field:')) {
                $key = substr($field, 6);
                if (! ContactService::validKey($key)) {
                    throw new ApiException('invalid_rules', 'Condition '.($i + 1).' uses an unknown field.', 422);
                }
                $type = $this->customTypes[$key] ?? 'text';
                $column = 'fields->'.$key;
            } else {
                $type = self::FIELDS[$field] ?? null;
                $column = $field;
            }
            if (! $type || ! in_array($op, self::OPS[$type], true)) {
                throw new ApiException('invalid_rules', 'Condition '.($i + 1).' isn’t complete. Pick a field and how to compare it.', 422);
            }
            $value = $c['value'] ?? null;
            $needsValue = ! in_array($op, ['is_set', 'is_not_set', 'is_true', 'is_false'], true);
            if ($needsValue && ($value === null || $value === '' || is_array($value))) {
                throw new ApiException('invalid_rules', 'Condition '.($i + 1).' needs a value.', 422);
            }
            if ($needsValue && ($type === 'event' || in_array($op, ['within_days', 'not_within_days', 'in_next_days'], true)) && (! is_numeric($value) || (int) $value < 1 || (int) $value > 3650)) {
                throw new ApiException('invalid_rules', 'Condition '.($i + 1).': enter a number of days between 1 and 3650.', 422);
            }
            if ($needsValue && in_array($op, ['before', 'after'], true) && ! strtotime((string) $value)) {
                throw new ApiException('invalid_rules', 'Condition '.($i + 1).': enter a date like 2026-10-01.', 422);
            }
            if ($needsValue && $type === 'number' && ! is_numeric($value)) {
                throw new ApiException('invalid_rules', 'Condition '.($i + 1).' needs a number.', 422);
            }
            $out[] = ['field' => $field, 'op' => $op, 'value' => $value, 'type' => $type, 'column' => $column];
        }

        return $out;
    }

    /**
     * @param  Builder<Contact>  $q
     * @param  array{field: string, op: string, value: mixed, type: string, column: string}  $c
     */
    private function one(Builder $q, array $c): void
    {
        $col = $c['column'];
        $v = $c['value'];
        $like = fn (string $s) => addcslashes(mb_strtolower($s), '%_\\');
        switch ($c['type'].':'.$c['op']) {
            case 'text:is': case 'enum:is': $q->where($col, $c['type'] === 'text' && in_array($col, ['email'], true) ? mb_strtolower((string) $v) : $v);
                break;
            case 'text:is_not': case 'enum:is_not': $q->where(fn ($w) => $w->where($col, '!=', $v)->orWhereNull($col));
                break;
            case 'text:contains': $q->where($col, 'like', '%'.$like((string) $v).'%');
                break;
            case 'text:not_contains': $q->where(fn ($w) => $w->where($col, 'not like', '%'.$like((string) $v).'%')->orWhereNull($col));
                break;
            case 'text:starts_with': $q->where($col, 'like', $like((string) $v).'%');
                break;
            case 'text:ends_with': $q->where($col, 'like', '%'.$like((string) $v));
                break;
            case 'number:eq': $q->where($col, '=', $v + 0);
                break;
            case 'number:neq': $q->where(fn ($w) => $w->where($col, '!=', $v + 0)->orWhereNull($col));
                break;
            case 'number:gt': $q->where($col, '>', $v + 0);
                break;
            case 'number:gte': $q->where($col, '>=', $v + 0);
                break;
            case 'number:lt': $q->where($col, '<', $v + 0);
                break;
            case 'number:lte': $q->where($col, '<=', $v + 0);
                break;
            case 'date:before': $q->where($col, '<', Carbon::parse((string) $v)->startOfDay()->toDateTimeString());
                break;
            case 'date:after': $q->where($col, '>', Carbon::parse((string) $v)->endOfDay()->toDateTimeString());
                break;
            case 'date:within_days': $q->where($col, '>=', now()->subDays((int) $v)->toDateTimeString());
                break;
            case 'date:not_within_days': $q->where(fn ($w) => $w->where($col, '<', now()->subDays((int) $v)->toDateTimeString())->orWhereNull($col));
                break;
            case 'date:in_next_days': // still to come, within N days (a subscription that ends soon)
                $q->where($col, '>=', now()->toDateTimeString())->where($col, '<=', now()->addDays((int) $v)->toDateTimeString());
                break;
            case 'boolean:is_true': $q->where($col, true);
                break;
            case 'boolean:is_false': $q->where(fn ($w) => $w->where($col, false)->orWhereNull($col));
                break;
            case 'tag:has': $q->whereExists(fn ($s) => $s->select(DB::raw(1))->from('contact_tags')->whereColumn('contact_tags.contact_id', 'contacts.id')->where('tag', mb_strtolower(trim((string) $v))));
                break;
            case 'tag:has_not': $q->whereNotExists(fn ($s) => $s->select(DB::raw(1))->from('contact_tags')->whereColumn('contact_tags.contact_id', 'contacts.id')->where('tag', mb_strtolower(trim((string) $v))));
                break;
            case 'list:in': $q->whereExists(fn ($s) => $s->select(DB::raw(1))->from('contact_list')->whereColumn('contact_list.contact_id', 'contacts.id')->where('list_id', (string) $v));
                break;
            case 'list:not_in': $q->whereNotExists(fn ($s) => $s->select(DB::raw(1))->from('contact_list')->whereColumn('contact_list.contact_id', 'contacts.id')->where('list_id', (string) $v));
                break;
            case 'event:performed':
            case 'event:not_performed':
                $exists = fn ($s) => $s->select(DB::raw(1))->from('contact_events')->whereColumn('contact_events.contact_id', 'contacts.id')->where('contact_events.name', $col)
                    ->where('contact_events.occurred_at', '>=', now()->subDays((int) $v)->toDateTimeString());
                $c['op'] === 'performed' ? $q->whereExists($exists) : $q->whereNotExists($exists);
                break;
            default: // is_set / is_not_set for any type
                $c['op'] === 'is_set' ? $q->whereNotNull($col) : $q->whereNull($col);
        }
    }
}
