<?php

namespace App\Services\Journeys;

use App\Exceptions\ApiException;
use App\Models\Contact;
use App\Services\Contacts\SegmentQuery;
use Illuminate\Support\Facades\DB;

/**
 * If/else rules and trigger filters. Contact fields, tags and lists use the segment rules (same operators as
 * Segments); journeys add "did an event", "opened / clicked an email in this journey", and "data": any value
 * the trigger or an earlier step produced ({"field": "data", "path": "steps.n1.body.plan", "op": "is", "value": "pro"}).
 * Rules: {"match": "all"|"any", "conditions": [{"field", "op", "value", "days"?, "path"?}]}.
 */
class Conditions
{
    public const EVENT_OPS = ['performed', 'not_performed'];

    public const EMAIL_OPS = ['opened', 'clicked', 'not_opened', 'not_clicked'];

    public const DATA_OPS = ['is', 'is_not', 'contains', 'not_contains', 'gt', 'gte', 'lt', 'lte', 'is_set', 'is_not_set', 'is_true', 'is_false'];

    /** A Switch step's case comparisons (compare() does them). */
    public const SWITCH_OPS = ['is', 'is_not', 'contains', 'not_contains', 'gt', 'gte', 'lt', 'lte', 'is_set', 'is_not_set'];

    /** Ops that compare against nothing. */
    private const DATA_UNARY = ['is_set', 'is_not_set', 'is_true', 'is_false'];

    /**
     * @param  array<string, mixed>  $rules
     * @param  array<string, string>  $customTypes
     * @param  array<string, array<string, mixed>>  $nodes  graph nodes by id
     * @return array{match: string, conditions: list<array<string, mixed>>}
     */
    public static function validate(array $rules, array $customTypes, array $nodes): array
    {
        $conds = $rules['conditions'] ?? null;
        $match = ($rules['match'] ?? 'all') === 'any' ? 'any' : 'all';
        if (! is_array($conds) || ! $conds || count($conds) > SegmentQuery::MAX_CONDITIONS) {
            throw new ApiException('incomplete', 'add between 1 and '.SegmentQuery::MAX_CONDITIONS.' conditions.', 422);
        }
        $out = [];
        foreach (array_values($conds) as $i => $c) {
            $c = is_array($c) ? $c : [];
            $field = (string) ($c['field'] ?? '');
            $op = (string) ($c['op'] ?? '');
            $value = $c['value'] ?? null;
            $n = $i + 1;
            if ($field === 'data') {
                $path = (string) ($c['path'] ?? '');
                if (! preg_match('/^(contact|trigger|steps)(\.[A-Za-z0-9_-]{1,64}){1,8}$/', $path)) {
                    throw new ApiException('incomplete', "condition $n: choose a value from the data (drag one in from the input).", 422);
                }
                if (! in_array($op, self::DATA_OPS, true)) {
                    throw new ApiException('incomplete', "condition $n: choose how to compare it.", 422);
                }
                $unary = in_array($op, self::DATA_UNARY, true);
                if (! $unary && (! is_scalar($value) || mb_strlen((string) $value) > 255)) {
                    throw new ApiException('incomplete', "condition $n: enter the value to compare with.", 422);
                }
                if (in_array($op, ['gt', 'gte', 'lt', 'lte'], true) && ! is_numeric($value)) {
                    throw new ApiException('incomplete', "condition $n: compare with a number.", 422);
                }
                $out[] = ['field' => 'data', 'path' => $path, 'op' => $op, 'value' => $unary ? null : (string) $value];
            } elseif ($field === 'event') {
                if (! in_array($op, self::EVENT_OPS, true) || ! is_string($value) || (! JourneyGraph::validEvent($value) && ! in_array($value, ['email.opened', 'email.clicked'], true))) {
                    throw new ApiException('incomplete', "condition $n needs an event name.", 422);
                }
                $days = isset($c['days']) && $c['days'] !== '' ? (int) $c['days'] : null;
                if ($days !== null && ($days < 1 || $days > 3650)) {
                    throw new ApiException('incomplete', "condition $n: days must be between 1 and 3650.", 422);
                }
                $out[] = ['field' => 'event', 'op' => $op, 'value' => $value, 'days' => $days];
            } elseif ($field === 'journey_email') {
                if (! in_array($op, self::EMAIL_OPS, true) || ! is_string($value) || (($nodes[$value]['type'] ?? null) !== 'email')) {
                    throw new ApiException('incomplete', "condition $n: choose an email step of this journey.", 422);
                }
                $out[] = ['field' => 'journey_email', 'op' => $op, 'value' => $value];
            } else {
                try {
                    (new SegmentQuery($customTypes))->validate(['match' => 'all', 'conditions' => [$c]]);
                } catch (ApiException) {
                    throw new ApiException('incomplete', "condition $n isn’t complete. Pick a field, how to compare it, and a value.", 422);
                }
                $out[] = ['field' => $field, 'op' => $op, 'value' => $value];
            }
        }

        return ['match' => $match, 'conditions' => $out];
    }

