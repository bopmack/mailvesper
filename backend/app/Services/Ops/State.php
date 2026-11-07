<?php

namespace App\Services\Ops;

use Illuminate\Support\Facades\DB;

/** Small operational facts that must survive restarts: the scheduler heartbeat, alert states, the last drill. */
class State
{
    public static function get(string $key): mixed
    {
        $v = DB::table('ops_state')->where('key', $key)->value('value');

        return $v === null ? null : json_decode((string) $v, true);
    }

    public static function put(string $key, mixed $value): void
    {
        DB::table('ops_state')->updateOrInsert(['key' => $key], ['value' => json_encode($value), 'updated_at' => now()]);
    }

    public static function forget(string $key): void
    {
        DB::table('ops_state')->where('key', $key)->delete();
    }
}
