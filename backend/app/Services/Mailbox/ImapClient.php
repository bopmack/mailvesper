<?php

namespace App\Services\Mailbox;

use App\Models\Mailbox;
use App\Services\Smtp\SmtpClient;

/**
 * A small read-only IMAP client: just what the mailbox reader needs (log in, EXAMINE, UID SEARCH, UID FETCH with
 * BODY.PEEK). Nothing is ever marked read, moved or deleted. Pure PHP (no imap extension, which PHP 8.4 dropped);
 * it connects to the address that passed the public-host check, and TLS is verified against the host name.
 * Tests swap in a fake through $factory.
 */
class ImapClient implements MailboxConnection
{
    /** @var (callable(Mailbox): MailboxConnection)|null */
    public static $factory = null;

    /** Largest message read (DMARC reports and bounces are small; big newsletters with attachments are skipped). */
    public const MAX_BYTES = 10_000_000;

    /** @var resource */
    private $sock;

    private int $tag = 0;

    public static function connect(Mailbox $box): MailboxConnection
    {
        if (self::$factory) {
            return (self::$factory)($box);
        }
        [$problem, $ip] = SmtpClient::vet($box->host); // the same public-host rule as SMTP
        if ($problem) {
            throw new MailboxError($problem);
        }

        return new self($box, (string) $ip);
    }

