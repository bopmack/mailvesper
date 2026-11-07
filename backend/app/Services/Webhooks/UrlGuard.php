<?php

namespace App\Services\Webhooks;

/**
 * Webhook URLs must be public HTTPS endpoints. Checked when saved and again at every delivery,
 * pinning the IP we checked so DNS can't be switched to an internal address in between.
 */
class UrlGuard
{
    /** @var (callable(string): list<string>)|null test hook: host → IPs */
    public static $resolver = null;

    /** Error message, or null when the URL is acceptable. */
    public static function problem(string $url): ?string
    {
        return self::vet($url)[0];
    }

    /**
     * Checks the URL and returns the public IP that was checked, to connect to exactly that address (resolving again
     * would let a hostile DNS server answer with an internal address the second time).
     *
     * @return array{0: string|null, 1: string|null} [problem, vetted IP]
     */
    public static function vet(string $url): array
    {
        $p = parse_url($url);
        if (! $p || ($p['scheme'] ?? '') !== 'https' || empty($p['host'])) {
            return ['Use a full https:// address.', null];
        }
        if (isset($p['user']) || isset($p['pass'])) {
            return ['Don’t put a username or password in the URL. Verify the signature instead.', null];
        }
        if (isset($p['port']) && ! in_array((int) $p['port'], [443, 8443], true)) {
            return ['Use the standard HTTPS port (443) or 8443.', null];
        }
        $ips = self::resolve($p['host']);
        if (! $ips) {
            return ['We couldn’t find that host. Check the address.', null];
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return ['That address points to a private network. Use a public URL.', null];
            }
        }

        return [null, $ips[0]];
    }

    /** @return list<string> */
    public static function resolve(string $host): array
    {
        $host = trim($host, '[]');
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }
        if (self::$resolver) {
            return (self::$resolver)($host);
        }
        $ips = [];
        foreach ([DNS_A, DNS_AAAA] as $type) {
            foreach (@dns_get_record($host, $type) ?: [] as $r) {
                $ips[] = (string) ($r['ip'] ?? $r['ipv6'] ?? '');
            }
        }

        return array_values(array_filter(array_unique($ips)));
    }
}
