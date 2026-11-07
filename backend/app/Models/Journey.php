<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $name
 * @property string $status
 * @property array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>} $graph
 * @property array<string, mixed>|null $settings
 * @property array<string, mixed>|null $test_data the editor's pinned test input: {contact_id, trigger}
 * @property int $draft_rev
 * @property string|null $live_version_id
 * @property int|null $live_rev
 * @property string $hook_token
 * @property string $secret
 * @property string|null $created_by
 * @property Carbon|null $activated_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Journey extends Model
{
    use HasUlids;

    public const STATUSES = ['draft', 'active', 'paused', 'archived'];

    protected $guarded = ['id'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return ['graph' => 'array', 'settings' => 'array', 'test_data' => 'array', 'draft_rev' => 'integer', 'live_rev' => 'integer', 'activated_at' => 'datetime'];
    }

    /** @return array{reentry: string, cooldown_days: int, timezone: string} */
    public function opts(): array
    {
        $s = $this->settings ?? [];

        return ['reentry' => in_array($s['reentry'] ?? null, ['once', 'cooldown', 'always'], true) ? $s['reentry'] : 'once',
            'cooldown_days' => max(1, min(365, (int) ($s['cooldown_days'] ?? 30))),
            'timezone' => is_string($s['timezone'] ?? null) && in_array($s['timezone'], timezone_identifiers_list(), true) ? $s['timezone'] : 'UTC'];
    }
}
