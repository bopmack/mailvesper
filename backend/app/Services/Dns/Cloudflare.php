<?php

namespace App\Services\Dns;

use App\Exceptions\ApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Adds a domain's sending records through the Cloudflare API, with a token the customer creates with the
 * "Edit zone DNS" template. Only adds what's missing or wrong: records are DNS-only (never proxied, which would
 * break DKIM), and an existing DMARC policy is left alone.
 */
class Cloudflare
{
    public const API = 'https://api.cloudflare.com/client/v4';

    /** Checks the token is valid and active. */
    public function verify(string $token): void
    {
        $r = $this->call($token, 'get', '/user/tokens/verify');
        if (($r['result']['status'] ?? null) !== 'active') {
            throw new ApiException('cloudflare_token', 'This Cloudflare token isn’t active.', 422);
        }
    }

    /** The zone that serves $name (the domain or a parent), or null. */
    public function zone(string $token, string $name): ?string
    {
        $labels = explode('.', mb_strtolower($name));
        for ($i = 0; $i < count($labels) - 1; $i++) {
            $candidate = implode('.', array_slice($labels, $i));
            $r = $this->call($token, 'get', '/zones', ['name' => $candidate, 'status' => 'active']);
            if ($id = $r['result'][0]['id'] ?? null) {
                return (string) $id;
            }
        }

        return null;
    }

    /**
     * Creates or corrects each record. keep_existing: skip when any record of that type is already at the host
     * (DMARC: never replace a policy someone chose). replace_prefix: the TXT record it may replace (an SPF record
     * replaces an SPF record; Copilot's DMARC fix replaces the DMARC record). A CNAME or MX at the host is replaced.
     *
     * @param  list<array{type: string, host: string, value: string, keep_existing?: bool, replace_prefix?: string}>  $records
     * @return int records written
     */
    public function apply(string $token, string $zone, array $records): int
    {
        $written = 0;
        foreach ($records as $rec) {
            $type = $rec['type'];
            [$priority, $content] = $type === 'MX' ? array_map('trim', explode(' ', $rec['value'], 2)) : [null, $rec['value']];
            $existing = $this->existing($token, $zone, $type, $rec['host']);
            $same = fn ($e) => rtrim(mb_strtolower(trim((string) $e['content'], '"')), '.') === rtrim(mb_strtolower($content), '.');
            if (array_filter($existing, $same) || (! empty($rec['keep_existing']) && $existing)) {
                continue;
            }
            $body = ['type' => $type, 'name' => $rec['host'], 'content' => $content, 'ttl' => 1, 'proxied' => false] + ($priority !== null ? ['priority' => (int) $priority] : []);
            $prefix = $rec['replace_prefix'] ?? null;
            $replace = null;
            foreach ($existing as $e) {
                if ($type !== 'TXT' || ($prefix !== null && str_starts_with(trim((string) $e['content'], '"'), $prefix))) {
                    $replace = $e;
                    break;
                }
            }
            $replace ? $this->call($token, 'put', "/zones/$zone/dns_records/{$replace['id']}", $body) : $this->call($token, 'post', "/zones/$zone/dns_records", $body);
            $written++;
        }

        return $written;
    }

    /**
     * The records of one type at a host.
     *
     * @return list<array{id: string, content: string}>
     */
    private function existing(string $token, string $zone, string $type, string $host): array
    {
        $out = [];
        foreach ($this->call($token, 'get', "/zones/$zone/dns_records", ['type' => $type, 'name' => $host, 'per_page' => 50])['result'] ?? [] as $r) {
            if (is_array($r) && isset($r['id'], $r['content'])) {
                $out[] = ['id' => (string) $r['id'], 'content' => (string) $r['content']];
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function call(string $token, string $method, string $path, array $data = []): array
    {
        try {
            $r = $this->http($token)->{$method}(self::API.$path, $data);
        } catch (ConnectionException) {
            throw new ApiException('cloudflare_unreachable', 'Couldn’t reach Cloudflare. Try again in a minute.', 503);
        }
        $json = $r->json() ?? [];
        if ($r->failed() || ($json['success'] ?? false) !== true) {
            $err = $json['errors'][0] ?? [];
            $msg = match (true) {
                $r->status() === 401 || in_array($err['code'] ?? null, [1000, 6003, 9109], true) => 'Cloudflare refused the token. Create one with the “Edit zone DNS” template and paste it again.',
                $r->status() === 403 || ($err['code'] ?? null) === 10000 => 'The token can’t edit this zone’s DNS. Give it “Zone → DNS → Edit” for this domain.',
                default => 'Cloudflare said: '.mb_substr((string) ($err['message'] ?? 'HTTP '.$r->status()), 0, 160),
            };
            throw new ApiException('cloudflare_failed', $msg, 422);
        }

        return $json;
    }

    private function http(string $token): PendingRequest
    {
        return Http::withToken($token)->acceptJson()->asJson()->timeout(15)->connectTimeout(8);
    }
}
