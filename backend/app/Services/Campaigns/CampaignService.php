<?php

namespace App\Services\Campaigns;

use App\Exceptions\ApiException;
use App\Jobs\SendCampaignBatch;
use App\Jobs\StartCampaign;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\ContactList;
use App\Models\Segment;
use App\Models\SiteConnection;
use App\Models\Workspace;
use App\Services\Connect\SiteAudience;
use App\Services\Contacts\SegmentQuery;
use App\Services\Design\DocValidator;
use App\Services\Design\EmailRenderer;
use App\Services\Sending\Senders;
use App\Support\Edition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Campaign lifecycle: audience, pre-send checks, scheduling, pause/resume/cancel and the A/B winner. */
class CampaignService
{
    public function __construct(private DocValidator $validator, private EmailRenderer $renderer) {}

    /**
     * Subscribed contacts in the campaign's audience (dormant ones left out when asked).
     *
     * @return Builder<Contact>
     */
    public function audience(Campaign $c): Builder
    {
        $q = Contact::where('workspace_id', $c->workspace_id)->where('status', 'subscribed');
        if ($c->audience_type === 'list') {
            $q->whereExists(fn ($s) => $s->select(DB::raw(1))->from('contact_list')->whereColumn('contact_list.contact_id', 'contacts.id')->where('list_id', $c->audience_id));
        } elseif ($c->audience_type === 'people') {
            $q->whereIn('id', (array) ($c->audience_contacts ?? []));
        } elseif ($c->audience_type === 'segment') {
            $seg = Segment::where('workspace_id', $c->workspace_id)->find($c->audience_id) ?? throw new ApiException('not_found', 'The segment for this campaign no longer exists.', 422);
            $types = DB::table('contact_fields')->where('workspace_id', $c->workspace_id)->pluck('type', 'key')->all();
            (new SegmentQuery($types))->apply($q, $seg->rules);
        }
        if ($c->exclude_dormant) {
            $q->where('dormant', false);
        }

        return $q;
    }

    /**
     * The live site and rules when the campaign goes to a connected site's segment (read from the site when it
     * starts, never stored as contacts); null for contacts.
     *
     * @return array{0: SiteConnection, 1: array<string, mixed>}|null
     */
    public function siteAudience(Campaign $c): ?array
    {
        if ($c->audience_type !== 'segment') {
            return null;
        }
        $seg = Segment::where('workspace_id', $c->workspace_id)->find($c->audience_id);
        if (! $seg) {
            return null; // gone: audience() says so
        }
        $site = SiteAudience::forSegment($seg);

        return $site ? [$site, $seg->rules] : null;
    }

    /** @return array{total: int, dormant: int} */
    public function estimate(Campaign $c): array
    {
        if (! $c->audience_type) {
            return ['total' => 0, 'dormant' => 0];
        }
        if ($live = $this->siteAudience($c)) {
            return ['total' => SiteAudience::counts($live[0], ['a' => $live[1]], true)['a'] ?? 0, 'dormant' => 0]; // only who agreed to marketing
        }
        $withDormant = clone $c;
        $withDormant->exclude_dormant = false;

        return ['total' => $this->audience($c)->count(), 'dormant' => $this->audience($withDormant)->where('dormant', true)->count()];
    }

    /** Renders the design into the campaign (same renderer as the builder). */
    public function renderDoc(Campaign $c, mixed $doc): void
    {
        $doc = $this->validator->validate($doc);
        if (is_string($c->preheader) && $c->preheader !== '') {
            $doc['settings']['preheader'] = $c->preheader;
        }
        $r = $this->renderer->render($doc, (string) $c->subject);
        $c->fill(['doc' => $doc, 'html' => $r['html'], 'size_kb' => $r['kb'], 'checks' => $r['checks']]);
    }

