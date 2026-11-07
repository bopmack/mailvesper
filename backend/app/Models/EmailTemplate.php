<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $name
 * @property string|null $subject
 * @property string|null $category
 * @property array<string, mixed> $doc
 * @property string $html
 * @property float $size_kb
 * @property list<array{name: string, status: string, detail: string}>|null $checks
 * @property string|null $created_by
 * @property string|null $updated_by
 * @property Carbon|null $last_used_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class EmailTemplate extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['doc' => 'array', 'checks' => 'array', 'size_kb' => 'float', 'last_used_at' => 'datetime'];
    }
}
