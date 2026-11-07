<?php

namespace App\Services\Mailbox;

use App\Models\Mailbox;
use App\Services\Platform\Settings;

/**
 * A mailbox the installation itself reads (not a workspace's), set in Platform → Settings under one prefix:
 * <prefix>.address, .imap_host, .imap_port, .imap_username, .imap_password. Used by setup checks ("checks") and
 * complaint feedback loops ("fbl").
 */
class PlatformMailbox
{
    public static function configured(string $prefix): bool
    {
        return (bool) Settings::get("$prefix.address") && (bool) Settings::get("$prefix.imap_host") && (bool) Settings::get("$prefix.imap_password");
    }

    /** An unsaved Mailbox for ImapClient::connect(). */
    public static function make(string $prefix): Mailbox
    {
        $port = (int) (Settings::get("$prefix.imap_port") ?: 993);

        return new Mailbox(['host' => (string) Settings::get("$prefix.imap_host"), 'port' => $port, 'encryption' => $port === 143 ? 'tls' : 'ssl',
            'username' => (string) (Settings::get("$prefix.imap_username") ?: Settings::get("$prefix.address")),
            'password' => (string) Settings::get("$prefix.imap_password"), 'folder' => 'INBOX']);
    }
}