    /**
     * What's missing before this campaign can go out. Empty when ready.
     *
     * @return list<array{key: string, message: string}>
     */
    public function problems(Campaign $c): array
    {
        $p = [];
        $method = Senders::method($c->workspace_id);
        if ($method === null) {
            $p[] = ['key' => 'ses', 'message' => 'Connect Amazon SES or your own SMTP server first.'];
        }
        if (! $c->audience_type) {
            $p[] = ['key' => 'audience', 'message' => 'Choose who gets this campaign.'];
        } elseif ($c->audience_type === 'list' && ! ContactList::where('workspace_id', $c->workspace_id)->where('id', $c->audience_id)->exists()) {
            $p[] = ['key' => 'audience', 'message' => 'The list for this campaign no longer exists.'];
        } elseif ($c->audience_type === 'people' && ! $c->audience_contacts) {
            $p[] = ['key' => 'audience', 'message' => 'Add at least one person.'];
        } else {
            try {
                if ($this->estimate($c)['total'] === 0) {
                    $p[] = ['key' => 'audience', 'message' => 'Nobody in this audience can be emailed right now.'];
                }
            } catch (ApiException $e) { // a connected site that doesn't answer, or a segment that's gone
                $p[] = ['key' => 'audience', 'message' => $e->getMessage()];
            }
        }
        if (! $c->from_email) {
            $p[] = ['key' => 'from', 'message' => 'Choose who it’s from.'];
        } elseif (! Senders::allows($c->workspace_id, $c->from_email)) {
            $p[] = ['key' => 'from', 'message' => $method === Senders::SMTP ? 'The sender address isn’t verified. Verify it under Sending → Senders.' : 'The sender address isn’t on a verified domain.'];
        }
        if (! $c->subject || trim($c->subject) === '') {
            $p[] = ['key' => 'subject', 'message' => 'Write a subject line.'];
        }
        if ($c->ab_enabled && (! $c->subject_b || trim($c->subject_b) === '')) {
            $p[] = ['key' => 'subject', 'message' => 'Write subject B for the A/B test, or turn the test off.'];
        }
        if (! $c->html) {
            $p[] = ['key' => 'content', 'message' => 'Design the email.'];
        } elseif (! preg_match('/\{\{\s*(unsubscribe_url|preferences_url)/', $c->html)) {
            $p[] = ['key' => 'content', 'message' => 'Add an unsubscribe link (the Footer block has one). Marketing email must have it.'];
        }

        return $p;
    }