    /**
     * @param  array{match: string, conditions: list<array<string, mixed>>}  $rules
     * @param  array<string, string>  $customTypes
     * @param  array<string, mixed>|null  $data  the run's data (JourneyData::input), for "data" rules
     */
    public static function test(Contact $contact, string $journeyId, array $rules, array $customTypes, ?array $data = null): bool
    {
        $any = $rules['match'] === 'any';
        foreach ($rules['conditions'] as $c) {
            $ok = self::one($contact, $journeyId, $c, $customTypes, $data);
            if ($any && $ok) {
                return true;
            }
            if (! $any && ! $ok) {
                return false;
            }
        }

        return ! $any;
    }

    /**
     * Each condition's own result, for the editor's step output.
     *
     * @param  array{match: string, conditions: list<array<string, mixed>>}  $rules
     * @param  array<string, string>  $customTypes
     * @param  array<string, mixed>|null  $data
     * @return list<bool>
     */
    public static function explain(Contact $contact, string $journeyId, array $rules, array $customTypes, ?array $data = null): array
    {
        return array_map(fn ($c) => self::one($contact, $journeyId, $c, $customTypes, $data), $rules['conditions']);
    }

    /** One "data" rule against a value. */
    public static function compare(mixed $v, string $op, ?string $value): bool
    {
        $str = is_bool($v) ? ($v ? 'true' : 'false') : (is_scalar($v) ? (string) $v : ($v === null ? '' : (string) json_encode($v)));
        $num = fn () => is_numeric($v) && is_numeric($value);

        return match ($op) {
            'is_set' => $v !== null && $str !== '',
            'is_not_set' => $v === null || $str === '',
            'is_true' => in_array(mb_strtolower($str), ['true', '1', 'yes'], true),
            'is_false' => in_array(mb_strtolower($str), ['false', '0', 'no', ''], true),
            'is' => mb_strtolower(trim($str)) === mb_strtolower(trim((string) $value)),
            'is_not' => mb_strtolower(trim($str)) !== mb_strtolower(trim((string) $value)),
            'contains' => (string) $value !== '' && str_contains(mb_strtolower($str), mb_strtolower((string) $value)),
            'not_contains' => (string) $value === '' || ! str_contains(mb_strtolower($str), mb_strtolower((string) $value)),
            'gt' => $num() && (float) $v > (float) $value,
            'gte' => $num() && (float) $v >= (float) $value,
            'lt' => $num() && (float) $v < (float) $value,
            'lte' => $num() && (float) $v <= (float) $value,
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $c
     * @param  array<string, string>  $customTypes
     * @param  array<string, mixed>|null  $data
     */
    private static function one(Contact $contact, string $journeyId, array $c, array $customTypes, ?array $data): bool
    {
        if ($c['field'] === 'data') {
            $data ??= JourneyData::input($contact, null, null); // a trigger filter: only the contact and the event exist

            return self::compare(JourneyData::get($data, (string) $c['path']), (string) $c['op'], $c['value'] ?? null);
        }
        if ($c['field'] === 'event') {
            $hit = DB::table('contact_events')->where('contact_id', $contact->id)->where('name', $c['value'])
                ->when($c['days'] ?? null, fn ($q, $d) => $q->where('occurred_at', '>=', now()->subDays((int) $d)->toDateTimeString()))->exists();

            return $c['op'] === 'performed' ? $hit : ! $hit;
        }
        if ($c['field'] === 'journey_email') {
            $col = in_array($c['op'], ['opened', 'not_opened'], true) ? 'opened_at' : 'clicked_at';
            $hit = DB::table('messages')->where('workspace_id', $contact->workspace_id)->where('journey_id', $journeyId)->where('journey_node', $c['value'])
                ->where('to_email', $contact->email)->whereNotNull($col)->exists();

            return str_starts_with($c['op'], 'not_') ? ! $hit : $hit;
        }
        $q = Contact::query()->whereKey($contact->id);

        return (new SegmentQuery($customTypes))->apply($q, ['match' => 'all', 'conditions' => [$c]])->exists();
    }
}
