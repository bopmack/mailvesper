<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A signup form: a hosted page, an embed, or plain HTML on the customer's site.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $name
 * @property string $public_key
 * @property string|null $list_id
 * @property list<string>|null $tags
 * @property list<array<string, mixed>>|null $fields {key, label, required, type, options, placeholder, width, step, value, param}
 * @property array<string, mixed>|null $design
 * @property array<string, mixed>|null $display
 * @property array<string, mixed>|null $draft edits not published yet (form columns → values); null: the draft is the live form
 * @property string|null $heading
 * @property string|null $intro
 * @property string|null $button
 * @property string|null $success_message
 * @property string|null $redirect_url
 * @property bool $double_opt_in
 * @property string|null $from_email
 * @property string|null $from_name
 * @property bool $enabled
 * @property Carbon|null $published_at null until the form first goes live
 * @property int $submissions
 * @property int $confirmed
 * @property Carbon|null $created_at
 */
class Form extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['tags' => 'array', 'fields' => 'array', 'design' => 'array', 'display' => 'array', 'draft' => 'array', 'published_at' => 'datetime', 'double_opt_in' => 'boolean', 'enabled' => 'boolean', 'submissions' => 'integer', 'confirmed' => 'integer'];
    }

    /** Settings that apply at once rather than through the draft: the name only you see, and open / closed. */
    public const IMMEDIATE = ['name', 'enabled'];

    /** What the builder edits: the live form with the draft's changes on top (not saved). */
    public function working(): self
    {
        $w = clone $this;

        return $this->draft ? $w->forceFill($this->draft) : $w;
    }

    /** "draft" (never published), "changed" (live, with unpublished edits) or "live". */
    public function state(): string
    {
        return $this->published_at === null ? 'draft' : ($this->draft ? 'changed' : 'live');
    }
}
