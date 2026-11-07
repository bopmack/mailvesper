<?php

namespace App\Services\Deliverability;

use App\Services\Ses\DnsLookup;

/**
 * SPF (RFC 7208) as a receiving server evaluates it: is $ip allowed to send for $domain?
 * Supports ip4, ip6, a, mx, include, exists, all, redirect, with the 10-lookup limit. Results: pass, fail,
 * softfail, neutral, none, permerror, temperror.
 */
class Spf
{
    private int $lookups = 0;

    public function __construct(private DnsLookup $dns) {}

    /** @return array{result: string, record: string|null, detail: string} */
    public function check(string $ip, string $domain): array
    {
        $this->lookups = 0;
        $record = $this->record($domain);
        if ($record === null) {
            return ['result' => 'none', 'record' => null, 'detail' => "$domain has no SPF record."];
        }
        try {
            [$result, $detail] = $this->evaluate($ip, $domain, $record, 0);
        } catch (SpfError $e) {
            return ['result' => 'permerror', 'record' => $record, 'detail' => $e->getMessage()];
        }

        return ['result' => $result, 'record' => $record, 'detail' => $detail];
    }

    private function record(string $domain): ?string
    {
        $found = array_values(array_filter($this->dns->lookup('TXT', $domain), fn ($t) => preg_match('/^v=spf1(\s|$)/i', trim((string) $t, '" '))));

        return $found ? trim((string) $found[0], '" ') : null;
    }

    /** @return array{0: string, 1: string} */
    private function evaluate(string $ip, string $domain, string $record, int $depth): array
    {
        if ($depth > 10) {
            throw new SpfError('SPF includes nest too deeply.');
        }
        $terms = preg_split('/\s+/', trim($record)) ?: [];
        array_shift($terms); // v=spf1
        $redirect = null;
        foreach ($terms as $term) {
            if (preg_match('/^redirect=(.+)$/i', $term, $m)) {
                $redirect = $m[1];

                continue;
            }
            if (str_contains($term, '=')) {
                continue; // exp= and unknown modifiers
            }
            $qualifier = in_array($term[0] ?? '', ['+', '-', '~', '?'], true) ? $term[0] : '+';
            $mech = ltrim($term, '+-~?');
            if ($this->matches($ip, $domain, $mech, $depth)) {
                return [['+' => 'pass', '-' => 'fail', '~' => 'softfail', '?' => 'neutral'][$qualifier], "$ip matches “{$term}” in the SPF record of $domain."];
            }
        }
        if ($redirect) {
            $this->count();
            $next = $this->record($redirect) ?? throw new SpfError("$domain redirects SPF to $redirect, which has no SPF record.");

            return $this->evaluate($ip, $redirect, $next, $depth + 1);
        }

        return ['neutral', "$ip isn’t listed in the SPF record of $domain."];
    }

    private function matches(string $ip, string $domain, string $mech, int $depth): bool
    {
        [$name, $arg] = array_pad(explode(':', $mech, 2), 2, null);
        $cidr = null;
        if ($arg === null && str_contains($name, '/')) { // "a/24", "mx/24"
            [$name, $cidr] = explode('/', $name, 2);
        } elseif ($arg !== null && in_array(strtolower($name), ['a', 'mx'], true) && str_contains($arg, '/')) {
            [$arg, $cidr] = explode('/', $arg, 2);
        }
        $name = strtolower($name);
        $v6 = str_contains($ip, ':');

        switch ($name) {
            case 'all':
                return true;
            case 'ip4':
                return ! $v6 && $arg !== null && self::inRange($ip, $arg);
            case 'ip6':
                return $v6 && $arg !== null && self::inRange($ip, $arg);
            case 'a':
                $this->count();
                foreach ($this->dns->lookup($v6 ? 'AAAA' : 'A', $arg ?: $domain) as $a) {
                    if (self::inRange($ip, $a.($cidr ? '/'.$cidr : ''))) {
                        return true;
                    }
                }

                return false;
            case 'mx':
                $this->count();
                foreach (array_slice($this->dns->lookup('MX', $arg ?: $domain), 0, 10) as $mx) {
                    $host = trim((string) (explode(' ', (string) $mx, 2)[1] ?? $mx), '.');
                    foreach ($this->dns->lookup($v6 ? 'AAAA' : 'A', $host) as $a) {
                        if (self::inRange($ip, $a.($cidr ? '/'.$cidr : ''))) {
                            return true;
                        }
                    }
                }

                return false;
            case 'include':
                $this->count();
                $rec = $this->record((string) $arg) ?? throw new SpfError("SPF includes $arg, which has no SPF record.");
                [$res] = $this->evaluate($ip, (string) $arg, $rec, $depth + 1);

                return $res === 'pass';
            case 'exists':
                $this->count();

                return (bool) $this->dns->lookup('A', str_replace(['%{i}', '%{d}'], [$ip, $domain], (string) $arg));
            case 'ptr':
                return false; // deprecated, and rarely what matters
            default:
                throw new SpfError("The SPF record of $domain has a part receivers don’t understand: “{$name}”.");
        }
    }

    private function count(): void
    {
        if (++$this->lookups > 10) {
            throw new SpfError('The SPF record needs more than 10 DNS lookups, so receivers treat it as broken (permerror). Flatten some includes.');
        }
    }

    public static function inRange(string $ip, string $range): bool
    {
        [$net, $bits] = array_pad(explode('/', $range, 2), 2, null);
        $a = @inet_pton($ip);
        $b = @inet_pton((string) $net);
        if ($a === false || $b === false || strlen($a) !== strlen($b)) {
            return false;
        }
        $bits = $bits === null ? strlen($a) * 8 : max(0, min(strlen($a) * 8, (int) $bits));
        $bytes = intdiv($bits, 8);
        if (strncmp($a, $b, $bytes) !== 0) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($a[$bytes]) & $mask) === (ord($b[$bytes]) & $mask);
    }
}
