<?php

namespace App\Services\Copilot;

use App\Models\Workspace;
use App\Services\Ai\AiClient;
use App\Support\Edition;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Copilot: the deterministic diagnosis (see Diagnosis), cached per workspace. The AI chat that explains it is a
 * MailVesper Pro feature.
 */
class Copilot
{
    public function __construct(private Diagnosis $diagnosis, private AiClient $ai) {}

    /** @return array<string, mixed> the latest diagnosis, running a fresh one when there is none or it is old */
    public function latest(Workspace $ws, bool $fresh = false): array
    {
        $row = DB::table('copilot_scans')->where('workspace_id', $ws->id)->orderByDesc('id')->first();
        if ($fresh || ! $row || now()->diffInMinutes(Carbon::parse((string) $row->created_at, 'UTC')) >= 360) {
            $r = $this->diagnosis->run($ws);
            DB::table('copilot_scans')->insert(['workspace_id' => $ws->id, 'score' => $r['score'], 'findings' => json_encode($r['findings']), 'passed' => json_encode($r['passed']),
                'summary' => json_encode($r['summary']), 'created_at' => now()]);
            $row = DB::table('copilot_scans')->where('workspace_id', $ws->id)->orderByDesc('id')->first();
        }
        $history = DB::table('copilot_scans')->where('workspace_id', $ws->id)->whereNotNull('score')->where('created_at', '>=', now()->subDays(90)->toDateTimeString())
            ->orderBy('id')->get(['score', 'created_at'])->groupBy(fn ($r) => substr((string) $r->created_at, 0, 10))->map(fn ($g) => (int) $g->last()->score)->all();

        return ['score' => $row->score === null ? null : (int) $row->score, 'findings' => json_decode((string) $row->findings, true), 'passed' => json_decode((string) $row->passed, true),
            'summary' => json_decode((string) $row->summary, true), 'checked_at' => Carbon::parse((string) $row->created_at, 'UTC')->toIso8601ZuluString(), 'history' => $history,
            'ai' => ['available' => app(Edition::class)->has('copilot_chat') && $this->ai->configured(), 'pro' => app(Edition::class)->has('copilot_chat')]];
    }

    /**
     * Fresh findings, for actions that must act on the current state.
     *
     * @return list<array<string, mixed>>
     */
    public function findings(Workspace $ws): array
    {
        /** @var list<array<string, mixed>> $f */
        $f = $this->diagnosis->run($ws)['findings'];

        return $f;
    }
}
