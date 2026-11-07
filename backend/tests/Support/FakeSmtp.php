<?php

namespace Tests\Support;

use App\Services\Smtp\SmtpClient;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/** A pretend SMTP server: records what it was given, or fails the way a real server would. */
class FakeSmtp implements TransportInterface
{
    /** @var list<Email> */
    public array $sent = [];

    /** @var list<array{0: int, 1: string}> failures for the next sends, in order: [code, server reply] */
    public array $failSends = [];

    public ?array $failLogin = null;

    public static function install(): self
    {
        $fake = new self;
        SmtpClient::$factory = fn () => $fake;

        return $fake;
    }

    public function start(): void
    {
        if ($this->failLogin) {
            [$code, $msg] = $this->failLogin;
            throw new TransportException("Expected response code \"235\" but got code \"$code\", with message \"$msg\".", $code);
        }
    }

    public function stop(): void {}

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        if ($this->failSends) {
            [$code, $msg] = array_shift($this->failSends);
            throw new TransportException("Expected response code \"250/251/252\" but got code \"$code\", with message \"$msg\".", $code);
        }
        $this->sent[] = $message instanceof Email ? $message : throw new \LogicException('Expected an Email');

        return new SentMessage($message, $envelope ?? Envelope::create($message));
    }

    public function last(): Email
    {
        return end($this->sent) ?: throw new \LogicException('Nothing was sent');
    }

    public function __toString(): string
    {
        return 'fake://smtp';
    }
}
