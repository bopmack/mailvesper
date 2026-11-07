<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A mailing list. ("List" is a reserved word in PHP.)
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $name
 * @property string|null $description
 * @property Carbon|null $created_at
 */
class ContactList extends Model
{
    use HasUlids;

    protected $table = 'lists';

    protected $guarded = ['id'];
}
