<?php

namespace App\Http\Controllers\Api\V1\Dns;

use App\Http\Controllers\Concerns\WorkspaceAccess;
use App\Http\Controllers\Controller;
use App\Models\DnsConnection;
use App\Models\Domain;
use App\Models\Workspace;
use App\Services\Audit;
use App\Services\Dns\Cloudflare;
use App\Services\Ses\DomainService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Domains → Cloudflare: the API token MailVesper uses to add sending records (owners and admins). */
class DnsConnectionController extends Controller
{
    use WorkspaceAccess;

    public function show(Workspace $workspace): JsonResponse
    {
        $c = DnsConnection::where('workspace_id', $workspace->id)->where('provider', 'cloudflare')->first();

        return response()->json(['cloudflare' => $c ? ['connected' => true, 'verified_at' => $c->verified_at?->toIso8601ZuluString(),
            'domains' => Domain::where('workspace_id', $workspace->id)->whereNotNull('cloudflare_zone_id')->pluck('name')] : ['connected' => false]]);
    }

    public function save(Request $request, Workspace $workspace, Cloudflare $cloudflare, DomainService $domains): JsonResponse
    {
        $this->requireManager($request, 'connect Cloudflare');
        $d = $request->validate(['token' => ['required', 'string', 'min:20', 'max:200', 'regex:/^[A-Za-z0-9_\-]+$/']], ['token.regex' => 'Paste the API token itself (letters, digits, - and _).']);
        $cloudflare->verify($d['token']);
        DnsConnection::updateOrCreate(['workspace_id' => $workspace->id, 'provider' => 'cloudflare'], ['token' => $d['token'], 'verified_at' => now()]);
        // Find which of the workspace's domains this token can manage.
        foreach (Domain::where('workspace_id', $workspace->id)->whereNull('route53_zone_id')->get() as $domain) {
            $domains->detectCloudflare($domain);
        }
        Audit::log('dns.cloudflare_connected', $this->currentUser($request), $workspace->id);

        return $this->show($workspace);
    }

    public function destroy(Request $request, Workspace $workspace): JsonResponse
    {
        $this->requireManager($request, 'disconnect Cloudflare');
        DnsConnection::where('workspace_id', $workspace->id)->where('provider', 'cloudflare')->delete();
        Domain::where('workspace_id', $workspace->id)->whereNotNull('cloudflare_zone_id')->update(['cloudflare_zone_id' => null, 'dns_provider' => null]);
        Audit::log('dns.cloudflare_removed', $this->currentUser($request), $workspace->id);

        return response()->json(['deleted' => true]);
    }
}
