<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\WorkspaceRole;
use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Http\Request;

trait WorkspaceAccess
{
    protected function role(Request $request): WorkspaceRole
    {
        /** @var WorkspaceRole $role */
        $role = $request->attributes->get('workspace_role');

        return $role;
    }

    protected function requireManager(Request $request, string $what): void
    {
        if (! $this->role($request)->canManageTeam()) {
            throw new ApiException('forbidden', "Only owners and admins can $what.", 403);
        }
    }

    protected function requireEditor(Request $request, string $what): void
    {
        if ($this->role($request) === WorkspaceRole::Viewer) {
            throw new ApiException('forbidden', "Viewers can’t $what.", 403);
        }
    }

    protected function currentUser(Request $request): User
    {
        /** @var User $u */
        $u = $request->user();

        return $u;
    }
}
