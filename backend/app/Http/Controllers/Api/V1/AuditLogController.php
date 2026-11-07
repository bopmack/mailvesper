<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\WorkspaceRole;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Settings → Security → Audit log. Owners and admins only. Newest first, 50 per page. */
class AuditLogController extends Controller
{
    public const PER_PAGE = 50;

    public const CATEGORIES = ['auth', 'security', 'team', 'workspace'];

    public function __invoke(Request $request, Workspace $workspace): JsonResponse
    {
        /** @var WorkspaceRole $role */
        $role = $request->attributes->get('workspace_role');
        if (! $role->canManageTeam()) {
            throw new ApiException('forbidden', 'Only owners and admins can see the audit log.', 403);
        }
        $data = $request->validate([
            'category' => ['nullable', Rule::in(self::CATEGORIES)],
            'before' => ['nullable', 'integer', 'min:1'],
        ]);

        $rows = DB::table('audit_logs')->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')
            ->where('audit_logs.workspace_id', $workspace->id)
            ->when($data['category'] ?? null, fn ($q, $c) => $q->where('audit_logs.action', 'like', $c.'.%'))
            ->when($data['before'] ?? null, fn ($q, $b) => $q->where('audit_logs.id', '<', $b))
            ->orderByDesc('audit_logs.id')->limit(self::PER_PAGE + 1)
            ->get(['audit_logs.id', 'audit_logs.action', 'audit_logs.ip_address', 'audit_logs.user_agent', 'audit_logs.meta', 'audit_logs.created_at', 'users.name', 'users.email']);

        $more = $rows->count() > self::PER_PAGE;
        $events = $rows->take(self::PER_PAGE)->map(fn (object $r) => [
            'id' => $r->id, 'action' => $r->action, 'actor' => $r->name ? ['name' => $r->name, 'email' => $r->email] : null,
            'ip' => $r->ip_address, 'user_agent' => $r->user_agent, 'meta' => $r->meta ? json_decode((string) $r->meta, true) : null,
            'at' => Carbon::parse($r->created_at)->toIso8601String(),
        ])->values();

        return response()->json(['events' => $events, 'next_before' => $more ? $events->last()['id'] : null]);
    }
}
