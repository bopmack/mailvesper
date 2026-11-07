<?php

namespace App\Services\Mailbox;

/** An open, read-only mailbox (the real IMAP client, or a fake in tests). */
interface MailboxConnection
{
    /** @return array{validity: int, next: int} the folder's UIDVALIDITY and the UID the next message will get */
    public function select(string $folder): array;

    /** @return list<int> UIDs from $from upwards, oldest first */
    public function uidsFrom(int $from): array;

    /** @return list<int> UIDs of messages whose subject contains $text (letters and digits only) */
    public function searchSubject(string $text): array;

    /** The whole message, or null when it's larger than $maxBytes. */
    public function fetch(int $uid, int $maxBytes = ImapClient::MAX_BYTES): ?string;

    public function close(): void;
}
