<?php

namespace App\Services\Deliverability;

use App\Services\Platform\Brand;
use App\Services\Ses\DnsLookup;
use ZBateson\MailMimeParser\Header\AddressHeader;
use ZBateson\MailMimeParser\Message as MimeMessage;

/**
 * Reads a delivered email the way the receiving mailbox provider does and explains it in plain words: the path it
 * took (which server and IP handed it over), SPF for that IP, every DKIM signature, DMARC alignment and policy,
 * blocklists for the sending IP, and the content checks spam filters weigh.
 */
class Analyzer
{
    /** Second-level labels under which organisations register (acme.co.uk, acme.com.ng…). */
    private const SLDS = ['co', 'com', 'net', 'org', 'gov', 'edu', 'ac', 'sch', 'or', 'ne', 'go', 'gob', 'mil', 'ltd', 'plc', 'nom'];

    /** IP blocklists queried for the sending address (zone => name). Spamhaus only answers registered resolvers. */
    public const IP_LISTS = ['zen.spamhaus.org' => 'Spamhaus ZEN', 'bl.spamcop.net' => 'SpamCop', 'b.barracudacentral.org' => 'Barracuda'];

    public function __construct(private DnsLookup $dns, private Spf $spf, private Dkim $dkim) {}

    /** @return array<string, mixed> */
    public function analyze(string $raw): array
    {
        $mail = MimeMessage::from($raw, false);
        $from = $mail->getHeader('From');
        $fromEmail = $from instanceof AddressHeader ? mb_strtolower((string) $from->getEmail()) : '';
        $fromDomain = substr((string) strrchr($fromEmail, '@'), 1);
        $returnPath = mb_strtolower(trim((string) $mail->getHeaderValue('Return-Path'), '<> '));
        $mailFromDomain = substr((string) strrchr($returnPath, '@'), 1) ?: $fromDomain;

        // The path, oldest hop first. The handover to the receiver is the newest hop that came from a public IP.
        $hops = [];
        foreach ($mail->getAllHeadersByName('Received') as $h) {
            $v = (string) preg_replace('/\s+/', ' ', $h->getRawValue());
            if (preg_match('/from\s+(\S+)(?:\s+\(([^)]*)\))?.*?by\s+(\S+)/i', $v, $m)) {
                $ip = preg_match('/\[([0-9a-f:.]+)\]/i', $v, $x) ? $x[1] : null;
                $hops[] = ['from' => $m[1], 'ip' => $ip, 'by' => $m[3]];
            }
        }
        $sending = null;
        foreach ($hops as $hop) { // newest first, as in the headers
            if ($hop['ip'] && self::publicIp($hop['ip'])) {
                $sending = $hop;
                break;
            }
        }

        $spf = $sending ? $this->spf->check($sending['ip'], $mailFromDomain) + ['ip' => $sending['ip'], 'domain' => $mailFromDomain]
            : ['result' => 'none', 'record' => null, 'detail' => 'The sending server couldn’t be found in the headers.', 'ip' => null, 'domain' => $mailFromDomain];
        $dkim = $this->dkim->verify($raw);
        $dmarc = $this->dmarc($fromDomain, $spf, $mailFromDomain, $dkim);
        $lists = $sending ? $this->ipLists($sending['ip']) : [];

        return [
            'from' => $fromEmail, 'from_domain' => $fromDomain, 'mail_from' => $returnPath ?: null,
            // Only the hops between real servers (no internal or loopback addresses), oldest first.
            'path' => array_values(array_filter(array_reverse(array_slice($hops, 0, 12)), fn ($h) => $h['ip'] && self::publicIp($h['ip']))), 'sending_ip' => $sending['ip'] ?? null, 'sending_host' => $sending['from'] ?? null,
            'spf' => $spf, 'dkim' => $dkim, 'dmarc' => $dmarc, 'blocklists' => $lists,
            'verdict' => self::verdict($spf, $dkim, $dmarc, $lists),
        ];
    }

