<?php

namespace App\Services\Journeys;

use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\Contacts\SegmentController;
use App\Models\Contact;
use App\Models\Journey;
use App\Models\JourneyVersion;
use App\Models\Segment;
use App\Services\Contacts\SegmentQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Triggers that come from the passing of time rather than from one event: joined / left a segment, went quiet,
 * inactive in the app, and on a schedule. The scheduler runs these; every start carries a dedupe key, so a scan
 * that runs twice (or after a crashed one) never starts the same run twice.
 */
class TriggerScan
{
    /** Joins and leaves handled per segment per scan; the rest wait for the next scan (every five minutes). */
    public static int $segmentBatch = 2000;

    public function __construct(private JourneyEvents $events, private JourneyEngine $engine) {}

    /**
     * Segment membership: compares who matches each watched segment now with who matched last time, and fires
     * contact.segment_joined / contact.segment_left. The first scan of a segment (or of new rules) only records the
     * members, so turning a journey on never floods it with everyone already in the segment.
     */
    public function segments(): int
    {
        $watched = [];
        foreach ($this->live(['segment_joined', 'segment_left']) as [, , $t]) {
            $watched[(string) $t['segment_id']] = true;
        }
        // Nothing watches these any more: forget the members, so a journey turned on later starts from today.
        foreach (Segment::whereNotNull('tracked_hash')->whereNotIn('id', array_keys($watched))->pluck('id') as $id) {
            DB::table('segment_members')->where('segment_id', $id)->delete();
            Segment::whereKey($id)->update(['tracked_hash' => null, 'tracked_at' => null]);
        }
        $n = 0;
        foreach (Segment::whereIn('id', array_keys($watched))->get() as $s) {
            try {
                $n += $this->segment($s);
            } catch (ApiException $e) { // rules that no longer validate (a custom field was deleted, say)
                Log::warning('Segment can’t be tracked for journeys', ['segment' => $s->id, 'error' => $e->getMessage()]);
            }
        }

        return $n;
    }

    private function segment(Segment $s): int
    {
        $rules = $s->rules;
        $hash = hash('sha256', (string) json_encode($rules));
        $types = SegmentController::customTypes($s->workspace_id);
        $matching = fn (): Builder => (new SegmentQuery($types))->apply(Contact::query()->where('contacts.workspace_id', $s->workspace_id), $rules);
        if ($s->tracked_hash !== $hash) {
            DB::transaction(function () use ($s, $matching, $hash) {
                DB::table('segment_members')->where('segment_id', $s->id)->delete();
                DB::table('segment_members')->insertUsing(['segment_id', 'contact_id', 'joined_at'],
                    $matching()->toBase()->selectRaw('?, contacts.id, ?', [$s->id, now()->toDateTimeString()]));
                $s->forceFill(['tracked_hash' => $hash, 'tracked_at' => now()])->save();
            });

            return 0;
        }

        $n = 0;
        $props = ['segment_id' => $s->id, 'segment' => $s->name];
        $joined = $matching()->whereNotExists(fn ($w) => $w->select(DB::raw(1))->from('segment_members')
            ->where('segment_members.segment_id', $s->id)->whereColumn('segment_members.contact_id', 'contacts.id'))->limit(self::$segmentBatch)->get();
        foreach ($joined as $c) {
            if (DB::table('segment_members')->insertOrIgnore(['segment_id' => $s->id, 'contact_id' => $c->id, 'joined_at' => now()])) {
                $this->events->fire($c, 'contact.segment_joined', $props, 'system');
                $n++;
            }
        }
        $left = DB::table('segment_members')->where('segment_id', $s->id)->whereNotIn('contact_id', $matching()->toBase()->select('contacts.id'))
            ->limit(self::$segmentBatch)->pluck('contact_id');
        foreach ($left as $id) {
            if (DB::table('segment_members')->where('segment_id', $s->id)->where('contact_id', $id)->delete() && ($c = Contact::find($id))) {
                $this->events->fire($c, 'contact.segment_left', $props, 'system');
                $n++;
            }
        }
        $s->forceFill(['tracked_at' => now()])->save();

        return $n;
    }

    /**
     * Went quiet: still getting your emails (one sent in the last N days) but no real open or click in N days.
     * Starts once per quiet stretch: someone who engages again and then goes quiet again can start again.
     */
    public function quiet(): int
    {
        $n = 0;
        foreach ($this->live(['went_quiet']) as [$j, $v, $t]) {
            $since = now()->subDays((int) $t['days'])->toDateTimeString();
            $node = (string) $t['node'];
            Contact::where('workspace_id', $j->workspace_id)->where('status', 'subscribed')
                ->where('created_at', '<', $since)->where('last_sent_at', '>=', $since)
                ->where(fn ($w) => $w->whereNull('last_opened_at')->orWhere('last_opened_at', '<', $since))
                ->where(fn ($w) => $w->whereNull('last_clicked_at')->orWhere('last_clicked_at', '<', $since))
                // not already started for this quiet stretch
                ->whereNotExists(fn ($r) => $this->runFor($r, $j, $node)
                    ->where(fn ($x) => $x->whereNull('contacts.last_opened_at')->orWhereColumn('journey_runs.entered_at', '>', 'contacts.last_opened_at'))
                    ->where(fn ($x) => $x->whereNull('contacts.last_clicked_at')->orWhereColumn('journey_runs.entered_at', '>', 'contacts.last_clicked_at')))
                ->chunkById(1000, function (Collection $contacts) use ($j, $v, $t, $node, &$n) {
                    foreach ($contacts as $c) {
                        $last = collect([$c->last_opened_at, $c->last_clicked_at])->filter()->max();
                        $context = ['event' => 'sendline.went_quiet', 'properties' => ['days' => (int) $t['days'], 'last_engaged_at' => $last?->toIso8601ZuluString()]];
                        if ($this->events->enroll($j, $v, $c, $node, $context, "$node:{$c->id}:".($last ? $last->timestamp : 'never'), $t['filter'] ?? null)) {
                            $n++;
                        }
                    }
                });
        }

        return $n;
    }

