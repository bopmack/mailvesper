<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Removes a deleted workspace's data in small chunks, so a workspace with millions of emails never locks the
 * database. Runs again from where it stopped when its time budget runs out (and the scheduler re-queues any purge a
 * dead worker left behind). The audit log and AI usage rows are kept: they're the installation's security and cost
 * records, and hold no contact data.
 */
class PurgeWorkspace implements ShouldQueue
{
    use Queueable;

    /** Rows per DELETE. */
    public static int $chunk = 2000;

    /** Seconds of work per run before it re-queues itself (tests set 0 to exercise the hand-over). */
    public static float $budgetSeconds = 40;

    public int $tries = 1;

    public function __construct(public string $workspaceId) {}

    public function handle(): void
    {
        $id = $this->workspaceId;
        $ws = DB::table('workspaces')->where('id', $id)->first(['id', 'deleting_at']);
        if (! $ws || $ws->deleting_at === null) {
            return; // already gone, or not being deleted
        }
        $until = microtime(true) + self::$budgetSeconds;
        $sub = fn (string $table) => DB::table($table)->where('workspace_id', $id)->select('id');

        // Rows tied to the workspace only through a parent, or not at all, go first, before their parents.
        $steps = [
            ['journey_steps', fn () => DB::table('journey_steps')->whereIn('journey_id', $sub('journeys'))],
            ['import_seen', fn () => DB::table('import_seen')->whereIn('import_id', $sub('contact_imports')), false],
            ['campaign_recipients', fn () => DB::table('campaign_recipients')->whereIn('campaign_id', $sub('campaigns'))],
            ['message_events', fn () => DB::table('message_events')->where('workspace_id', $id)],
            ['ses_events', fn () => DB::table('ses_events')->where('workspace_id', $id)],
            ['webhook_deliveries', fn () => DB::table('webhook_deliveries')->where('workspace_id', $id)],
            ['contact_events', fn () => DB::table('contact_events')->where('workspace_id', $id)],
            ['journey_runs', fn () => DB::table('journey_runs')->where('workspace_id', $id)],
            ['form_submissions', fn () => DB::table('form_submissions')->where('workspace_id', $id)],
            ['contact_tags', fn () => DB::table('contact_tags')->where('workspace_id', $id), false],
            ['messages', fn () => DB::table('messages')->where('workspace_id', $id)],
            ['agent_memories', fn () => DB::table('agent_memories')->where('workspace_id', $id)],
            ['contacts', fn () => DB::table('contacts')->where('workspace_id', $id)], // their list and topic links cascade
        ];
        foreach ($steps as $step) {
            [$table, $query] = $step;
            $hasId = $step[2] ?? true; // tables without an id column get a limited DELETE instead
            while (true) {
                if ($hasId) {
                    $ids = $query()->limit(self::$chunk)->pluck('id')->all();
                    $gone = $ids === [] ? 0 : DB::table($table)->whereIn('id', $ids)->delete();
                } else {
                    $gone = $query()->limit(self::$chunk)->delete();
                }
                if ($gone === 0) {
                    break;
                }
                if (microtime(true) > $until) {
                    self::dispatch($id); // carry on in a fresh job

                    return;
                }
            }
        }

        // Everything left (settings, lists, templates, campaigns, journeys…) is small and cascades from the row.
        DB::table('workspaces')->where('id', $id)->delete();
        Storage::disk('public')->deleteDirectory("media/$id");
        Log::info('Workspace purged', ['workspace' => $id]);
    }
}
