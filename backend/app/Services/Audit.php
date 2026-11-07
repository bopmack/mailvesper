<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;

class Audit
{
    /** @param array<string, mixed> $meta */
    public static function log(string $action, ?User $user = null, ?string $workspaceId = null, array $meta = []): void
    {
        $request = request();

        AuditLog::create([
            'action' => $action,
            'user_id' => $user?->id,
            'workspace_id' => $workspaceId ?? $user?->current_workspace_id,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
            'meta' => $meta ?: null,
        ]);
    }
}
