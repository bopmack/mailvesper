<?php

namespace App\Services\Journeys;

use App\Http\Controllers\Api\V1\Contacts\SegmentController;
use App\Models\Contact;
use App\Models\Journey;
use App\Models\JourneyRun;
use App\Services\Webhooks\UrlGuard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * n8n's core nodes, for journeys that move one contact at a time: Switch (a path per case), Filter (go on only if),
 * Edit fields (values for later steps), Date & Time, HTTP Request, Stop and error, and Human approval (a teammate
 * approves or rejects from an email). Each returns the engine's step result.
 */
class JourneyCore
{
    public const DATE_OPS = ['now', 'add', 'subtract', 'format', 'diff'];

    public const DATE_UNITS = ['minutes' => 'minutes', 'hours' => 'hours', 'days' => 'days', 'weeks' => 'weeks', 'months' => 'months'];

    /** format key → PHP date format (iso: ISO 8601 in UTC). */
    public const FORMATS = ['iso' => null, 'date' => 'Y-m-d', 'datetime' => 'Y-m-d H:i', 'time' => 'H:i', 'weekday' => 'l', 'long' => 'j F Y', 'short' => 'j M'];

    /** Seconds a teammate has to answer, at most, is the step's own setting; links stop working after it. */
    public const APPROVAL_KEY = 'approval:';

    /**
     * @param  array<string, mixed>  $c
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function switch(array $c, array $data): array
    {
        $value = JourneyData::resolve((string) $c['value'], $data);
        foreach ((array) $c['cases'] as $i => $k) {
            if (Conditions::compare($value === '' ? null : $value, (string) $k['op'], (string) $k['value'])) {
                $label = self::caseName($k, $i);

                return ['branch' => $k['id'], 'outcome' => $k['id'], 'detail' => $label, 'output' => ['value' => $value, 'matched' => $label]];
            }
        }

        return ['branch' => 'other', 'outcome' => 'other', 'detail' => 'No case matched', 'output' => ['value' => $value, 'matched' => null]];
    }

    /** @param array<string, mixed> $k */
    public static function caseName(array $k, int $i): string
    {
        return $k['name'] !== '' ? (string) $k['name'] : 'Case '.($i + 1);
    }

    /**
     * Go on when the conditions match; otherwise the run ends here, as filtered out.
     *
     * @param  array<string, mixed>  $c
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function filter(Journey $j, Contact $contact, array $c, array $data): array
    {
        $each = Conditions::explain($contact, $j->id, $c['rules'], SegmentController::customTypes($j->workspace_id), $data);
        $pass = $c['rules']['match'] === 'any' ? in_array(true, $each, true) : ! in_array(false, $each, true);

        return $pass ? ['outcome' => 'passed', 'output' => ['passed' => true, 'conditions' => $each]]
            : ['end' => 'completed', 'outcome' => 'filtered', 'detail' => 'Didn’t pass the filter', 'output' => ['passed' => false, 'conditions' => $each]];
    }

    /**
     * @param  array<string, mixed>  $c
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function set(array $c, array $data): array
    {
        $out = [];
        foreach ((array) $c['fields'] as $f) {
            $v = JourneyData::resolve((string) $f['value'], $data);
            $out[$f['name']] = $f['type'] === 'text' ? $v : JourneyAi::coerce($v, (string) $f['type']);
        }

        return ['outcome' => 'done', 'output' => $out];
    }

    /**
     * @param  array<string, mixed>  $c
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function datetime(array $c, array $data, string $tz): array
    {
        try {
            $base = $c['op'] === 'now' ? now() : self::parseDate(JourneyData::resolve((string) $c['value'], $data), $tz);
        } catch (Throwable) {
            $base = null;
        }
        if (! $base) {
            $raw = mb_substr(JourneyData::resolve((string) $c['value'], $data), 0, 80);

            return ['outcome' => 'failed', 'detail' => 'Not a date: “'.$raw.'”', 'output' => ['error' => 'Not a date', 'value' => $raw]];
        }
        $local = $base->copy()->setTimezone($tz);
        switch ($c['op']) {
            case 'add':
            case 'subtract':
                $unit = (string) $c['unit'];
                $amount = (int) $c['amount'];
                $local = $unit === 'months' // 31 January + 1 month is 28/29 February, not March
                    ? ($c['op'] === 'add' ? $local->addMonthsNoOverflow($amount) : $local->subMonthsNoOverflow($amount))
                    : ($c['op'] === 'add' ? $local->add($unit, $amount) : $local->sub($unit, $amount));
                break;
            case 'diff':
                $unit = (string) $c['unit'];
                $diff = (int) now()->setTimezone($tz)->startOfMinute()->diffInUnit($unit, $local->copy()->startOfMinute(), false);

                return ['outcome' => 'done', 'detail' => "$diff $unit from now", 'output' => ['result' => $diff, 'unit' => $unit, 'date' => $local->toIso8601String(), 'past' => $diff < 0]];
        }
        $fmt = self::FORMATS[$c['format']] ?? null;
        $result = $fmt === null ? $local->copy()->utc()->toIso8601ZuluString() : $local->format($fmt);

        return ['outcome' => 'done', 'detail' => mb_substr($result, 0, 100), 'output' => ['result' => $result, 'iso' => $local->copy()->utc()->toIso8601ZuluString(), 'timezone' => $tz]];
    }

    /** A date from a field or an event: ISO 8601, 2026-11-03, 2026-11-03 14:00, or a Unix timestamp. Null when it isn't one. */
    public static function parseDate(string $v, string $tz): ?Carbon
    {
        $v = trim($v);
        if ($v === '') {
            return null;
        }
        if (preg_match('/^\d{9,11}$/', $v)) {
            return Carbon::createFromTimestampUTC((int) $v);
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}([T ][0-9:.]+(Z|[+-]\d{2}:?\d{2})?)?$/', $v)) {
            return null;
        }

