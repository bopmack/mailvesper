<?php

namespace App\Http\Controllers\Api\V1\Deliverability;

use App\Exceptions\ApiException;
use App\Http\Controllers\Concerns\WorkspaceAccess;
use App\Http\Controllers\Controller;
use App\Models\DeliverabilityCheck;
use App\Models\Message;
use App\Models\Workspace;
use App\Services\Deliverability\EmailReport;
use App\Services\Deliverability\SetupCheck;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** "Check my setup" and each email's report. */
class DeliverabilityController extends Controller
{
    use WorkspaceAccess;

    public function start(Request $request, Workspace $workspace, SetupCheck $checks): JsonResponse
    {
        $this->requireEditor($request, 'check the setup');
        $from = (string) $request->validate(['from' => ['required', 'email', 'max:254']])['from'];
        if (DeliverabilityCheck::where('workspace_id', $workspace->id)->where('created_at', '>', now()->subSeconds(30))->exists()) {
            throw new ApiException('too_many', 'A check is already on its way. Give it a moment.', 429);
        }

        return response()->json(['check' => self::present($checks->start($workspace, $from))], 201);
    }

    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        $q = DeliverabilityCheck::where('workspace_id', $workspace->id)->orderByDesc('created_at');
        if ($d = $request->query('domain')) {
            $q->where('domain', mb_strtolower((string) $d));
        }

        return response()->json(['checks' => $q->limit(10)->get()->map(fn ($c) => self::present($c)), 'available' => SetupCheck::configured()]);
    }

    public function show(Workspace $workspace, string $id, SetupCheck $checks): JsonResponse
    {
        $c = DeliverabilityCheck::where('workspace_id', $workspace->id)->find($id) ?? throw new ApiException('not_found', 'Not found.', 404);
        if ($c->status === 'waiting' && $c->created_at->lt(now()->subSeconds(10))) {
            $checks->collect(); // don't make people wait for the scheduler
            $c->refresh();
        }

        return response()->json(['check' => self::present($c)]);
    }

    public function report(Workspace $workspace, string $id, EmailReport $reports): JsonResponse
    {
        $m = Message::where('workspace_id', $workspace->id)->where(fn ($q) => $q->where('id', $id)->orWhere('tracking_code', strtoupper($id)))->first()
            ?? throw new ApiException('not_found', 'Not found.', 404);

        return response()->json(['report' => $reports->for($m)]);
    }

    /** @return array<string, mixed> */
    public static function present(DeliverabilityCheck $c): array
    {
        return ['id' => $c->id, 'domain' => $c->domain, 'from' => $c->from_email, 'status' => $c->status, 'result' => $c->result, 'error' => $c->error,
            'created_at' => $c->created_at->toIso8601ZuluString(), 'finished_at' => $c->finished_at?->toIso8601ZuluString()];
    }
}
