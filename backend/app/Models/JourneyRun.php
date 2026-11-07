<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $journey_id
 * @property string $version_id
 * @property string $workspace_id
 * @property string $contact_id
 * @property string $status
 * @property string|null $current_node
 * @property string|null $trigger_node
 * @property string|null $armed
 * @property string|null $signal
 * @property Carbon|null $wake_at
 * @property string|null $waiting_for
 * @property string|null $dedupe_key
 * @property array<string, mixed>|null $context
 * @property array<string, mixed>|null $data the outputs of the steps it passed, by step id (steps.<id>.…)
 * @property string|null $exit_reason
 * @property Carbon $entered_at
 * @property Carbon|null $finished_at
 */
class JourneyRun extends Model
{
    use HasUlids;

    public const OPEN = ['waiting', 'running'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['context' => 'array', 'data' => 'array', 'wake_at' => 'datetime', 'entered_at' => 'datetime', 'finished_at' => 'datetime'];
    }
}
