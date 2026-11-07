<?php

namespace App\Services\Deliverability;

use App\Services\Ses\DnsLookup;

/**
 * Verifies the DKIM signatures on a raw email (RFC 6376), the way a receiving server does: body hash, then the
 * signature over the signed headers with the public key published at <selector>._domainkey.<domain>.
 * Supports rsa-sha256 and rsa-sha1 with simple or relaxed canonicalization.
 */
class Dkim
{
    public function __construct(private DnsLookup $dns) {}

    /** @return list<array{domain: string, selector: string, result: string, detail: string}> */
    public function verify(string $raw): array
    {
        $raw = (string) preg_replace("/\r?\n/", "\r\n", $raw);
        $split = strpos($raw, "\r\n\r\n");
        $head = $split === false ? $raw : substr($raw, 0, $split + 2);
        $body = $split === false ? '' : substr($raw, $split + 4);
        $headers = self::headers($head);
        $out = [];
        foreach ($headers as $i => [$name, $value, $line]) {
            if (strcasecmp($name, 'DKIM-Signature') === 0) {
                $out[] = $this->one($headers, $i, $line, $value, $body);
            }
        }

        return $out;
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string}>  $headers  [name, value, the whole raw header]
     * @return array{domain: string, selector: string, result: string, detail: string}
     */
    private function one(array $headers, int $sigIndex, string $sigLine, string $sigValue, string $body): array
    {
        $tags = self::tags($sigValue);
        $d = strtolower($tags['d'] ?? '');
        $s = $tags['s'] ?? '';
        $res = fn (string $r, string $why) => ['domain' => $d, 'selector' => $s, 'result' => $r, 'detail' => $why];
        if (($tags['v'] ?? '') !== '1' || $d === '' || $s === '' || empty($tags['b']) || empty($tags['bh']) || empty($tags['h'])) {
            return $res('permerror', 'The DKIM signature is missing required parts.');
        }
        $algo = strtolower($tags['a'] ?? '');
        $hash = ['rsa-sha256' => ['sha256', OPENSSL_ALGO_SHA256], 'rsa-sha1' => ['sha1', OPENSSL_ALGO_SHA1]][$algo] ?? null;
        if (! $hash) {
            return $res('permerror', "Signed with “{$algo}”, which this check doesn’t verify.");
        }
        [$hc, $bc] = array_pad(explode('/', strtolower($tags['c'] ?? 'simple/simple'), 2), 2, 'simple');

        // Body hash
        $canonBody = $bc === 'relaxed' ? self::relaxedBody($body) : self::simpleBody($body);
        if (isset($tags['l']) && ctype_digit($tags['l'])) {
            $canonBody = substr($canonBody, 0, (int) $tags['l']);
        }
        if (! hash_equals(base64_encode(hash($hash[0], $canonBody, true)), preg_replace('/\s+/', '', $tags['bh']))) {
            return $res('fail', 'The body changed after it was signed (body hash mismatch): a server on the way altered the email, or the signature is for another message.');
        }

        // Signed headers, each name matched from the bottom up, then the signature header with an empty b=
        $used = [];
        $data = '';
        foreach (array_map('trim', explode(':', $tags['h'])) as $want) {
            for ($i = count($headers) - 1; $i >= 0; $i--) {
                if (! isset($used[$i]) && $i !== $sigIndex && strcasecmp($headers[$i][0], $want) === 0) {
                    $used[$i] = true;
                    $data .= $hc === 'relaxed' ? self::relaxedHeader($headers[$i][0], $headers[$i][1]) : $headers[$i][2];
                    break;
                }
            }
        }
        $emptyB = (string) preg_replace('/(^|[;\s])(b\s*=)[^;]*/i', '$1$2', $hc === 'relaxed' ? $sigValue : $sigLine);
        $data .= $hc === 'relaxed' ? rtrim(self::relaxedHeader('DKIM-Signature', $emptyB), "\r\n") : rtrim($emptyB, "\r\n");

        // Public key
        $txt = $this->dns->lookup('TXT', "$s._domainkey.$d");
        $key = null;
        foreach ($txt as $t) {
            $k = self::tags(str_replace('" "', '', trim((string) $t, '"')));
            if (isset($k['p'])) {
                $key = $k;
                break;
            }
        }
        if (! $key) {
            return $res('permerror', "No DKIM key is published at $s._domainkey.$d.");
        }
        if (trim($key['p']) === '') {
            return $res('fail', "The DKIM key at $s._domainkey.$d has been revoked (empty p=).");
        }
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split((string) preg_replace('/\s+/', '', $key['p']), 64, "\n")."-----END PUBLIC KEY-----\n";
        $ok = @openssl_verify($data, (string) base64_decode((string) preg_replace('/\s+/', '', $tags['b'])), $pem, $hash[1]);

        return $ok === 1 ? $res('pass', "Signed by $d (selector $s) and the signature is valid.")
            : $res('fail', $ok === 0 ? "The signature doesn’t match the key published at $s._domainkey.$d: the email was changed after signing, or the key in DNS isn’t the one the server signs with." : "The key at $s._domainkey.$d couldn’t be read.");
    }

    /** @return list<array{0: string, 1: string, 2: string}> name, value (unfolded as-is), and the raw header with its CRLF */
    public static function headers(string $head): array
    {
        $out = [];
        foreach (preg_split('/\r\n(?![ \t])/', rtrim($head, "\r\n")) ?: [] as $h) {
            if ($h === '' || ! str_contains($h, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $h, 2);
            $out[] = [$name, $value, $h."\r\n"];
        }

        return $out;
    }

    /** @return array<string, string> */
    public static function tags(string $value): array
    {
        $out = [];
        foreach (explode(';', $value) as $part) {
            if (str_contains($part, '=')) {
                [$k, $v] = explode('=', $part, 2);
                $out[strtolower(trim($k))] = trim((string) preg_replace('/\s+/', ' ', $v));
            }
        }

        return $out;
    }

    public static function relaxedHeader(string $name, string $value): string
    {
        $value = (string) preg_replace('/\r\n(?=[ \t])/', '', $value);
        $value = trim((string) preg_replace('/[ \t]+/', ' ', $value));

        return strtolower(trim($name)).':'.$value."\r\n";
    }

    public static function simpleBody(string $body): string
    {
        $body = (string) preg_replace('/(\r\n)+$/', '', $body);

        return $body."\r\n";
    }

    public static function relaxedBody(string $body): string
    {
        $lines = explode("\r\n", $body);
        $lines = array_map(fn ($l) => rtrim((string) preg_replace('/[ \t]+/', ' ', $l), " \t"), $lines);
        while ($lines && end($lines) === '') {
            array_pop($lines);
        }

        return $lines ? implode("\r\n", $lines)."\r\n" : '';
    }
}
