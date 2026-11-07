<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string|null $user_id
 * @property string $filename
 * @property string $path
 * @property string $status
 * @property int $total_rows
 * @property int $processed_rows
 * @property list<string>|null $columns
 * @property array<int, string>|null $mapping
 * @property array<string, mixed>|null $options
 * @property array<string, int>|null $stats
 * @property string|null $error
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 */
class ContactImport extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['columns' => 'array', 'mapping' => 'array', 'options' => 'array', 'stats' => 'array', 'total_rows' => 'integer', 'processed_rows' => 'integer',
            'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }
}
