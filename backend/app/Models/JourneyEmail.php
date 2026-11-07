<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $journey_id
 * @property string $node_id
 * @property string|null $subject
 * @property string|null $preheader
 * @property string|null $from_email
 * @property string|null $from_name
 * @property string|null $reply_to
 * @property array<string, mixed>|null $doc
 * @property string|null $html
 * @property float|null $size_kb
 * @property list<array{name: string, status: string, detail: string}>|null $checks
 */
class JourneyEmail extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['doc' => 'array', 'checks' => 'array', 'size_kb' => 'float'];
    }
}
