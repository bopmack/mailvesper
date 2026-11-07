<?php

namespace App\Http\Controllers\Api\V1\Ses;

use App\Enums\WorkspaceRole;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\SesConnection;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Audit;
use App\Services\Ses\DomainService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Sending domains. Anyone in the workspace can see them; owners and admins add, check and remove. */
class DomainController extends Controller
{
    public function __construct(private DomainService $domains) {}

    public function index(Workspace $workspace): JsonResponse
    {
        return response()->json(['domains' => Domain::where('workspace_id', $workspace->id)->orderBy('name')->get()->map(fn (Domain $d) => self::present($d))]);
    }

    public function show(Workspace $workspace, Domain $domain): JsonResponse
    {
        return response()->json(['domain' => self::present($this->own($workspace, $domain))]);
    }

    public function store(Request $request, Workspace $workspace): JsonResponse
    {
        $this->manager($request);
        $name = DomainService::normalise((string) $request->input('name', ''));
        if (! DomainService::valid($name)) {
            throw new ApiException('validation_failed', 'Enter a domain like mail.yourcompany.com.', 422, ['name' => 'Enter a domain like mail.yourcompany.com.']);
        }
        if (Domain::where('workspace_id', $workspace->id)->count() >= 100) {
            throw new ApiException('domain_limit', 'You can add up to 100 domains.', 422);
        }
        $conn = $this->conn($workspace);
        $domain = $this->domains->add($conn, $name);
        Audit::log('domain.added', $this->user($request), $workspace->id, ['domain' => $name]);

        // DNS on Route 53: add the records straight away, so there's nothing to copy.
        $auto = 0;
        if ($domain->dns_provider === 'route53' && $domain->status !== 'verified') {
            try {
                $auto = $this->domains->applyRoute53($conn, $domain);
                Audit::log('domain.dns_auto_configured', $this->user($request), $workspace->id, ['domain' => $name, 'provider' => 'route53', 'records' => $auto]);
            } catch (ApiException) {
                $auto = 0; // the page offers the button and the manual records instead
            }
        }

        if ($domain->dns_provider === 'cloudflare' && $domain->status !== 'verified') {
            try {
                $auto = $this->domains->applyCloudflare($domain);
                Audit::log('domain.dns_auto_configured', $this->user($request), $workspace->id, ['domain' => $name, 'provider' => 'cloudflare', 'records' => $auto]);
            } catch (ApiException) {
                $auto = 0;
            }
        }

        return response()->json(['domain' => self::present($domain), 'auto_dns' => $auto], 201);
    }

    public function check(Request $request, Workspace $workspace, Domain $domain): JsonResponse
    {
        $this->notViewer($request);
        $this->own($workspace, $domain);
        if ($domain->last_checked_at && $domain->last_checked_at->gt(now()->subSeconds(10))) {
            return response()->json(['domain' => self::present($domain)]);
        }

        return response()->json(['domain' => self::present($this->domains->check($this->conn($workspace), $domain))]);
    }

    public function cloudflare(Request $request, Workspace $workspace, Domain $domain): JsonResponse
    {
        $this->manager($request);
        $this->own($workspace, $domain);
        $written = $this->domains->applyCloudflare($domain);
        Audit::log('domain.dns_auto_configured', $this->user($request), $workspace->id, ['domain' => $domain->name, 'provider' => 'cloudflare', 'records' => $written]);

        return response()->json(['written' => $written, 'domain' => self::present($domain)]);
    }

    public function route53(Request $request, Workspace $workspace, Domain $domain): JsonResponse
    {
        $this->manager($request);
        $this->own($workspace, $domain);
        $conn = $this->conn($workspace);
        $written = $this->domains->applyRoute53($conn, $domain);
        Audit::log('domain.dns_auto_configured', $this->user($request), $workspace->id, ['domain' => $domain->name, 'provider' => 'route53', 'records' => $written]);

        return response()->json(['written' => $written, 'domain' => self::present($domain)]);
    }

    public function destroy(Request $request, Workspace $workspace, Domain $domain): JsonResponse
    {
        $this->manager($request);
        $this->own($workspace, $domain);
        $domain->delete(); // removes it from MailVesper only; the identity stays in the customer's SES
        Audit::log('domain.removed', $this->user($request), $workspace->id, ['domain' => $domain->name]);

        return response()->json(['removed' => true]);
    }

    /** @return array<string, mixed> */
    public static function present(Domain $d): array
    {
        $check = $d->dns_check ?? [];

        return [
            'id' => $d->id, 'name' => $d->name, 'region' => $d->region, 'status' => $d->status, 'imported' => $d->imported,
            'dkim_status' => $d->dkim_status, 'mail_from_domain' => $d->mail_from_domain, 'mail_from_status' => $d->mail_from_status,
            'dns_provider' => $d->dns_provider,
            'records' => array_map(fn ($r) => $r + ['live' => $check[$r['key']] ?? 'unknown'], $d->dkim_tokens ? $d->records() : []),
            'last_checked_at' => $d->last_checked_at?->toIso8601String(), 'verified_at' => $d->verified_at?->toIso8601String(),
        ];
    }

    private function own(Workspace $workspace, Domain $domain): Domain
    {
        if ($domain->workspace_id !== $workspace->id) {
            throw new ApiException('not_found', 'Not found.', 404);
        }

        return $domain;
    }

    private function conn(Workspace $workspace): SesConnection
    {
        return SesConnection::where('workspace_id', $workspace->id)->first() ?? throw new ApiException('ses_not_connected', 'Connect Amazon SES first.', 422);
    }

    private function manager(Request $request): void
    {
        /** @var WorkspaceRole $role */
        $role = $request->attributes->get('workspace_role');
        if (! $role->canManageTeam()) {
            throw new ApiException('forbidden', 'Only owners and admins can change sending domains.', 403);
        }
    }

    /** Viewers can look, but not make the app call AWS on the workspace's behalf. */
    private function notViewer(Request $request): void
    {
        if ($request->attributes->get('workspace_role') === WorkspaceRole::Viewer) {
            throw new ApiException('forbidden', 'Viewers can’t refresh this. Ask an owner or admin.', 403);
        }
    }

    private function user(Request $request): User
    {
        /** @var User $u */
        $u = $request->user();

        return $u;
    }
}
