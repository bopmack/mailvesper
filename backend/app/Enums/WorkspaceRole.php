<?php

namespace App\Enums;

enum WorkspaceRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';
    case Viewer = 'viewer';

    public function canManageTeam(): bool
    {
        return $this === self::Owner || $this === self::Admin;
    }

    /** Which roles this role may hand out (by invite or role change). Ownership moves only by transfer. */
    public function canAssign(self $role): bool
    {
        return match ($this) {
            self::Owner => $role !== self::Owner,
            self::Admin => $role === self::Member || $role === self::Viewer,
            default => false,
        };
    }

    /** Whether this role may change or remove someone who currently has $target. */
    public function canManage(self $target): bool
    {
        return match ($this) {
            self::Owner => $target !== self::Owner,
            self::Admin => $target === self::Member || $target === self::Viewer,
            default => false,
        };
    }
}
