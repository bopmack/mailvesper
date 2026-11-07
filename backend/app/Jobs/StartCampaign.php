<?php

namespace App\Jobs;

use App\Exceptions\ApiException;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\SiteConnection;
use App\Services\Campaigns\CampaignService;
use App\Services\Connect\SiteAudience;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Freezes the audience into campaign_recipients (with A/B groups and local send times), then starts sending. */
class StartCampaign implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public string $campaignId) {}

    public function handle(CampaignService $service): void
    {
        $c = Campaign::find($this->campaignId);
        if (! $c || ! in_array($c->status, ['sending', 'scheduled'], true) || DB::table('campaign_recipients')->where('campaign_id', $c->id)->exists()) {
            return;
        }
        $c->forceFill(['status' => 'sending', 'started_at' => now()])->save();

        try {
            $live = $service->siteAudience($c);
            if ($live) {
                $this->fromSite($c, ...$live);

                return;
            }
        } catch (ApiException $e) {
            // The site didn't answer: nothing half-read is kept; Resume reads the audience again.
            DB::table('campaign_recipients')->where('campaign_id', $c->id)->delete();
            $c->forceFill(['status' => 'paused', 'paused_at' => now(), 'recipients_count' => 0, 'status_note' => mb_substr($e->getMessage(), 0, 255)])->save();

            return;
        }
        // Random order, so A/B groups are fair samples.
        $ids = $service->audience($c)->pluck('id')->shuffle()->values();
        $n = $ids->count();
        $testEach = $c->ab_enabled ? max(1, (int) floor($n * $c->ab_test_pct / 100 / 2)) : 0;
        $now = now();
        foreach ($ids->chunk(1000) as $offsetChunk) {
            $contacts = Contact::whereIn('id', $offsetChunk->all())->get(['id', 'email', 'fields'])->keyBy('id');
            $rows = [];
            foreach ($offsetChunk as $pos => $id) {
                $contact = $contacts[$id] ?? null;
                if (! $contact) {
                    continue;
                }
                $variant = ! $c->ab_enabled ? 'a' : ($pos < $testEach ? 'a' : ($pos < 2 * $testEach ? 'b' : null));
                $rows[] = ['campaign_id' => $c->id, 'contact_id' => $id, 'email' => $contact->email, 'variant' => $variant, 'status' => 'pending',
                    'send_at' => $variant === null ? null : $this->sendAt($c, $contact, $now)->toDateTimeString()];
            }
            DB::table('campaign_recipients')->insertOrIgnore($rows);
        }
        $c->forceFill(['recipients_count' => DB::table('campaign_recipients')->where('campaign_id', $c->id)->count()])->save();
        SendCampaignBatch::dispatch($c->id);
    }

    /**
     * A connected site's segment: the people are read from the site now, page by page (only those who agreed to
     * marketing), and are never stored as contacts. Each recipient keeps its merge details only until its email is
     * built. A/B groups are drawn at random afterwards.
     *
     * @param  array<string, mixed>  $rules
     */
    private function fromSite(Campaign $c, SiteConnection $site, array $rules): void
    {
        $now = now();
        $after = null;
        $pages = 0;
        do {
            $r = SiteAudience::chunk($site, $rules, $after, 500);
            $rows = [];
            foreach ($r['people'] as $p) {
                $email = mb_strtolower(trim((string) ($p['email'] ?? '')));
                if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    continue;
                }
                $vars = SiteAudience::mergeVars($p);
                $rows[] = ['campaign_id' => $c->id, 'contact_id' => null, 'email' => $email, 'variant' => 'a', 'status' => 'pending',
                    'send_at' => $this->sendAt($c, $vars, $now)->toDateTimeString(), 'vars' => json_encode($vars)];
            }
            DB::table('campaign_recipients')->insertOrIgnore($rows);
            $after = $r['next'];
        } while ($after !== null && ++$pages < 2000);

        $n = DB::table('campaign_recipients')->where('campaign_id', $c->id)->count();
        if ($c->ab_enabled && $n > 0) {
            $each = max(1, (int) floor($n * $c->ab_test_pct / 100 / 2));
            $ids = DB::table('campaign_recipients')->where('campaign_id', $c->id)->pluck('id')->shuffle()->values();
            DB::table('campaign_recipients')->whereIn('id', $ids->slice($each, $each)->all())->update(['variant' => 'b']);
            DB::table('campaign_recipients')->whereIn('id', $ids->slice(2 * $each)->all())->update(['variant' => null, 'send_at' => null]); // the holdout waits for the winner
        }
        $c->forceFill(['recipients_count' => $n])->save();
        SendCampaignBatch::dispatch($c->id);
    }

    /** For "local time" sends: the recipient's own zone (a "timezone" field), else the campaign's zone. */
    /** @param Contact|array<string, mixed> $who a contact, or a site person's merge details */
    private function sendAt(Campaign $c, Contact|array $who, Carbon $now): Carbon
    {
        if ($c->send_mode !== 'local' || ! $c->local_date || ! $c->local_time) {
            return $now;
        }
        $tz = (is_array($who) ? ($who['timezone'] ?? null) : ($who->fields['timezone'] ?? null)) ?? $c->timezone ?? 'UTC';
        if (! is_string($tz) || ! in_array($tz, timezone_identifiers_list(), true)) {
            $tz = $c->timezone ?: 'UTC';
        }
        $at = Carbon::parse($c->local_date->toDateString().' '.$c->local_time, $tz)->utc();

        return $at->lt($now) ? $now : $at; // that zone has already passed the time: send now
    }
}
