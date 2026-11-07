<?php

namespace App\Services\Ses;

/** Live DNS checks for a domain's records. Tests replace this with a fake. */
class DnsLookup
{
    /** @return list<string> normalised answers */
    public function lookup(string $type, string $host): array
    {
        $flag = ['CNAME' => DNS_CNAME, 'TXT' => DNS_TXT, 'MX' => DNS_MX, 'A' => DNS_A, 'AAAA' => DNS_AAAA][$type] ?? null;
        if ($flag === null) {
            return [];
        }
        $answers = @dns_get_record($host, $flag);
        if (! is_array($answers)) {
            return [];
        }

        $out = [];
        foreach ($answers as $a) {
            $out[] = match ($type) {
                'CNAME' => strtolower(rtrim((string) ($a['target'] ?? ''), '.')),
                'MX' => ($a['pri'] ?? '').' '.strtolower(rtrim((string) ($a['target'] ?? ''), '.')),
                'A' => (string) ($a['ip'] ?? ''),
                'AAAA' => (string) ($a['ipv6'] ?? ''),
                default => isset($a['entries']) && is_array($a['entries']) ? implode('', $a['entries']) : (string) ($a['txt'] ?? ''), // long TXT records come in parts
            };
        }

        return $out;
    }

    /**
     * found | missing | mismatch (a record exists at the host but says something else).
     *
     * @param  array{key: string, type: string, host: string, value: string}  $record
     */
    public function check(array $record): string
    {
        $answers = $this->lookup($record['type'], $record['host']);
        if (! $answers) {
            return 'missing';
        }
        $norm = fn (string $s) => strtolower(preg_replace('/\s+/', '', trim($s, '"')) ?? '');
        foreach ($answers as $a) {
            $ok = match (true) {
                $record['key'] === 'spf' => str_starts_with($norm($a), 'v=spf1') && str_contains($norm($a), 'include:amazonses.com'),
                $record['key'] === 'dmarc' => str_starts_with($norm($a), 'v=dmarc1'),
                default => $norm($a) === $norm($record['value']),
            };
            if ($ok) {
                return 'found';
            }
        }
        if ($record['key'] === 'spf' && ! array_filter($answers, fn ($a) => str_starts_with($norm($a), 'v=spf1'))) {
            return 'missing'; // other TXT records only
        }

        return 'mismatch';
    }
}
