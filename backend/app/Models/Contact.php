<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $email
 * @property string|null $first_name
 * @property string|null $last_name
 * @property array<string, mixed>|null $fields
 * @property string $status
 * @property string $source
 * @property string|null $import_id
 * @property int|null $engagement
 * @property bool $dormant
 * @property Carbon|null $last_sent_at
 * @property Carbon|null $last_opened_at
 * @property Carbon|null $last_clicked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Contact extends Model
{
    use HasUlids;

    public const STATUSES = ['subscribed', 'unsubscribed', 'bounced', 'complained'];

    /** Suppression reason → contact status. */
    public const FROM_SUPPRESSION = ['hard_bounce' => 'bounced', 'complaint' => 'complained', 'unsubscribe' => 'unsubscribed', 'manual' => 'unsubscribed'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['fields' => 'array', 'engagement' => 'integer', 'dormant' => 'boolean', 'last_sent_at' => 'datetime', 'last_opened_at' => 'datetime', 'last_clicked_at' => 'datetime'];
    }

    /** @return BelongsToMany<ContactList, $this> */
    public function lists(): BelongsToMany
    {
        return $this->belongsToMany(ContactList::class, 'contact_list', 'contact_id', 'list_id');
    }

    public function name(): string
    {
        return trim(($this->first_name ?? '').' '.($this->last_name ?? ''));
    }
}