        return Carbon::parse($v, $tz);
    }

    /**
     * Calls any API, as n8n's HTTP Request: the URL, query, headers and body can use data; the answer is the output.
     *
     * @param  array<string, mixed>  $c
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function http(array $c, array $data): array
    {
        $url = JourneyData::resolve((string) $c['url'], $data);
        $query = array_map(fn ($v) => JourneyData::resolve((string) $v, $data), (array) $c['query']);
        if ($query) {
            $url .= (str_contains($url, '?') ? '&' : '?').http_build_query($query);
        }
        $headers = array_map(fn ($v) => JourneyData::resolve((string) $v, $data), (array) $c['headers']);
        $body = array_map(fn ($v) => JourneyData::resolve((string) $v, $data), (array) $c['body']);
        $out = self::send((string) $c['method'], $url, $headers, $body);
        if (isset($out['error'])) {
            return ['outcome' => 'failed', 'detail' => $out['error'], 'output' => $out];
        }

        return $out['ok'] ? ['outcome' => 'done', 'detail' => $c['method'].' · HTTP '.$out['status'], 'output' => $out]
            : ['outcome' => 'failed', 'detail' => 'The API answered HTTP '.$out['status'], 'output' => $out];
    }

    /**
     * One request to a public HTTPS address (checked after any expressions were filled, connected to exactly the
     * address that was checked, no redirects, 10 s). Returns {status, ok, body, ms} or {ok: false, error}.
     *
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public static function send(string $method, string $url, array $headers, array $body): array
    {
        [$problem, $ip] = UrlGuard::vet($url);
        if ($problem) {
            return ['ok' => false, 'error' => $problem];
        }
        $headers = array_map(fn ($v) => str_replace(["\r", "\n"], '', (string) $v), $headers);
        $host = (string) parse_url($url, PHP_URL_HOST);
        $port = (int) (parse_url($url, PHP_URL_PORT) ?: 443);
        $req = Http::withHeaders($headers + ['User-Agent' => 'MailVesper-Journeys/1.0'])->acceptJson()->timeout(10)->connectTimeout(5)->withoutRedirecting()
            ->withOptions($ip ? ['curl' => [CURLOPT_RESOLVE => ["$host:$port:$ip"]]] : []);
        $t0 = hrtime(true);
        try {
            $res = match ($method) {
                'POST' => $req->post($url, $body),
                'PUT' => $req->put($url, $body),
                'PATCH' => $req->patch($url, $body),
                'DELETE' => $req->delete($url),
                default => $req->get($url),
            };
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => str_contains($e->getMessage(), 'timed out') ? 'Timed out after 10 seconds' : 'Couldn’t connect'];
        }
        $raw = substr((string) $res->body(), 0, 65536);
        $json = json_decode($raw, true);

        return ['status' => $res->status(), 'ok' => $res->successful(), 'body' => is_array($json) ? $json : mb_substr($raw, 0, 2000), 'ms' => (int) round((hrtime(true) - $t0) / 1e6)];
    }

    /** The signed part of an approval link: which run and step it answers. */
    public static function approvalToken(string $runId, string $nodeId): string
    {
        $payload = rtrim(strtr(base64_encode($runId.'.'.$nodeId), '+/', '-_'), '=');

        return $payload.'.'.substr(hash_hmac('sha256', 'approval|'.$runId.'|'.$nodeId, (string) config('app.key')), 0, 32);
    }

    /** @return array{0: string, 1: string}|null [run id, node id] */
    public static function readApproval(string $token): ?array
    {
        if (! preg_match('/^([A-Za-z0-9_-]{8,80})\.([a-f0-9]{32})$/', $token, $m)) {
            return null;
        }
        $plain = base64_decode(strtr($m[1], '-_', '+/'), true);
        if (! is_string($plain) || ! preg_match('/^([0-9a-z]{26})\.([A-Za-z0-9_-]{1,24})$/', $plain, $p)) {
            return null;
        }

        return hash_equals(self::approvalToken($p[1], $p[2]), $token) ? [$p[1], $p[2]] : null;
    }

    public static function approvalUrl(JourneyRun $run, string $nodeId): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/a/?t='.self::approvalToken($run->id, $nodeId);
    }
}
