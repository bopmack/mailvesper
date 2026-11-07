<?php

namespace App\Services\Copilot;

use App\Exceptions\ApiException;
use App\Models\Domain;
use App\Models\SenderIdentity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use SimpleXMLElement;
use ZipArchive;

/**
 * DMARC aggregate reports (RFC 7489 "rua"): XML, gzip or zip, as mailbox providers send them. Only reports
 * for the workspace's own domains are kept; the same report never counts twice.
 */
class DmarcReports
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    /** @return array{report_id: string, org: string, domain: string, messages: int, passed: int, duplicate: bool} */
    public function ingest(string $workspaceId, string $raw): array
    {
        $xml = $this->unpack($raw);
        $prev = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_use_internal_errors($prev);
        if (! $doc || ! isset($doc->report_metadata, $doc->policy_published)) {
            throw new ApiException('invalid_report', 'That isn’t a DMARC aggregate report.', 422);
        }
        $domain = mb_strtolower(trim((string) $doc->policy_published->domain));
        // SES domains, and for SMTP sending its verified sender domains and addresses' domains.
        $mine = Domain::where('workspace_id', $workspaceId)->pluck('name')->merge(SenderIdentity::where('workspace_id', $workspaceId)->whereNotNull('verified_at')->pluck('value')
            ->map(fn ($v) => mb_strtolower(str_contains($v, '@') ? substr($v, strrpos($v, '@') + 1) : $v)))->unique()->values()->all();
        $match = collect($mine)->first(fn ($d) => $d === $domain || str_ends_with($domain, '.'.$d) || str_ends_with($d, '.'.$domain));
        if (! $match) {
            throw new ApiException('foreign_domain', "This report is for $domain, which isn’t one of your domains.", 422);
        }
        $org = mb_substr(trim((string) $doc->report_metadata->org_name), 0, 150);
        $id = mb_substr(trim((string) $doc->report_metadata->report_id), 0, 200);
        if ($org === '' || $id === '') {
            throw new ApiException('invalid_report', 'The report has no sender or id.', 422);
        }
        $records = [];
        $messages = 0;
        $passed = 0;
        foreach ($doc->record as $rec) {
            $count = (int) $rec->row->count;
            $dkim = strtolower((string) $rec->row->policy_evaluated->dkim);
            $spf = strtolower((string) $rec->row->policy_evaluated->spf);
            $messages += $count;
            if ($dkim === 'pass' || $spf === 'pass') {
                $passed += $count;
            }
            $records[] = ['source_ip' => mb_substr((string) $rec->row->source_ip, 0, 45), 'count' => $count, 'disposition' => mb_substr(strtolower((string) $rec->row->policy_evaluated->disposition), 0, 16) ?: null,
                'dkim' => $dkim ?: null, 'spf' => $spf ?: null, 'header_from' => mb_substr(strtolower((string) $rec->identifiers->header_from), 0, 253) ?: null,
                'dkim_domain' => mb_substr(strtolower((string) ($rec->auth_results->dkim->domain ?? '')), 0, 253) ?: null, 'spf_domain' => mb_substr(strtolower((string) ($rec->auth_results->spf->domain ?? '')), 0, 253) ?: null];
        }
        $exists = DB::table('dmarc_reports')->where('workspace_id', $workspaceId)->where('org_name', $org)->where('report_id', $id)->exists();
        if (! $exists) {
            DB::transaction(function () use ($workspaceId, $org, $id, $domain, $doc, $messages, $passed, $records) {
                $rid = DB::table('dmarc_reports')->insertGetId(['workspace_id' => $workspaceId, 'org_name' => $org, 'report_id' => $id, 'domain' => $domain,
                    'policy' => mb_substr(strtolower((string) $doc->policy_published->p), 0, 16) ?: null,
                    'date_begin' => Carbon::createFromTimestampUTC((int) $doc->report_metadata->date_range->begin)->toDateTimeString(),
                    'date_end' => Carbon::createFromTimestampUTC((int) $doc->report_metadata->date_range->end)->toDateTimeString(),
                    'messages' => $messages, 'passed' => $passed, 'created_at' => now()]);
                foreach (array_chunk($records, 500) as $part) {
                    DB::table('dmarc_records')->insert(array_map(fn ($r) => $r + ['report_id' => $rid], $part));
                }
            });
        }

        return ['report_id' => $id, 'org' => $org, 'domain' => $domain, 'messages' => $messages, 'passed' => $passed, 'duplicate' => $exists];
    }

    /** @return array<string, mixed> per domain: volume, pass rate, and each source with its results (last 30 days) */
    public function summary(string $workspaceId): array
    {
        $since = now()->subDays(30)->toDateTimeString();
        $rows = DB::table('dmarc_records')->join('dmarc_reports', 'dmarc_reports.id', '=', 'dmarc_records.report_id')
            ->where('dmarc_reports.workspace_id', $workspaceId)->where('dmarc_reports.date_end', '>=', $since)
            ->selectRaw("dmarc_reports.domain, dmarc_records.source_ip, sum(dmarc_records.count) n,
                sum(case when dmarc_records.dkim = 'pass' or dmarc_records.spf = 'pass' then dmarc_records.count else 0 end) pass,
                max(dmarc_records.dkim_domain) dkim_domain")
            ->groupBy('dmarc_reports.domain', 'dmarc_records.source_ip')->orderByDesc('n')->get();

        return $rows->groupBy('domain')->map(fn ($g) => ['messages' => (int) $g->sum('n'), 'passed' => (int) $g->sum('pass'),
            'sources' => $g->take(20)->map(fn ($r) => ['ip' => $r->source_ip, 'messages' => (int) $r->n, 'passed' => (int) $r->pass, 'dkim_domain' => $r->dkim_domain])->values()])->all();
    }

    private function unpack(string $raw): string
    {
        if (strlen($raw) > self::MAX_BYTES) {
            throw new ApiException('too_large', 'Reports can be up to 10 MB.', 422);
        }
        if (str_starts_with($raw, "\x1f\x8b")) {
            $out = @gzdecode($raw, self::MAX_BYTES);
            if ($out === false) {
                throw new ApiException('invalid_report', 'The gzip file couldn’t be opened.', 422);
            }

            return $out;
        }
        if (str_starts_with($raw, "PK\x03\x04")) {
            $tmp = tempnam(sys_get_temp_dir(), 'dmarc');
            file_put_contents((string) $tmp, $raw);
            $zip = new ZipArchive;
            try {
                if ($zip->open((string) $tmp) !== true || $zip->numFiles < 1) {
                    throw new ApiException('invalid_report', 'The zip file couldn’t be opened.', 422);
                }
                $stat = $zip->statIndex(0);
                if (! $stat || $stat['size'] > self::MAX_BYTES) {
                    throw new ApiException('too_large', 'The report inside the zip is too large.', 422);
                }

                return (string) $zip->getFromIndex(0);
            } finally {
                $zip->close();
                @unlink((string) $tmp);
            }
        }

        return $raw;
    }
}
