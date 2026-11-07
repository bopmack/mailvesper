<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A website connected to a workspace through "Connect to MailVesper". Its users are a live audience: read from the
 * site when needed, never stored as contacts.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $site_url
 * @property string $name
 * @property string $redirect_uri
 * @property string|null $code_hash
 * @property string|null $code_challenge
 * @property Carbon|null $code_expires_at
 * @property string|null $api_key_id
 * @property string|null $audience_url
 * @property string|null $audience_secret
 * @property Carbon|null $audience_ok_at
 * @property string|null $audience_error
 * @property list<array{key: string, label: string, type: string}>|null $fields
 * @property string|null $created_by
 * @property Carbon|null $connected_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 */
class SiteConnection extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected $hidden = ['code_hash', 'code_challenge', 'audience_secret'];

    protected function casts(): array
    {
        return ['code_expires_at' => 'datetime', 'connected_at' => 'datetime', 'revoked_at' => 'datetime', 'audience_ok_at' => 'datetime', 'audience_secret' => 'encrypted', 'fields' => 'array'];
    }
}
