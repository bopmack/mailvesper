<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $journey_id
 * @property int $number
 * @property array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>} $graph
 * @property array<string, array<string, mixed>> $emails
 * @property list<array<string, mixed>> $triggers
 * @property string|null $published_by
 * @property Carbon $created_at
 */
class JourneyVersion extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['graph' => 'array', 'emails' => 'array', 'triggers' => 'array', 'number' => 'integer'];
    }
}