    /**
     * Sends now, at a time, or at a local time in each recipient's zone.
     *
     * @param  array{mode: string, at?: string|null, date?: string|null, time?: string|null, timezone?: string|null}  $when
     */
    public function schedule(Campaign $c, array $when): Campaign
    {
        // Plan limits (Pro SaaS): refuse up front rather than stopping halfway through the send.
        $ws = Workspace::findOrFail($c->workspace_id);
        $room = app(Edition::class)->room($ws, 'emails');
        if ($room !== null && ($n = $this->estimate($c)['total']) > $room) {
            app(Edition::class)->assertRoom($ws, 'emails', $n);
        }

        if (! in_array($c->status, Campaign::EDITABLE, true)) {
            throw new ApiException('not_editable', 'This campaign has already started.', 422);
        }
        if ($problems = $this->problems($c)) {
            throw new ApiException('not_ready', $problems[0]['message'], 422, ['ready' => $problems[0]['key']]);
        }
        $tz = $when['timezone'] ?? 'UTC';
        if (! in_array($tz, timezone_identifiers_list(), true)) {
            throw new ApiException('validation_failed', 'Unknown time zone.', 422, ['timezone' => 'Unknown time zone.']);
        }
        switch ($when['mode']) {
            case 'now':
                $c->fill(['send_mode' => 'now', 'scheduled_at' => null, 'status' => 'sending']);
                break;
            case 'scheduled':
                $at = $this->parseTime((string) ($when['at'] ?? ''), $tz);
                $c->fill(['send_mode' => 'scheduled', 'scheduled_at' => $at, 'timezone' => $tz, 'status' => 'scheduled']);
                break;
            case 'local':
                if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($when['date'] ?? '')) || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) ($when['time'] ?? ''))) {
                    throw new ApiException('validation_failed', 'Pick a date and a time.', 422, ['time' => 'Pick a date and a time.']);
                }
                // Starts when the earliest time zone (UTC+14) reaches that local time.
                $earliest = Carbon::parse($when['date'].' '.$when['time'], 'Pacific/Kiritimati')->utc();
                if (Carbon::parse($when['date'].' '.$when['time'], 'Etc/GMT+12')->utc()->lt(now())) {
                    throw new ApiException('validation_failed', 'That time has already passed everywhere.', 422, ['time' => 'Pick a later date.']);
                }
                $c->fill(['send_mode' => 'local', 'local_date' => $when['date'], 'local_time' => $when['time'], 'timezone' => $tz, 'scheduled_at' => $earliest->lt(now()) ? now() : $earliest, 'status' => 'scheduled']);
                break;
            default:
                throw new ApiException('validation_failed', 'Choose when to send.', 422);
        }
        $c->save();
        if ($c->status === 'sending') {
            $this->dispatchStart($c);
        }

        return $c;
    }

    private function parseTime(string $at, string $tz): Carbon
    {
        try {
            $t = Carbon::parse($at, $tz)->utc();
        } catch (\Throwable) {
            throw new ApiException('validation_failed', 'Pick a valid date and time.', 422, ['at' => 'Pick a valid date and time.']);
        }
        if ($t->lt(now()->subMinute())) {
            throw new ApiException('validation_failed', 'That time has passed. Pick a time in the future.', 422, ['at' => 'In the future.']);
        }
        if ($t->gt(now()->addDays(365))) {
            throw new ApiException('validation_failed', 'Schedule up to a year ahead.', 422, ['at' => 'Up to a year ahead.']);
        }

        return $t;
    }

    public function dispatchStart(Campaign $c): void
    {
        app()->runningInConsole() ? StartCampaign::dispatch($c->id) : StartCampaign::dispatchAfterResponse($c->id);
    }

    /** Back to draft (only before it starts). */
    public function unschedule(Campaign $c): void
    {
        if ($c->status !== 'scheduled') {
            throw new ApiException('not_scheduled', 'Only a scheduled campaign can go back to draft.', 422);
        }
        $c->forceFill(['status' => 'draft', 'scheduled_at' => null])->save();
    }

    /** Stops sending; emails not yet handed to SES are pulled back so pausing takes effect at once. */
    public function pause(Campaign $c): void
    {
        if ($c->status !== 'sending') {
            throw new ApiException('not_sending', 'Only a sending campaign can be paused.', 422);
        }
        $c->forceFill(['status' => 'paused', 'paused_at' => now(), 'status_note' => null])->save();
        $this->pullBack($c, 'pending');
    }

    public function resume(Campaign $c): void
    {
        if ($c->status !== 'paused') {
            throw new ApiException('not_paused', 'Only a paused campaign can be resumed.', 422);
        }
        $c->forceFill(['status' => 'sending', 'paused_at' => null, 'status_note' => null])->save();
        // Paused before its audience was read (a connected site didn't answer): read it now.
        DB::table('campaign_recipients')->where('campaign_id', $c->id)->exists() ? SendCampaignBatch::dispatch($c->id) : StartCampaign::dispatch($c->id);
    }

    public function cancel(Campaign $c): void
    {
        if (! in_array($c->status, ['scheduled', 'sending', 'paused'], true)) {
            throw new ApiException('not_cancellable', 'This campaign can’t be cancelled.', 422);
        }
        $c->forceFill(['status' => 'cancelled', 'finished_at' => now()])->save();
        $this->pullBack($c, 'cancelled');
        DB::table('campaign_recipients')->where('campaign_id', $c->id)->where('status', 'pending')->update(['status' => 'cancelled']);
        DB::table('campaign_recipients')->where('campaign_id', $c->id)->whereNotNull('vars')->update(['vars' => null]); // a site's people: nothing kept
    }

    /** Deletes this campaign's messages still waiting in the queue and returns their recipients to $status. */
    private function pullBack(Campaign $c, string $status): void
    {
        $ids = DB::table('messages')->where('campaign_id', $c->id)->where('status', 'queued')->where('attempts', 0)->pluck('id')->all();
        foreach (array_chunk($ids, 500) as $chunk) {
            $gone = DB::table('messages')->whereIn('id', $chunk)->where('status', 'queued')->where('attempts', 0)->pluck('id')->all();
            DB::table('message_events')->whereIn('message_id', $gone)->delete();
            DB::table('messages')->whereIn('id', $gone)->where('status', 'queued')->delete();
            DB::table('campaign_recipients')->where('campaign_id', $c->id)->whereIn('message_id', $gone)->update(['status' => $status, 'message_id' => null]);
        }
    }

    /** A/B: compare the two test groups on real opens (or clicks) and send the rest the winner. */
    public function decideWinner(Campaign $c): string
    {
        $col = $c->ab_metric === 'clicks' ? 'clicked_at' : 'opened_at';
        $rate = fn (string $v) => (function ($r) {
            return (int) $r->delivered > 0 ? (int) $r->engaged / (int) $r->delivered : 0.0;
        })(DB::table('messages')->where('campaign_id', $c->id)->where('variant', $v)
            ->selectRaw("sum(case when delivered_at is not null then 1 else 0 end) as delivered, sum(case when $col is not null then 1 else 0 end) as engaged")->first());
        $winner = $rate('b') > $rate('a') ? 'b' : 'a'; // ties go to A
        DB::transaction(function () use ($c, $winner) {
            $c->forceFill(['ab_winner' => $winner, 'ab_decided_at' => now()])->save();
            DB::table('campaign_recipients')->where('campaign_id', $c->id)->whereNull('variant')->where('status', 'pending')->update(['variant' => $winner, 'send_at' => now()]);
        });

        return $winner;
    }
}
