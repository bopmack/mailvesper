<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One sign-up. Double opt-in ones wait as "pending" until the link in the confirmation email is used.
 *
 * @property string $id
 * @property string $form_id
 * @property string $workspace_id
 * @property string $email
 * @property array<string, string>|null $data
 * @property string|null $token_hash
 * @property string $status pending | confirmed | added
 * @property string|null $ip
 * @property Carbon $created_at
 * @property Carbon|null $confirmed_at
 */
class FormSubmission extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['data' => 'array', 'confirmed_at' => 'datetime'];
    }
}
