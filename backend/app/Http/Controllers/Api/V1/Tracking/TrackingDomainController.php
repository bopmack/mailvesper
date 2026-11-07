<?php

namespace App\Http\Controllers\Api\V1\Tracking;

use App\Http\Controllers\Concerns\WorkspaceAccess;
use App\Http\Controllers\Controller;
use App\Models\TrackingDomain;
use App\Models\Workspace;
use App\Services\Audit;
use App\Services\Smtp\Tracking;
use App\Services\Tracking\TrackingDomains;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Domains → Link tracking: the workspace's own tracking domain (owners and admins). */
class TrackingDomainController extends Controller
{
    use WorkspaceAccess;

    public function __construct(private TrackingDomains $domains) {}

    public function show(Workspace $workspace): JsonResponse
    {
        return response()->json(['tracking_domain' => self::present(TrackingDomain::where('workspace_id', $workspace->id)->first())]);
    }

    public function save(Request $request, Workspace $workspace): JsonResponse
    {
        $this->requireManager($request, 'set the tracking domain');
        $host = TrackingDomains::normalise((string) $request->input('host'));
        $d = TrackingDomain::firstOrNew(['workspace_id' => $workspace->id]);
        if ($d->host !== $host) {
            $d->forceFill(['host' => $host, 'status' => 'pending', 'verified_at' => null, 'ses_ready' => false])->save();
            Tracking::flush();
        }
        $this->domains->check($d);
        Audit::log('tracking_domain.saved', $this->currentUser($request), $workspace->id, ['host' => $host, 'status' => $d->status]);

        return $this->show($workspace);
    }

    public function check(Request $request, Workspace $workspace): JsonResponse
    {
        $this->requireManager($request, 'check the tracking domain');
        if ($d = TrackingDomain::where('workspace_id', $workspace->id)->first()) {
            $this->domains->check($d);
        }

        return $this->show($workspace);
    }

    public function destroy(Request $request, Workspace $workspace): JsonResponse
    {
        $this->requireManager($request, 'remove the tracking domain');
        TrackingDomain::where('workspace_id', $workspace->id)->delete();
        Tracking::flush();
        Audit::log('tracking_domain.removed', $this->currentUser($request), $workspace->id);

        return response()->json(['deleted' => true]);
    }

    /** GET /v1/t/ping: proves a tracking domain reaches this installation. */
    public function ping(): Response
    {
        return response(Tracking::pingToken(), 200, ['Content-Type' => 'text/plain', 'Cache-Control' => 'no-store']);
    }

    /** @return array<string, mixed> */
    private static function present(?TrackingDomain $d): array
    {
        return ['target' => TrackingDomains::target()] + ($d ? ['host' => $d->host, 'status' => $d->status, 'ses_ready' => $d->ses_ready, 'last_error' => $d->last_error,
            'verified_at' => $d->verified_at?->toIso8601ZuluString(), 'last_checked_at' => $d->last_checked_at?->toIso8601ZuluString()] : ['host' => null]);
    }
}
