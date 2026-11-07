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
use App\Services\Ses\SesCatalog;
use App\Services\Ses\SesSetup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Amazon SES connection for a workspace. Anyone in the workspace can see it; owners and admins change it. */
class SesController extends Controller
{
    public function __construct(private SesSetup $setup) {}

    /** Public facts the connect page needs: regions and the IAM policy to copy. */
    public function catalog(): JsonResponse
    {
        return response()->json(['regions' => collect(SesCatalog::REGIONS)->map(fn ($n, $id) => ['id' => $id, 'name' => $n])->values(), 'policy' => SesCatalog::policy(), 'event_types' => SesCatalog::EVENT_TYPES]);
    }

    public function show(Workspace $workspace): JsonResponse
    {
        $conn = SesConnection::where('workspace_id', $workspace->id)->first();

        return response()->json(['connection' => $conn ? self::present($conn) : null]);
    }

    public function connect(Request $request, Workspace $workspace): JsonResponse
    {
        $this->manager($request);
        $data = $request->validate([
            'access_key_id' => ['required', 'string', 'regex:/^(AKIA|ASIA)[A-Z0-9]{16}$/'],
            'secret_access_key' => ['required', 'string', 'size:40', 'regex:/^[A-Za-z0-9\/+=]{40}$/'],
            'region' => ['required', Rule::in(array_keys(SesCatalog::REGIONS))],
        ], [
            'access_key_id.regex' => 'An access key ID is 20 characters and starts with AKIA.',
            'secret_access_key.size' => 'A secret access key is 40 characters.',
            'secret_access_key.regex' => 'A secret access key is 40 characters.',
        ]);
        if (str_starts_with($data['access_key_id'], 'ASIA')) {
            throw new ApiException('aws_temporary_keys', 'These are temporary keys that expire. Use the long-term keys of an IAM user (they start with AKIA).', 422, ['access_key_id' => 'Use long-term IAM user keys (AKIA…).']);
        }
        $conn = $this->setup->connect($workspace, $this->user($request), $data['access_key_id'], $data['secret_access_key'], $data['region']);
        Audit::log('ses.connected', $this->user($request), $workspace->id, ['account' => $conn->aws_account_id, 'region' => $conn->region]);

        return response()->json(['connection' => self::present($conn)]);
    }

    public function retry(Request $request, Workspace $workspace): JsonResponse
    {
        $this->manager($request);

        return response()->json(['connection' => self::present($this->setup->runSetup($this->conn($workspace)))]);
    }

    public function sync(Request $request, Workspace $workspace): JsonResponse
    {
        $this->notViewer($request);
        $conn = $this->conn($workspace);
        if ($conn->last_synced_at && $conn->last_synced_at->gt(now()->subSeconds(20))) {
            return response()->json(['connection' => self::present($conn)]); // don't hammer AWS on repeated clicks
        }

        return response()->json(['connection' => self::present($this->setup->sync($conn))]);
    }

    public function productionAccess(Request $request, Workspace $workspace): JsonResponse
    {
        $this->manager($request);
        $data = $request->validate([
            'mail_type' => ['required', Rule::in(['MARKETING', 'TRANSACTIONAL'])],
            'website_url' => ['required', 'url:https,http', 'max:1000'],
            'use_case' => ['required', 'string', 'min:100', 'max:5000'],
            'contacts' => ['nullable', 'array', 'max:4'],
            'contacts.*' => ['email'],
        ], ['use_case.min' => 'Describe your sending in at least 100 characters. AWS rejects vague requests.']);
        $conn = $this->conn($workspace);
        if ($conn->production_access) {
            throw new ApiException('already_production', 'This account already has production access.', 422);
        }
        $this->setup->requestProductionAccess($conn, $data['mail_type'], $data['website_url'], $data['use_case'], $data['contacts'] ?? []);
        Audit::log('ses.production_access_requested', $this->user($request), $workspace->id);

        return response()->json(['connection' => self::present($conn->refresh())]);
    }

    public function disconnect(Request $request, Workspace $workspace): JsonResponse
    {
        $this->manager($request);
        $conn = $this->conn($workspace);
        DB::transaction(function () use ($workspace, $conn) {
            Domain::where('workspace_id', $workspace->id)->delete();
            $conn->delete();
        });
        Audit::log('ses.disconnected', $this->user($request), $workspace->id, ['account' => $conn->aws_account_id, 'region' => $conn->region]);

        return response()->json(['disconnected' => true]);
    }

    /** @return array<string, mixed> */
    public static function present(SesConnection $c): array
    {
        $steps = $c->setup_steps ?? [];
        if ($c->subscription_confirmed_at) {
            $steps['subscribe'] = ['status' => 'done'];
        }

        return [
            'id' => $c->id, 'status' => $c->status, 'region' => $c->region, 'region_name' => SesCatalog::REGIONS[$c->region] ?? $c->region,
            'aws_account_id' => $c->aws_account_id, 'iam_arn' => $c->iam_arn, 'access_key' => $c->maskedKey(),
            'production_access' => $c->production_access, 'sending_enabled' => $c->sending_enabled, 'enforcement_status' => $c->enforcement_status,
            'review_status' => $c->review_status,
            'quota' => ['max_24h' => $c->max_24h_send, 'max_rate' => $c->max_send_rate, 'sent_24h' => $c->sent_last_24h],
            'reputation' => ['bounce_rate' => $c->bounce_rate, 'complaint_rate' => $c->complaint_rate],
            'configuration_set' => $c->configuration_set, 'events_receiving' => (bool) $c->subscription_confirmed_at,
            'steps' => collect(SesSetup::STEPS)->mapWithKeys(fn ($s) => [$s => $steps[$s] ?? ['status' => 'pending']]),
            'last_error' => $c->last_error, 'last_synced_at' => $c->last_synced_at?->toIso8601String(), 'last_event_at' => $c->last_event_at?->toIso8601String(),
            'connected_at' => $c->created_at?->toIso8601String(),
        ];
    }

    private function conn(Workspace $workspace): SesConnection
    {
        return SesConnection::where('workspace_id', $workspace->id)->first() ?? throw new ApiException('ses_not_connected', 'Connect Amazon SES first.', 404);
    }

    private function manager(Request $request): void
    {
        /** @var WorkspaceRole $role */
        $role = $request->attributes->get('workspace_role');
        if (! $role->canManageTeam()) {
            throw new ApiException('forbidden', 'Only owners and admins can change the Amazon SES connection.', 403);
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
