<?php

namespace App\Http\Controllers\Api\V1\Sending;

use App\Exceptions\ApiException;
use App\Http\Controllers\Concerns\WorkspaceAccess;
use App\Http\Controllers\Controller;
use App\Models\Suppression;
use App\Models\Workspace;
use App\Services\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Suppressions: addresses MailVesper never sends to. */
class SuppressionController extends Controller
{
    use WorkspaceAccess;

    public const PER_PAGE = 50;

    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', Rule::in(Suppression::REASONS)], 'q' => ['nullable', 'string', 'max:254'], 'before' => ['nullable', 'integer']]);
        $base = DB::table('suppressions')->where('workspace_id', $workspace->id);
        $rows = (clone $base)
            ->when($data['reason'] ?? null, fn ($q, $r) => $q->where('reason', $r))
            ->when($data['q'] ?? null, fn ($q, $s) => $q->where('email', 'like', '%'.addcslashes(mb_strtolower($s), '%_\\').'%'))
            ->when($data['before'] ?? null, fn ($q, $b) => $q->where('id', '<', $b))
            ->orderByDesc('id')->limit(self::PER_PAGE + 1)->get(['id', 'email', 'reason', 'detail', 'message_id', 'created_at']);
        $more = $rows->count() > self::PER_PAGE;
        $rows = $rows->take(self::PER_PAGE)->values();

        return response()->json([
            'suppressions' => $rows, 'next_before' => $more ? $rows->last()->id : null,
            'counts' => (clone $base)->selectRaw('reason, count(*) as n')->groupBy('reason')->pluck('n', 'reason'),
        ]);
    }

    /** Add one or many addresses by hand (also used by CSV import in the app). */
    public function store(Request $request, Workspace $workspace): JsonResponse
    {
        $this->requireEditor($request, 'change suppressions');
        $data = $request->validate(['emails' => ['required', 'array', 'min:1', 'max:10000'], 'emails.*' => ['string', 'max:254']]);
        $valid = [];
        $invalid = 0;
        foreach ($data['emails'] as $e) {
            $e = mb_strtolower(trim($e));
            filter_var($e, FILTER_VALIDATE_EMAIL) ? $valid[$e] = true : $invalid++;
        }
        $before = DB::table('suppressions')->where('workspace_id', $workspace->id)->count();
        foreach (array_chunk(array_keys($valid), 500) as $chunk) {
            DB::table('suppressions')->insertOrIgnore(array_map(fn ($e) => ['workspace_id' => $workspace->id, 'email' => $e, 'reason' => 'manual', 'detail' => 'Added by '.$this->currentUser($request)->email, 'created_at' => now()], $chunk));
            DB::table('contacts')->where('workspace_id', $workspace->id)->whereIn('email', $chunk)->where('status', 'subscribed')->update(['status' => 'unsubscribed', 'updated_at' => now()]);
        }
        $added = DB::table('suppressions')->where('workspace_id', $workspace->id)->count() - $before;
        Audit::log('suppression.added', $this->currentUser($request), $workspace->id, ['count' => $added]);

        return response()->json(['added' => $added, 'already' => count($valid) - $added, 'invalid' => $invalid]);
    }

    public function destroy(Request $request, Workspace $workspace, int $id): JsonResponse
    {
        $this->requireEditor($request, 'change suppressions');
        $row = DB::table('suppressions')->where('workspace_id', $workspace->id)->where('id', $id)->first() ?? throw new ApiException('not_found', 'Not found.', 404);
        if ($row->reason === 'complaint') {
            $this->requireManager($request, 'remove spam complaints'); // re-mailing someone who reported spam is risky
        }
        Suppression::remove($workspace->id, $row->email);
        Audit::log('suppression.removed', $this->currentUser($request), $workspace->id, ['email' => $row->email, 'reason' => $row->reason]);

        return response()->json(['removed' => true]);
    }

    public function export(Request $request, Workspace $workspace): StreamedResponse
    {
        return response()->streamDownload(function () use ($workspace) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['email', 'reason', 'detail', 'added_at']);
            DB::table('suppressions')->where('workspace_id', $workspace->id)->orderBy('id')->chunk(1000, function ($rows) use ($out) {
                foreach ($rows as $r) {
                    fputcsv($out, [$r->email, $r->reason, $r->detail, $r->created_at]);
                }
            });
            fclose($out);
        }, 'mailvesper-suppressions-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