    /**
     * Inactive in the app: did the activity event before (app_opened, say) but not in the last N days.
     * Once per inactive stretch, like "went quiet".
     */
    public function inactive(): int
    {
        $n = 0;
        foreach ($this->live(['inactive']) as [$j, $v, $t]) {
            $since = now()->subDays((int) $t['days'])->toDateTimeString();
            $node = (string) $t['node'];
            $activity = (string) $t['activity'];
            $event = fn ($q) => $q->select(DB::raw(1))->from('contact_events')->whereColumn('contact_events.contact_id', 'contacts.id')->where('contact_events.name', $activity);
            $lastSeen = fn ($q) => $q->from('contact_events')->selectRaw('max(occurred_at)')->whereColumn('contact_events.contact_id', 'contacts.id')->where('contact_events.name', $activity);
            Contact::query()->select('contacts.*')->selectSub($lastSeen, 'last_seen_at')
                ->where('workspace_id', $j->workspace_id)->where('status', 'subscribed')
                ->whereExists(fn ($q) => $event($q)->where('contact_events.occurred_at', '<', $since))
                ->whereNotExists(fn ($q) => $event($q)->where('contact_events.occurred_at', '>=', $since))
                ->whereNotExists(fn ($r) => $this->runFor($r, $j, $node)->where('journey_runs.entered_at', '>', $lastSeen))
                ->chunkById(1000, function (Collection $contacts) use ($j, $v, $t, $node, $activity, &$n) {
                    foreach ($contacts as $c) {
                        $last = Carbon::parse((string) $c->getAttribute('last_seen_at'), 'UTC');
                        $context = ['event' => 'sendline.inactive', 'properties' => ['activity' => $activity, 'days' => (int) $t['days'], 'last_seen_at' => $last->toIso8601ZuluString()]];
                        if ($this->events->enroll($j, $v, $c, $node, $context, "$node:{$c->id}:{$last->timestamp}", $t['filter'] ?? null)) {
                            $n++;
                        }
                    }
                }, 'contacts.id', 'id');
        }

        return $n;
    }

    /**
     * On a schedule: every day, week (on a weekday) or month (on a day; the 31st means the month's last day), at
     * an hour in each contact's time zone. Everyone subscribed who passes the trigger's filter enters.
     */
    public function schedule(): int
    {
        $n = 0;
        foreach ($this->live(['schedule']) as [$j, $v, $t]) {
            $node = (string) $t['node'];
            Contact::where('workspace_id', $j->workspace_id)->where('status', 'subscribed')->chunkById(1000, function (Collection $contacts) use ($j, $v, $t, $node, &$n) {
                foreach ($contacts as $c) {
                    $local = now()->setTimezone($this->engine->timezone($c, $j));
                    if (! self::due($t, $local)) {
                        continue;
                    }
                    $context = ['event' => 'sendline.schedule', 'properties' => ['date' => $local->toDateString()]];
                    if ($this->events->enroll($j, $v, $c, $node, $context, "$node:{$c->id}:{$local->toDateString()}", $t['filter'] ?? null)) {
                        $n++;
                    }
                }
            });
        }

        return $n;
    }

    /** @param array<string, mixed> $t a compiled schedule trigger */
    public static function due(array $t, Carbon $local): bool
    {
        if ($local->hour !== (int) $t['hour']) {
            return false;
        }

        return match ($t['every']) {
            'week' => $local->dayOfWeekIso === (int) $t['weekday'],
            'month' => $local->day === min((int) $t['monthday'], $local->daysInMonth),
            default => true,
        };
    }

    /** The runs this trigger step already started for the contact in the outer query. */
    private function runFor(\Illuminate\Database\Query\Builder $r, Journey $j, string $node): \Illuminate\Database\Query\Builder
    {
        return $r->select(DB::raw(1))->from('journey_runs')->where('journey_runs.journey_id', $j->id)
            ->where('journey_runs.trigger_node', $node)->whereColumn('journey_runs.contact_id', 'contacts.id');
    }

    /**
     * Live journeys' triggers of the given kinds.
     *
     * @param  list<string>  $kinds
     * @return \Generator<int, array{0: Journey, 1: JourneyVersion, 2: array<string, mixed>}>
     */
    private function live(array $kinds): \Generator
    {
        foreach (Journey::where('status', 'active')->whereNotNull('live_version_id')->get() as $j) {
            $v = JourneyVersion::find($j->live_version_id);
            foreach ($v->triggers ?? [] as $t) {
                if (in_array($t['trigger'] ?? null, $kinds, true)) {
                    yield [$j, $v, $t];
                }
            }
        }
    }
}
