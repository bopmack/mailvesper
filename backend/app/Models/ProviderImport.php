<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $provider
 * @property string $status
 * @property array<string, mixed> $credentials
 * @property string|null $account_name
 * @property array<string, mixed>|null $discovery
 * @property array<string, mixed>|null $options
 * @property string|null $stage
 * @property array<string, mixed>|null $cursor
 * @property array<string, array{done: int, total: int|null, skipped: int}>|null $progress
 * @property array<string, mixed>|null $state
 * @property list<string>|null $notes
 * @property string|null $error
 * @property int $attempts
 * @property string|null $created_by
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon $created_at
 */
class ProviderImport extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array', 'discovery' => 'array', 'options' => 'array', 'cursor' => 'array', 'progress' => 'array', 'state' => 'array', 'notes' => 'array',
            'started_at' => 'datetime', 'finished_at' => 'datetime', 'attempts' => 'integer'];
    }
}