    private function __construct(Mailbox $box, string $ip)
    {
        $ctx = stream_context_create(['ssl' => ['peer_name' => $box->host, 'verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
        $addr = str_contains($ip, ':') ? "[$ip]" : $ip;
        $implicit = $box->encryption === 'ssl';
        $sock = @stream_socket_client(($implicit ? 'ssl' : 'tcp')."://$addr:{$box->port}", $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (! $sock) {
            throw new MailboxError(preg_match('/certificate|ssl|tls/i', (string) $errstr)
                ? 'The mail server’s certificate couldn’t be verified for '.$box->host.'.' : 'Couldn’t reach '.$box->host.' on port '.$box->port.'. Check the host and port.');
        }
        stream_set_timeout($sock, 30);
        $this->sock = $sock;
        $greeting = $this->line();
        if (! str_starts_with($greeting, '* OK') && ! str_starts_with($greeting, '* PREAUTH')) {
            throw new MailboxError('That doesn’t look like an IMAP server.');
        }
        if (! $implicit) {
            $this->command('STARTTLS');
            if (! @stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                throw new MailboxError('The mail server’s certificate couldn’t be verified for '.$box->host.'.');
            }
        }
        try {
            $this->command(['LOGIN ', $this->astring($box->username), ' ', $this->astring($box->password)]);
        } catch (MailboxError) {
            throw new MailboxError('The mail server rejected the username or password. Gmail, Outlook and Yahoo need an app password.');
        }
    }

    public function select(string $folder): array
    {
        $out = $this->command(['EXAMINE ', $this->astring($folder)]); // read-only
        $validity = preg_match('/\[UIDVALIDITY (\d+)\]/i', $out, $m) ? (int) $m[1] : 0;
        $next = preg_match('/\[UIDNEXT (\d+)\]/i', $out, $n) ? (int) $n[1] : 0;
        if (! $next) {
            $status = $this->command(['STATUS ', $this->astring($folder), ' (UIDNEXT)']);
            $next = preg_match('/UIDNEXT (\d+)/i', $status, $n) ? (int) $n[1] : 1;
        }

        return ['validity' => $validity, 'next' => $next];
    }

    public function uidsFrom(int $from): array
    {
        $out = $this->command('UID SEARCH UID '.max(1, $from).':*');
        $uids = [];
        foreach (preg_split('/\r\n/', $out) ?: [] as $l) {
            if (preg_match('/^\* SEARCH(.*)$/i', $l, $m)) {
                foreach (preg_split('/\s+/', trim($m[1])) ?: [] as $u) {
                    if (ctype_digit($u) && (int) $u >= $from) { // "n:*" always includes the newest message
                        $uids[] = (int) $u;
                    }
                }
            }
        }
        sort($uids);

        return $uids;
    }

    public function searchSubject(string $text): array
    {
        if (! preg_match('/^[A-Za-z0-9]{1,64}$/', $text)) {
            return [];
        }
        $out = $this->command('UID SEARCH SUBJECT "'.$text.'"');
        $uids = [];
        foreach (preg_split('/\r\n/', $out) ?: [] as $l) {
            if (preg_match('/^\* SEARCH(.*)$/i', $l, $m)) {
                foreach (preg_split('/\s+/', trim($m[1])) ?: [] as $u) {
                    if (ctype_digit($u)) {
                        $uids[] = (int) $u;
                    }
                }
            }
        }

        return $uids;
    }

    public function fetch(int $uid, int $maxBytes = self::MAX_BYTES): ?string
    {
        $size = $this->command("UID FETCH $uid (RFC822.SIZE)");
        if (! preg_match('/RFC822\.SIZE (\d+)/i', $size, $m) || (int) $m[1] > $maxBytes) {
            return null;
        }
        $out = $this->command("UID FETCH $uid (BODY.PEEK[])", true);

        return $out === '' ? null : $out;
    }

    public function close(): void
    {
        try {
            $this->command('LOGOUT');
        } catch (\Throwable) {
            // closing anyway
        }
        @fclose($this->sock);
    }

    /**
     * Sends a command and reads to its tagged answer. With $literal, returns the first literal ({n} block) instead
     * of the text. Arguments that need a literal are sent as {n}, then their bytes once the server says "+".
     *
     * @param  string|list<string|array{literal: string}>  $cmd
     */
    private function command(string|array $cmd, bool $literal = false): string
    {
        $tag = 'S'.(++$this->tag);
        $this->write($tag.' ');
        foreach (is_array($cmd) ? $cmd : [$cmd] as $part) {
            if (is_array($part)) {
                $this->write('{'.strlen($part['literal'])."}\r\n");
                $this->expectContinuation();
                $this->write($part['literal']);
            } else {
                $this->write($part);
            }
        }
        $this->write("\r\n");
        $text = '';
        $body = '';
        while (true) {
            $l = $this->line();
            if (preg_match('/\{(\d+)\}$/', $l, $m)) { // a literal follows
                $n = (int) $m[1];
                if ($n > self::MAX_BYTES * 2) {
                    throw new MailboxError('The mail server sent more than expected.');
                }
                $chunk = $this->bytes($n);
                if ($literal && $body === '') {
                    $body = $chunk;
                }
                $text .= $l."\r\n";

                continue;
            }
            if (str_starts_with($l, $tag.' ')) {
                if (! preg_match('/^'.$tag.' OK/i', $l)) {
                    throw new MailboxError('The mail server said: '.mb_substr(substr($l, strlen($tag) + 1), 0, 160));
                }

                return $literal ? $body : $text;
            }
            $text .= $l."\r\n";
        }
    }

    private function expectContinuation(): void
    {
        $l = $this->line();
        if (! str_starts_with($l, '+')) {
            throw new MailboxError('The mail server said: '.mb_substr($l, 0, 160));
        }
    }

    private function write(string $s): void
    {
        if (@fwrite($this->sock, $s) === false) {
            throw new MailboxError('The connection to the mail server was lost.');
        }
    }

    private function line(): string
    {
        $l = fgets($this->sock, 65536);
        if ($l === false) {
            throw new MailboxError(stream_get_meta_data($this->sock)['timed_out'] ? 'The mail server stopped answering.' : 'The connection to the mail server was lost.');
        }

        return rtrim($l, "\r\n");
    }

    private function bytes(int $n): string
    {
        $out = '';
        while (strlen($out) < $n) {
            $chunk = fread($this->sock, min(65536, $n - strlen($out)));
            if ($chunk === false || $chunk === '') {
                throw new MailboxError('The connection to the mail server was lost.');
            }
            $out .= $chunk;
        }

        return $out;
    }

    /**
     * A quoted string, or a literal when it holds anything a quoted string can't.
     *
     * @return string|array{literal: string}
     */
    private function astring(string $s): string|array
    {
        return preg_match('/[\x00-\x1f\x7f-\xff"\\\\]/', $s) ? ['literal' => $s] : '"'.$s.'"';
    }
}
