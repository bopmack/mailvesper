<?php

namespace App\Services\Activity;

/** Pure rules: which mailbox provider, and whether an open or click came from a machine. */
class Signals
{
    public const PROVIDERS = ['google' => 'Gmail / Google', 'microsoft' => 'Outlook / Microsoft', 'yahoo' => 'Yahoo / AOL', 'apple' => 'iCloud', 'proton' => 'Proton', 'other' => 'Other'];

    /** Security gateways and link checkers that fetch images or follow links before a person does. */
    private const SCANNERS = '/bot\b|crawler|spider|curl\/|wget|python|java\/|go-http|okhttp|headless|phantom|scanner|barracuda|mimecast|proofpoint|safelinks|symantec|trend ?micro|fortinet|forcepoint|sophos|zscaler|cloudmark|ironport|messagelabs|appriver|checkpoint|libwww|httpclient|preview/i';

    /** Seconds after delivery within which an open/click is almost certainly automated. */
    public const SCANNER_WINDOW = 2;

    public static function providerFromDomain(string $email): string
    {
        $d = strtolower(substr($email, (int) strrpos($email, '@') + 1));

        return match (true) {
            in_array($d, ['gmail.com', 'googlemail.com'], true) => 'google',
            (bool) preg_match('/^(outlook|hotmail|live|msn|windowslive)\.[a-z.]+$/', $d) => 'microsoft',
            (bool) preg_match('/^(yahoo|ymail|rocketmail|aol|aim)\.[a-z.]+$/', $d) => 'yahoo',
            in_array($d, ['icloud.com', 'me.com', 'mac.com'], true) => 'apple',
            (bool) preg_match('/^(proton\.me|protonmail\.(com|ch)|pm\.me)$/', $d) => 'proton',
            default => 'other',
        };
    }

    /** The receiving server's reply names its operator even for company domains (Google Workspace, Microsoft 365). */
    public static function providerFromDelivery(string $smtpResponse, string $email): string
    {
        $r = strtolower($smtpResponse);

        return match (true) {
            str_contains($r, 'gsmtp') || str_contains($r, 'google') => 'google',
            str_contains($r, 'outlook.com') || str_contains($r, 'microsoft') || str_contains($r, 'hotmail') => 'microsoft',
            str_contains($r, 'yahoo') => 'yahoo',
            default => self::providerFromDomain($email),
        };
    }

    /**
     * Why an open is not a person: 'apple_privacy', 'scanner', or null for a human open.
     * Apple Mail Privacy Protection fetches images through Apple's proxy with a bare "Mozilla/5.0" user agent.
     */
    public static function machineOpen(string $userAgent, ?float $secondsAfterDelivery, ?string $ip = null): ?string
    {
        $ua = trim($userAgent);
        if (str_contains($ua, 'GoogleImageProxy') || str_contains($ua, 'YahooMailProxy')) {
            return null; // the provider proxies the image when the person opens the email
        }
        // Mail providers' security scanners fetch new mail with a frozen, years-old browser signature (Chrome 42 /
        // Edge 12), and Gmail's from Google's own addresses: a person on Gmail comes through GoogleImageProxy above.
        if (str_contains($ua, 'Edge/12.246') || ($ip !== null && self::googleIp($ip))) {
            return 'scanner';
        }
        if ($ua === 'Mozilla/5.0') {
            return 'apple_privacy';
        }
        if ($ua === '' || preg_match(self::SCANNERS, $ua) || ($secondsAfterDelivery !== null && $secondsAfterDelivery < self::SCANNER_WINDOW)) {
            return 'scanner';
        }

        return null;
    }

    /** Google's mail and crawler networks (opens from here that aren't GoogleImageProxy are machines). */
    public static function googleIp(string $ip): bool
    {
        $long = ip2long($ip);
        if ($long === false) {
            return false;
        }
        foreach (['66.249.64.0/19', '64.233.160.0/19', '72.14.192.0/18', '74.125.0.0/16', '108.177.0.0/17', '209.85.128.0/17', '172.217.0.0/16', '173.194.0.0/16', '216.58.192.0/19', '142.250.0.0/15'] as $cidr) {
            [$net, $bits] = explode('/', $cidr);
            $mask = -1 << (32 - (int) $bits);
            if (($long & $mask) === (ip2long($net) & $mask)) {
                return true;
            }
        }

        return false;
    }

    /** Whether a click came from a link scanner rather than a person. */
    public static function botClick(string $userAgent, ?float $secondsAfterDelivery, int $clicksSameSecond): bool
    {
        return trim($userAgent) === ''
            || (bool) preg_match(self::SCANNERS, $userAgent)
            || ($secondsAfterDelivery !== null && $secondsAfterDelivery < self::SCANNER_WINDOW)
            || $clicksSameSecond >= 2; // scanners follow every link at once
    }
}
