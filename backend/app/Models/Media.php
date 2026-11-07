<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $filename
 * @property string $path
 * @property string $url
 * @property string $mime
 * @property int $size
 * @property int $width
 * @property int $height
 * @property string|null $created_by
 * @property Carbon|null $created_at
 */
class Media extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $table = 'media';

    protected $guarded = ['id'];
}
