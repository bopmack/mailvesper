<?php

namespace App\Jobs;

use App\Exceptions\ApiException;
use App\Models\Campaign;
use App\Models\Workspace;
use App\Services\Activity\Signals;
use App\Services\Sending\Mailer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Hands the next batch of due recipients to the mailer, then queues itself again. Small batches mean pause
 * works within seconds. Outlook/Microsoft addresses are capped per batch so their volume ramps up gently.
 */
class SendCampaignBatch implements ShouldQueue
{
    use Queueable;

    public const BATCH = 200;

    public const MICROSOFT_PER_BATCH = 60;

    public const GENTLE_MICROSOFT_PER_BATCH = 20;

    public const PAUSE_SECONDS = 5;

    public int $tries = 1;

    public function __construct(public string $campaignId) {}

    public function handle(Mailer $mailer): void
    {
        $c = Campaign::find($this->campaignId);
        if (! $c || $c->status !== 'sending') {
            return;
        }
        $lock = cache()->lock('campaign-batch:'.$c->id, 120);
        if (! $lock->get()) {
            return; // another batch for this campaign is running
        }
        try {
            $this->batch($c, $mailer);
        } catch (Throwable $e) {
            report($e);
        } finally {
            $lock->release();
        }

        $c->refresh();
        if ($c->status !== 'sending') {
            return;
        }
        $due = DB::table('campaign_recipients')->where('campaign_id', $c->id)->where('status', 'pending')->whereNotNull('send_at')->where('send_at', '<=', now())->exists();
        $waiting = DB::table('campaign_recipients')->where('campaign_id', $c->id)->where('status', 'pending')->exists();
        if ($due) {
            self::dispatch($c->id)->delay(now()->addSeconds(self::PAUSE_SECONDS));
        } elseif (! $waiting) {
            // Everyone is handed to the mailer, but it stays "sending" (so it can still be paused) until the
            // queue has passed every email to SES under the account's send rate.
            $inQueue = DB::table('messages')->where('campaign_id', $c->id)->whereIn('status', ['queued', 'sending'])->exists();
            if ($inQueue) {
                self::dispatch($c->id)->delay(now()->addSeconds(self::PAUSE_SECONDS * 3));
            } else {
                $c->forceFill(['status' => 'sent', 'finished_at' => now()])->save();
                DB::table('campaign_recipients')->where('campaign_id', $c->id)->whereNotNull('vars')->update(['vars' => null]); // a site's people: nothing kept
            }
        }
        // else: later local-time slots or the A/B holdout; campaigns:tick picks them up.
    }

    private function batch(Campaign $c, Mailer $mailer): void
    {
        $rows = DB::table('campaign_recipients')->where('campaign_id', $c->id)->where('status', 'pending')->whereNotNull('send_at')->where('send_at', '<=', now())
            ->orderBy('send_at')->orderBy('id')->limit(self::BATCH)->get(['id', 'email', 'variant', 'vars']);
        if ($rows->isEmpty()) {
            return;
        }
        // Gentle on Outlook: past the cap, Microsoft addresses wait for the next batch. Copilot's "gentle Outlook
        // pacing" (a workspace setting) cuts the cap to a third when Microsoft starts deferring.
        $ms = 0;
        $cap = (bool) ((Workspace::findOrFail($c->workspace_id)->settings ?? [])['gentle_microsoft'] ?? false) ? self::GENTLE_MICROSOFT_PER_BATCH : self::MICROSOFT_PER_BATCH;
        $rows = $rows->filter(function ($r) use (&$ms, $cap) {
            if (Signals::providerFromDomain($r->email) !== 'microsoft') {
                return true;
            }
            if (++$ms <= $cap) {
                return true;
            }
            DB::table('campaign_recipients')->where('id', $r->id)->update(['send_at' => now()->addSeconds(self::PAUSE_SECONDS * 2)->toDateTimeString()]);

            return false;
        });

        $ws = Workspace::findOrFail($c->workspace_id);
        $base = $mailer->validate($ws, [
            'from' => $c->from_name ? "{$c->from_name} <{$c->from_email}>" : $c->from_email, 'to' => $c->from_email, 'subject' => (string) $c->subject,
            'html' => $c->html, 'reply_to' => $c->reply_to ?: null, 'type' => 'marketing', 'topic' => $c->topic_id, 'tags' => ['campaign' => $c->id],
        ]);
        foreach ($rows->groupBy('variant') as $variant => $group) {
            foreach ($group->chunk(Mailer::MAX_RECIPIENTS) as $chunk) {
                if (Campaign::whereKey($c->id)->value('status') !== 'sending') {
                    return; // paused or cancelled mid-batch: the rest stay pending
                }
                $payload = ['to' => $chunk->pluck('email')->values()->all(), 'campaign_id' => $c->id, 'variant' => $variant ?: 'a',
                    'subject' => $variant === 'b' && $c->subject_b ? $c->subject_b : (string) $c->subject,
                    // A connected site's people aren't contacts: their merge details came with them.
                    'recipient_vars' => $chunk->filter(fn ($r) => $r->vars !== null)->mapWithKeys(fn ($r) => [mb_strtolower($r->email) => (array) json_decode((string) $r->vars, true)])->all()] + $base;
                try {
                    $messages = $mailer->submit($ws, [$payload], 'campaign');
                } catch (ApiException $e) {
                    if ($e->errorCode !== 'plan_limit') {
                        throw $e;
                    }
                    // Over the plan's monthly emails: pause with the reason; the rest stay pending for Resume.
                    Campaign::whereKey($c->id)->where('status', 'sending')->update(['status' => 'paused', 'paused_at' => now(), 'status_note' => mb_substr($e->getMessage(), 0, 255)]);

                    return;
                }
                $byEmail = collect($messages)->keyBy(fn ($m) => mb_strtolower($m->to_email));
                foreach ($chunk as $r) {
                    $m = $byEmail[mb_strtolower($r->email)] ?? null;
                    DB::table('campaign_recipients')->where('id', $r->id)->update([
                        'vars' => null, // the email is built: a site person's details aren't kept
                        'status' => $m && $m->status === 'suppressed' ? 'skipped' : 'sent', 'message_id' => $m?->id,
                        'skip_reason' => $m && $m->status === 'suppressed' ? mb_substr((string) $m->error, 0, 40) : null,
                    ]);
                }
            }
        }
    }
}