    /**
     * @param  array<string, mixed>  $spf
     * @param  list<array{domain: string, selector: string, result: string, detail: string}>  $dkim
     * @return array<string, mixed>
     */
    public function dmarc(string $fromDomain, array $spf, string $mailFromDomain, array $dkim): array
    {
        $record = $this->record('_dmarc.'.$fromDomain) ?? $this->record('_dmarc.'.self::orgDomain($fromDomain));
        $tags = $record ? Dkim::tags($record) : [];
        $strict = fn (string $k) => strtolower($tags[$k] ?? 'r') === 's';
        $aligned = fn (string $d, bool $s) => $s ? $d === $fromDomain : self::orgDomain($d) === self::orgDomain($fromDomain);
        $spfAligned = $spf['result'] === 'pass' && $aligned($mailFromDomain, $strict('aspf'));
        $dkimAligned = (bool) array_filter($dkim, fn ($s) => $s['result'] === 'pass' && $aligned($s['domain'], $strict('adkim')));
        $pass = $spfAligned || $dkimAligned;

        return ['result' => $record ? ($pass ? 'pass' : 'fail') : 'none', 'policy' => $record ? strtolower($tags['p'] ?? 'none') : null, 'record' => $record,
            'aligned_by' => array_values(array_filter([$dkimAligned ? 'dkim' : null, $spfAligned ? 'spf' : null])),
            'detail' => ! $record ? "$fromDomain has no DMARC record. Gmail and Yahoo require one for bulk senders."
                : ($pass ? 'Passes: '.($dkimAligned ? 'DKIM' : 'SPF').' is aligned with '.$fromDomain.'.'
                    : "Fails: neither SPF nor DKIM passed for $fromDomain itself. With p=".($tags['p'] ?? 'none').', receivers '.(['reject' => 'reject it', 'quarantine' => 'put it in spam'][strtolower($tags['p'] ?? 'none')] ?? 'only report it').'.')];
    }

