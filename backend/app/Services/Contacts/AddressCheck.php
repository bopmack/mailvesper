<?php

namespace App\Services\Contacts;

use Illuminate\Support\Facades\Cache;

/** Import-time checks: typos in big-provider domains, role addresses, and domains that can't receive mail. */
class AddressCheck
{
    public const TYPOS = [
        'gmial.com' => 'gmail.com', 'gmal.com' => 'gmail.com', 'gmaill.com' => 'gmail.com', 'gamil.com' => 'gmail.com', 'gnail.com' => 'gmail.com',
        'gmail.co' => 'gmail.com', 'gmail.cm' => 'gmail.com', 'gmai.com' => 'gmail.com', 'gmali.com' => 'gmail.com', 'gmail.om' => 'gmail.com',
        'hotmial.com' => 'hotmail.com', 'hotmal.com' => 'hotmail.com', 'hotmail.co' => 'hotmail.com', 'hotamil.com' => 'hotmail.com', 'hotmai.com' => 'hotmail.com',
        'yaho.com' => 'yahoo.com', 'yahooo.com' => 'yahoo.com', 'yhoo.com' => 'yahoo.com', 'yahoo.cm' => 'yahoo.com', 'yahoo.om' => 'yahoo.com',
        'outlok.com' => 'outlook.com', 'outllok.com' => 'outlook.com', 'outlook.co' => 'outlook.com', 'otlook.com' => 'outlook.com',
        'iclod.com' => 'icloud.com', 'icoud.com' => 'icloud.com', 'icloud.co' => 'icloud.com', 'iclould.com' => 'icloud.com',
    ];

    public const ROLES = ['admin', 'administrator', 'info', 'support', 'sales', 'contact', 'help', 'noreply', 'no-reply', 'donotreply', 'do-not-reply',
        'postmaster', 'webmaster', 'hostmaster', 'abuse', 'billing', 'mailer-daemon', 'root', 'security', 'privacy', 'compliance'];

    /** Domains we know receive mail, so we skip the DNS lookup. */
    private const KNOWN = ['gmail.com', 'googlemail.com', 'yahoo.com', 'outlook.com', 'hotmail.com', 'live.com', 'msn.com', 'icloud.com', 'me.com', 'mac.com', 'aol.com', 'proton.me', 'protonmail.com', 'ymail.com', 'gmx.com', 'gmx.de', 'yandex.com', 'mail.com', 'zoho.com'];

    /** @var (callable(string): bool)|null test hook: domain → can receive mail */
    public static $resolver = null;

    /** @return array{0: string, 1: string|null}|null [reason, suggestion] when the address shouldn't be imported */
    public function problem(string $email, bool $allowRoles): ?array
    {
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
            return ['invalid', null];
        }
        [$local, $domain] = explode('@', $email, 2);
        if (isset(self::TYPOS[$domain])) {
            return ['typo', $local.'@'.self::TYPOS[$domain]];
        }
        if (str_ends_with($domain, '.con')) {
            return ['typo', $local.'@'.substr($domain, 0, -4).'.com'];
        }
        if (! $allowRoles && in_array(strtok($local, '+'), self::ROLES, true)) {
            return ['role', null];
        }
        if (! $this->receivesMail($domain)) {
            return ['no_mail_server', null];
        }

        return null;
    }

    public function receivesMail(string $domain): bool
    {
        if (in_array($domain, self::KNOWN, true)) {
            return true;
        }
        if (self::$resolver) {
            return (self::$resolver)($domain);
        }

        return (bool) Cache::remember('mx-ok:'.$domain, 86400, function () use ($domain) {
            if (@dns_get_record($domain, DNS_MX)) {
                return 1;
            }

            return @dns_get_record($domain, DNS_A) || @dns_get_record($domain, DNS_AAAA) ? 1 : 0; // RFC 5321: no MX falls back to A/AAAA
        });
    }
}
