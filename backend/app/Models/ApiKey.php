<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $name
 * @property string $prefix
 * @property string $key_hash
 * @property string $scope
 * @property Carbon|null $last_used_at
 * @property string|null $last_used_ip
 * @property string|null $created_by
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 */
class ApiKey extends Model
{
    use HasUlids;

    public const SCOPES = ['send', 'full'];

    protected $guarded = ['id'];

    protected $hidden = ['key_hash'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public static function hash(string $key): string
    {
        return hash_hmac('sha256', $key, (string) config('app.key'));
    }

    /** @return array{0: self, 1: string} the model and the plain key (shown once) */
    public static function generate(string $workspaceId, string $name, string $scope, ?string $userId): array
    {
        $plain = 'sl_live_'.Str::random(40);
        $key = self::create(['workspace_id' => $workspaceId, 'name' => $name, 'scope' => $scope, 'prefix' => substr($plain, 0, 12), 'key_hash' => self::hash($plain), 'created_by' => $userId]);

        return [$key, $plain];
    }
}
