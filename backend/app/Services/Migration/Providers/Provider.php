<?php

namespace App\Services\Migration\Providers;

/**
 * One email provider, read-only. Every method returns data in MailVesper's shape:
 *  suppressions: {email, reason: unsubscribe|bounce|complaint, detail}
 *  lists:        {ext_id, name, count?}
 *  contacts:     {email, first_name, last_name, fields{}, tags[], lists[ext_id], status: subscribed|unsubscribed|bounced|complained|pending}
 *  templates:    {name, subject, html}   (merge tags still in the provider's syntax; the importer converts them)
 * fetch() returns one page and the cursor for the next (null when the stage is finished).
 */
interface Provider
{
    /** @return array{name: string} the account, or throws ProviderError (bad key etc.) */
    public function account(): array;

    /** @return array{contacts: int|null, suppressions: int|null, lists: list<array{ext_id: string, name: string, count: int|null}>, templates: int|null, notes: list<string>} */
    public function discover(): array;

    /** @return list<string> stages this provider supports, in import order (suppressions first) */
    public function stages(): array;

    /**
     * @param  array<string, mixed>|null  $cursor
     * @param  array<string, mixed>  $options
     * @return array{items: list<array<string, mixed>>, cursor: array<string, mixed>|null, total?: int|null}
     */
    public function fetch(string $stage, ?array $cursor, array $options): array;

    /** Converts this provider's merge tags to MailVesper's ({{ first_name }}, {{unsubscribe_url}}…). */
    public function convertMergeTags(string $html): string;
}
