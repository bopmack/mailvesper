<?php

namespace App\Support;

use App\Models\User;
use App\Services\Platform\Settings;
use Illuminate\Support\Facades\DB;

/**
 * The installation's admins (the operator on Pro; Community's one admin login): the users named in the
 * platform.admins setting, or the first account when none are named.
 */
class Installation
{
    /** @return list<string> */
    public static function adminIds(): array
    {
        $named = array_values(array_filter(array_map('trim', explode(',', (string) Settings::get('platform.admins')))));
        if ($named) {
            $ids = DB::table('users')->whereIn('id', $named)->pluck('id')->map(fn ($id) => (string) $id)->all();
            if ($ids) {
                return array_values($ids);
            }
        }
        $first = DB::table('users')->orderBy('created_at')->orderBy('id')->value('id');

        return $first === null ? [] : [(string) $first];
    }

    /** The main admin (the first of them). */
    public static function adminId(): ?string
    {
        return self::adminIds()[0] ?? null;
    }

    public static function isAdmin(?User $user): bool
    {
        return $user !== null && in_array((string) $user->id, self::adminIds(), true);
    }

    public static function ownsAsAdmin(?string $userId): bool
    {
        return $userId !== null && in_array($userId, self::adminIds(), true);
    }
}
