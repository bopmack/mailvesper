<?php

namespace Tests\Support;

use App\Services\Ses\AwsFactory;
use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\Result;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;

/**
 * Stands in for AWS. The SDK still validates every request against the real API models before it
 * reaches this handler, so wrong parameter names fail the tests.
 */
class FakeAws
{
    /** @var list<array{op: string, args: array<string, mixed>}> */
    public array $calls = [];

    /** @var array<string, mixed> operation => result array | callable(args): (result array | "!Code") | error code string starting with "!" */
    public array $responses = [];

    public const ACCOUNT = '123456789012';

    public static function install(): self
    {
        $fake = new self;
        app(AwsFactory::class)->handler = $fake;

        return $fake;
    }

    public function __construct()
    {
        $topic = 'arn:aws:sns:eu-west-1:'.self::ACCOUNT.':sendline-events';
        $this->responses = [
            'GetCallerIdentity' => ['Account' => self::ACCOUNT, 'Arn' => 'arn:aws:iam::'.self::ACCOUNT.':user/sendline', 'UserId' => 'AIDAEXAMPLE'],
            'GetAccount' => ['ProductionAccessEnabled' => false, 'SendingEnabled' => true, 'EnforcementStatus' => 'HEALTHY', 'SendQuota' => ['Max24HourSend' => 200.0, 'MaxSendRate' => 14.0, 'SentLast24Hours' => 3.0]],
            'CreateConfigurationSet' => [],
            'CreateTopic' => ['TopicArn' => $topic],
            'SetTopicAttributes' => [],
            'Subscribe' => ['SubscriptionArn' => $topic.':1111-2222'],
            'CreateConfigurationSetEventDestination' => [],
            'UpdateConfigurationSetEventDestination' => [],
            'ListEmailIdentities' => ['EmailIdentities' => [['IdentityType' => 'DOMAIN', 'IdentityName' => 'acme.com'], ['IdentityType' => 'EMAIL_ADDRESS', 'IdentityName' => 'me@acme.com']]],
            'GetEmailIdentity' => fn (array $a) => self::identity($a['EmailIdentity'], $a['EmailIdentity'] === 'acme.com'),
            'CreateEmailIdentity' => fn (array $a) => ['IdentityType' => 'DOMAIN', 'VerifiedForSendingStatus' => false, 'DkimAttributes' => ['Status' => 'PENDING', 'Tokens' => self::tokens($a['EmailIdentity'])]],
            'PutEmailIdentityMailFromAttributes' => [],
            'ListHostedZonesByName' => ['HostedZones' => [], 'IsTruncated' => false, 'MaxItems' => '1'],
            'ChangeResourceRecordSets' => ['ChangeInfo' => ['Id' => '/change/C1', 'Status' => 'PENDING', 'SubmittedAt' => '2026-10-24T00:00:00Z']],
            'GetMetricData' => ['MetricDataResults' => [['Id' => 'bounce', 'Values' => [0.012]], ['Id' => 'complaint', 'Values' => [0.0004]]]],
            'PutAccountDetails' => [],
            'SendEmail' => fn () => ['MessageId' => '0100'.bin2hex(random_bytes(12)).'-000000'],
        ];
    }

    /** @return list<string> */
    public static function tokens(string $domain): array
    {
        return array_map(fn ($i) => substr(md5($domain.$i), 0, 32), [1, 2, 3]);
    }

    /** @return array<string, mixed> */
    public static function identity(string $domain, bool $verified): array
    {
        return ['IdentityType' => 'DOMAIN', 'VerifiedForSendingStatus' => $verified,
            'DkimAttributes' => ['Status' => $verified ? 'SUCCESS' : 'PENDING', 'Tokens' => self::tokens($domain)],
            'MailFromAttributes' => ['MailFromDomain' => 'bounce.'.$domain, 'MailFromDomainStatus' => $verified ? 'SUCCESS' : 'PENDING', 'BehaviorOnMxFailure' => 'USE_DEFAULT_VALUE']];
    }

    public function fail(string $op, string $code): self
    {
        $this->responses[$op] = '!'.$code;

        return $this;
    }

    /** @return list<array<string, mixed>> */
    public function argsFor(string $op): array
    {
        return array_values(array_map(fn ($c) => $c['args'], array_filter($this->calls, fn ($c) => $c['op'] === $op)));
    }

    public function __invoke(CommandInterface $cmd, RequestInterface $request): PromiseInterface
    {
        $op = $cmd->getName();
        $args = $cmd->toArray();
        unset($args['@http'], $args['@context']);
        $this->calls[] = ['op' => $op, 'args' => $args];
        $r = $this->responses[$op] ?? throw new \LogicException("FakeAws: no response for $op");
        if (is_callable($r)) {
            $r = $r($args); // may answer "!Code" to fail this one call
        }
        if (is_string($r) && str_starts_with($r, '!')) {
            return Create::rejectionFor(new AwsException("$op failed", $cmd, ['code' => substr($r, 1), 'message' => "$op: ".substr($r, 1)]));
        }

        return Create::promiseFor(new Result($r));
    }
}
