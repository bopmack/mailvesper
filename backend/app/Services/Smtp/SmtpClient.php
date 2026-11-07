<?php

namespace App\Services\Smtp;

use App\Models\SmtpConnection;
use App\Services\Platform\Settings;
use App\Services\Webhooks\UrlGuard;
use Closure;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Talks to a customer's SMTP server: connection tests and sending, with failures explained in plain words and
 * sorted into "this recipient is bad" (permanent), "try again later" (temporary) and "the settings are wrong".
 */
class SmtpClient
{
    /** @var (Closure(SmtpConnection): TransportInterface)|null tests swap in a fake server */
    public static ?Closure $factory = null;

    public const TIMEOUT = 20;

    /**
     * The server's public address, or why it can't be used. Private, loopback and reserved addresses are refused, so
     * a customer can't relay through this server's own mail service or reach the internal network (a self-hoster
     * who relays through a local server can allow that in Platform → Settings).
     *
     * @return array{0: string|null, 1: string|null} [problem, ip to connect to]
     */
    public static function vet(string $host): array
    {
        $ips = UrlGuard::resolve($host);
        if (! $ips) {
            return ["We couldn’t find $host. Check the host name.", null];
        }
        if (! Settings::get('smtp.allow_private_hosts')) {
            foreach ($ips as $ip) {
                if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return ["$host points to a private or local address. Use your mail server’s public name.", null];
                }
            }
        }

        return [null, $ips[0]];
    }

    public function transport(SmtpConnection $c): TransportInterface
    {
        if (self::$factory) {
            return (self::$factory)($c);
        }
        [$problem, $ip] = self::vet($c->host);
        if ($problem) {
            throw new HostRefused($problem);
        }
        // Connect to exactly the address that was checked; TLS is still verified against the host name.
        $t = new EsmtpTransport(str_contains((string) $ip, ':') ? "[$ip]" : (string) $ip, $c->port, $c->encryption === 'ssl'); // ssl = implicit TLS (465)
        if ($c->encryption === 'tls') {
            $t->setRequireTls(true); // STARTTLS, and never fall back to plain text
        } elseif ($c->encryption === 'none') {
            $t->setAutoTls(false);
        }
        if ($c->username) {
            $t->setUsername($c->username);
            $t->setPassword((string) $c->password);
        }
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            $t->setLocalDomain($host);
        }
        $stream = $t->getStream();
        if ($stream instanceof SocketStream) {
            $stream->setTimeout(self::TIMEOUT);
            $stream->setStreamOptions(['ssl' => ['peer_name' => $c->host, 'verify_peer' => true, 'verify_peer_name' => true]]);
        }

        return $t;
    }

    /**
     * Connects, says hello, starts TLS and logs in, without sending anything.
     *
     * @return array{ok: bool, message: string}
     */
    public function test(SmtpConnection $c): array
    {
        try {
            $t = $this->transport($c); // inside the try: a refused host is an answer, not a crash
            if (method_exists($t, 'start') && method_exists($t, 'stop')) {
                $t->start();
                $t->stop();
            }

            return ['ok' => true, 'message' => "Connected to {$c->host}:{$c->port}".($c->username ? ' and signed in' : '').'.'];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => self::explain($e, $c)];
        }
    }

    public function send(SmtpConnection $c, Email $email, Envelope $envelope): ?SentMessage
    {
        $t = $this->transport($c);
        try {
            return $t->send($email, $envelope);
        } finally {
            if ($t instanceof EsmtpTransport) {
                try {
                    $t->stop();
                } catch (Throwable) {
                    // already closed
                }
            }
        }
    }

    /** The SMTP reply code in a failure (550, 421…), or null when the connection itself failed. */
    public static function code(Throwable $e): ?int
    {
        if ($e->getCode() >= 200 && $e->getCode() < 600) {
            return (int) $e->getCode();
        }

        return preg_match('/code "?(\d{3})"?/', $e->getMessage(), $m) ? (int) $m[1] : null;
    }

    /**
     * bounce (this recipient doesn't exist: suppress it), rejected (the server refused this email, e.g. relaying or
     * sender not allowed: don't blame the recipient), auth (the settings are wrong) or temporary (try again).
     */
    public static function kind(Throwable $e): string
    {
        $code = self::code($e);
        $msg = strtolower($e->getMessage());
        $recipient = (bool) preg_match('/\b5\.1\.[0-3]\b|user unknown|unknown user|no such user|does not exist|doesn\'t exist|invalid recipient|recipient address rejected|mailbox (unavailable|not found)|address rejected/', $msg);

        return match (true) {
            $e instanceof HostRefused => 'auth', // the settings need fixing
            in_array($code, [530, 534, 535, 538], true) || str_contains($msg, 'authenticat') => 'auth',
            $code !== null && $code >= 500 && $recipient => 'bounce',
            $code !== null && $code >= 500 => 'rejected',
            default => 'temporary',
        };
    }

    public static function explain(Throwable $e, SmtpConnection $c): string
    {
        $msg = $e->getMessage();
        $lower = strtolower($msg);
        $code = self::code($e);

        return mb_substr(match (true) {
            $e instanceof HostRefused => $msg,
            in_array($code, [530, 534, 535, 538], true) || str_contains($lower, 'authenticat') => 'The SMTP server didn’t accept the username or password.',
            str_contains($lower, 'connection refused') => "Nothing accepted the connection on {$c->host}:{$c->port}. Check the host and port.",
            str_contains($lower, 'timed out') || str_contains($lower, 'timeout') => "Couldn’t reach {$c->host}:{$c->port} in time. The port may be blocked by a firewall; try 587 or 465.",
            str_contains($lower, 'getaddrinfo') || str_contains($lower, 'name or service not known') || str_contains($lower, 'no such host') => "We couldn’t find {$c->host}. Check the host name.",
            str_contains($lower, 'certificate') || str_contains($lower, 'ssl') && str_contains($lower, 'verify') => 'The server’s TLS certificate couldn’t be verified (it must match the host name).',
            str_contains($lower, 'starttls') || str_contains($lower, 'tls') && str_contains($lower, 'not support') => 'The server doesn’t offer STARTTLS on this port. Try SSL on port 465.',
            $e instanceof TransportExceptionInterface && $code !== null => "The SMTP server said ($code): ".trim(preg_replace('/^.*?with message "?/s', '', $msg) ?? $msg, '" '),
            default => 'The SMTP server said: '.$msg,
        }, 0, 400);
    }
}
