<?php

namespace App\Http\Controllers\Api\V1\Copilot;

use App\Exceptions\ApiException;
use App\Http\Controllers\Concerns\WorkspaceAccess;
use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\SesConnection;
use App\Models\Workspace;
use App\Services\Audit;
use App\Services\Copilot\Copilot;
use App\Services\Copilot\DmarcReports;
use App\Services\Ses\DomainService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/** Copilot: findings, one-click fixes, DMARC reports. (The grounded AI chat is in MailVesper Pro.) */
class CopilotController extends Controller
{
    use WorkspaceAccess;

    public function __construct(private Copilot $copilot) {}

    public function show(Workspace $workspace): JsonResponse
    {
        return response()->json(['copilot' => $this->copilot->latest($workspace)]);
    }

    public function rescan(Request $request, Workspace $workspace): JsonResponse
    {
        $this->requireEditor($request, 'run Copilot checks');
        $key = 'copilot-scan:'.$workspace->id;
        if (RateLimiter::tooManyAttempts($key, 1)) {
            throw new ApiException('rate_limited', 'Copilot just checked. Try again in a minute.', 429, retryAfter: RateLimiter::availableIn($key));
        }
        RateLimiter::hit($key, 60);

        return response()->json(['copilot' => $this->copilot->latest($workspace, true)]);
    }

    /** One-click fixes: publish a DMARC record through Route 53, or flip a sending switch. */
    public function fix(Request $request, Workspace $workspace, DomainService $domains): JsonResponse
    {
        $data = $request->validate(['fix' => ['required', 'in:dmarc,gentle_microsoft'], 'domain' => ['required_if:fix,dmarc', 'nullable', 'string', 'max:253'], 'on' => ['required_if:fix,gentle_microsoft', 'boolean']]);
        if ($data['fix'] === 'gentle_microsoft') {
            $this->requireEditor($request, 'change sending settings');
            $workspace->settings = array_merge($workspace->settings ?? [], ['gentle_microsoft' => (bool) $data['on']]);
            $workspace->save();
            Audit::log('settings.gentle_microsoft', $this->currentUser($request), $workspace->id, ['on' => (bool) $data['on']]);

            return response()->json(['copilot' => $this->copilot->latest($workspace, true)]);
        }
        $this->requireManager($request, 'change DNS records');
        $domain = Domain::where('workspace_id', $workspace->id)->where('name', mb_strtolower((string) $data['domain']))->firstOrFail();
        // The record is recomputed on the server from the current finding: the client can't choose what gets published.
        $finding = collect($this->copilot->findings($workspace))->first(fn ($f) => in_array($f['id'], ['dmarc_missing:'.$domain->name, 'dmarc_none:'.$domain->name], true))
            ?? throw new ApiException('nothing_to_fix', 'There’s no DMARC change to make on '.$domain->name.' right now.', 422);
        $record = (string) $finding['evidence']['proposed'];
        $conn = SesConnection::where('workspace_id', $workspace->id)->firstOrFail();
        $domains->upsertTxt($conn, $domain, '_dmarc.'.$domain->name, $record);
        Audit::log('copilot.dmarc_published', $this->currentUser($request), $workspace->id, ['domain' => $domain->name, 'record' => $record]);

        return response()->json(['published' => $record, 'note' => 'Published. DNS changes can take a few minutes to show up; Copilot re-checks on its next run.']);
    }

    public function dmarcUpload(Request $request, Workspace $workspace, DmarcReports $reports): JsonResponse
    {
        $this->requireEditor($request, 'upload DMARC reports');
        $request->validate(['file' => ['required', 'file', 'max:10240']]);

        return response()->json(['report' => $reports->ingest($workspace->id, (string) file_get_contents((string) $request->file('file')?->getRealPath()))], 201);
    }

    public function dmarc(Workspace $workspace, DmarcReports $reports): JsonResponse
    {
        return response()->json(['dmarc' => $reports->summary($workspace->id)]);
    }
}
