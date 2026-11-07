<?php

namespace App\Http\Middleware;

use App\Enums\WorkspaceRole;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Models\Workspace;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/** Resolves {workspace} and the caller's role in it. Non-members get 404 so workspace ids can't be probed. */
class EnsureWorkspaceMember
{
    public function handle(Request $request, Closure $next): Response
    {
        $workspace = $request->route('workspace');
        /** @var User|null $user */
        $user = $request->user();
        $role = $workspace instanceof Workspace && $user
            ? DB::table('workspace_user')->where('workspace_id', $workspace->id)->where('user_id', $user->id)->value('role')
            : null;
        if (! is_string($role)) {
            throw new ApiException('not_found', 'Not found.', 404);
        }
        $request->attributes->set('workspace_role', WorkspaceRole::from($role));

        return $next($request);
    }
}