    /** @return list<array{list: string, listed: bool|null}> null = the list didn't answer this resolver */
    public function ipLists(string $ip): array
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return [];
        }
        $rev = implode('.', array_reverse(explode('.', $ip)));
        $out = [];
        foreach (self::IP_LISTS as $zone => $name) {
            $a = $this->dns->lookup('A', "$rev.$zone")[0] ?? null;
            // 127.0.0.2–127.0.0.255 = listed; 127.255.255.x = this resolver isn't allowed to ask (Spamhaus).
            $out[] = ['list' => $name, 'listed' => $a === null ? false : (str_starts_with($a, '127.255.255.') ? null : (bool) preg_match('/^127\.0\.0\.\d+$/', $a))];
        }

        return $out;
    }

    /**
     * Content signals spam filters weigh, for an email as MailVesper sends it.
     *
     * @return list<array{level: string, title: string, detail: string}>
     */
    public static function content(string $subject, ?string $html, ?string $text, string $fromEmail, ?string $fromName, string $type, ?string $trackingHost = null): array
    {
        $out = [];
        $add = function (string $level, string $title, string $detail) use (&$out) {
            $out[] = ['level' => $level, 'title' => $title, 'detail' => $detail];
        };
        $domain = substr((string) strrchr(mb_strtolower($fromEmail), '@'), 1);
        $tld = substr((string) strrchr($domain, '.'), 1);
        if (in_array($tld, ['online', 'xyz', 'top', 'click', 'site', 'icu', 'buzz', 'live', 'store', 'shop', 'club', 'monster', 'rest', 'bond', 'cfd', 'sbs', 'link', 'work', 'fun', 'space'], true)) {
            $add('warn', "The .$tld domain ending is often used by spammers", "Spam filters score .$tld senders lower (SpamAssassin adds up to 2.5 points just for it). Sending from a .com (or your country’s domain) helps a new sender most.");
        }
        $plain = trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string) preg_replace('#<(style|script|head)\b.*?</\1>#is', '', (string) $html)))));
        $images = preg_match_all('/<img\b/i', (string) $html);
        if ($html !== null && mb_strlen($plain) < 300 && $images > 0) {
            $add('warn', 'Very little text for the images', 'Short emails that are mostly images (including the tracking pixel) look like spam. Aim for a few real paragraphs.');
        }
        if ($html !== null && ($text === null || trim($text) === '')) {
            $add('info', 'No plain-text version', 'A plain-text part alongside the HTML is a small trust signal and helps people on simple mail apps.');
        }
        if ($html !== null && strlen($html) > 100_000) {
            $add('warn', 'Gmail will clip this email', 'Gmail cuts emails over about 102 KB and hides the rest, including the unsubscribe link. Trim images or code.');
        }
        $hosts = [];
        preg_match_all('/\bhref\s*=\s*["\']https?:\/\/([^\/"\'?#:]+)/i', (string) $html, $m);
        foreach ($m[1] as $h) {
            $hosts[mb_strtolower($h)] = true;
        }
        if (preg_match('/\b(bit\.ly|tinyurl\.com|t\.co|goo\.gl|is\.gd|ow\.ly|rebrand\.ly|cutt\.ly)\b/i', implode(' ', array_keys($hosts)))) {
            $add('bad', 'Link shorteners', 'Shortened links (bit.ly and similar) are a classic spam signal. Link to your own site instead.');
        }
        $foreign = array_filter(array_keys($hosts), fn ($h) => self::orgDomain($h) !== self::orgDomain($domain) && $h !== $trackingHost);
        if ($trackingHost === null && $html !== null && ($images || $hosts)) {
            $add('info', 'Tracked links use a shared address', 'Links and the open pixel go through '.Brand::name().'’s address instead of your domain. Add your own tracking domain (Sending → Link tracking) so everything matches your From domain.');
        } elseif (count($foreign) > 3) {
            $add('info', 'Links to many other domains', 'Most links point away from '.$domain.'. That’s fine in moderation; links to your own domain read as more trustworthy.');
        }
        $name = mb_strtolower(trim((string) $fromName));
        $local = mb_strtolower((string) strstr($fromEmail, '@', true));
        if ($name === '' || in_array($name, ['hello', 'info', 'admin', 'noreply', 'no-reply', 'test', 'mail', 'support'], true)) {
            $add('info', 'A generic sender name', 'People open and trust email from a real name or brand (“Ada at Now2Code”) more than “'.($fromName ?: $local).'”.');
        }
        if (in_array($local, ['noreply', 'no-reply', 'donotreply'], true)) {
            $add('info', 'A no-reply address', 'Replies are a strong good signal to Gmail. Use an address people can answer.');
        }
        $letters = (string) preg_replace('/[^A-Za-z]/', '', $subject);
        if (strlen($letters) >= 8 && strtoupper($letters) === $letters) {
            $add('warn', 'Subject in capitals', 'ALL-CAPS subjects are scored as spam. Write it as a sentence.');
        }
        if (preg_match('/!{2,}|\${2,}|\b(free money|act now|winner|100% free|risk[- ]free|click here|limited time only|urgent)\b/i', $subject.' '.$plain)) {
            $add('warn', 'Spam-trigger wording', 'Phrases like “act now”, “winner” or “!!!” are weighed by spam filters. Plain wording lands better.');
        }
        if ($type === 'marketing' && ! str_contains((string) $html.(string) $text, 'unsubscribe')) {
            $add('bad', 'No unsubscribe link', 'Gmail and Yahoo require an easy unsubscribe for marketing email.');
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $spf
     * @param  list<array<string, mixed>>  $dkim
     * @param  array<string, mixed>  $dmarc
     * @param  list<array{list: string, listed: bool|null}>  $lists
     * @return array{level: string, title: string}
     */
    public static function verdict(array $spf, array $dkim, array $dmarc, array $lists): array
    {
        $dkimPass = (bool) array_filter($dkim, fn ($s) => $s['result'] === 'pass');
        if (array_filter($lists, fn ($l) => $l['listed'] === true)) {
            return ['level' => 'bad', 'title' => 'The sending IP is on a blocklist'];
        }
        if ($dmarc['result'] === 'fail') {
            return ['level' => 'bad', 'title' => 'DMARC fails: receivers can’t confirm the email is from your domain'];
        }
        if (! $dkimPass) {
            return ['level' => 'bad', 'title' => 'No valid DKIM signature for your domain'];
        }
        if ($spf['result'] !== 'pass' || $dmarc['result'] === 'none') {
            return ['level' => 'warn', 'title' => $dmarc['result'] === 'none' ? 'Add a DMARC record' : 'SPF doesn’t pass for the sending server'];
        }

        return ['level' => 'ok', 'title' => 'SPF, DKIM and DMARC pass'];
    }

    /** A real internet address: not private, reserved, or carrier-grade NAT (100.64.0.0/10, used inside providers). */
    public static function publicIp(string $ip): bool
    {
        return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) && ! Spf::inRange($ip, '100.64.0.0/10');
    }

    public static function orgDomain(string $domain): string
    {
        $labels = explode('.', trim(mb_strtolower($domain), '.'));
        $n = count($labels);
        if ($n >= 3 && strlen($labels[$n - 1]) === 2 && in_array($labels[$n - 2], self::SLDS, true)) {
            return implode('.', array_slice($labels, -3));
        }

        return implode('.', array_slice($labels, -2));
    }

    private function record(string $name): ?string
    {
        foreach ($this->dns->lookup('TXT', $name) as $t) {
            $t = trim((string) $t, '" ');
            if (stripos($t, 'v=DMARC1') === 0) {
                return $t;
            }
        }

        return null;
    }
}
