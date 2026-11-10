<?php

namespace App\Services\Ses;

use App\Exceptions\ApiException;
use App\Models\SesConnection;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Platform\Brand;
use Aws\Exception\AwsException;
use Aws\Result;
use Illuminate\Support\Str;
use Throwable;

/**
 * Connects a customer's own Amazon SES with access keys, then sets up event tracking in their account:
 * configuration set "sendline-events" → SNS topic "sendline-events" → HTTPS subscription to our webhook.
 */
class SesSetup
{
    /** Setup steps in the order they run. Labels live in the front end. */
    public const STEPS = ['config_set', 'topic', 'subscribe', 'events', 'domains', 'reputation'];

    /** Steps that must succeed for tracking to work. */
    public const REQUIRED = ['config_set', 'topic', 'subscribe', 'events'];

    public function __construct(private AwsFactory $aws, private DomainService $domains) {}

    public function connect(Workspace $workspace, User $user, string $key, string $secret, string $region): SesConnection
    {
        $creds = ['key' => $key, 'secret' => $secret, 'region' => $region];
        try {
            $who = $this->aws->sts($creds)->getCallerIdentity();
        } catch (AwsException $e) {
            throw SesErrors::from($e, 'sts:GetCallerIdentity');
        }
        if (str_ends_with((string) $who['Arn'], ':root')) {
            throw new ApiException('aws_root_keys', 'These are your AWS root account keys. For safety, create an IAM user with the '.Brand::name().' policy and use its keys instead.', 422);
        }
        try {
            $account = $this->aws->ses($creds)->getAccount();
        } catch (AwsException $e) {
            throw SesErrors::from($e, 'ses:GetAccount');
        }

        $conn = SesConnection::firstOrNew(['workspace_id' => $workspace->id]);
        if (! $conn->exists || $conn->region !== $region) {
            $token = Str::random(48);
            $conn->webhook_token = $token;
            $conn->webhook_token_hash = SesConnection::hashToken($token);
            $conn->subscription_confirmed_at = null;
            $conn->subscription_arn = null;
            $conn->sns_topic_arn = null;
        }
        $conn->fill([
            'auth_type' => 'keys', 'access_key_id' => $key, 'secret_access_key' => $secret, 'region' => $region,
            'aws_account_id' => (string) $who['Account'], 'iam_arn' => (string) $who['Arn'], 'status' => 'connecting',
            'connected_by' => $user->id, 'last_error' => null,
        ]);
        $this->applyAccount($conn, $account);
        $conn->save();

        return $this->runSetup($conn);
    }

    /** Idempotent: safe to run again after fixing permissions. */
    public function runSetup(SesConnection $conn): SesConnection
    {
        $steps = [];
        $run = function (string $name, callable $fn) use (&$steps, $conn): void {
            try {
                $steps[$name] = $fn() ?? ['status' => 'done'];
                // Persist as we go: SNS calls our webhook to confirm the subscription while setup is still running,
                // and the webhook only accepts messages from the topic it can already see in the database.
                $conn->save();
            } catch (ApiException $e) {
                $steps[$name] = ['status' => 'failed', 'detail' => $e->getMessage()];
            } catch (AwsException $e) {
                $steps[$name] = ['status' => 'failed', 'detail' => SesErrors::from($e, $this->actionFor($name))->getMessage()];
            } catch (Throwable $e) {
                report($e);
                $steps[$name] = ['status' => 'failed', 'detail' => 'Something went wrong on our side. Try again.'];
            }
        };
        $ses = $this->aws->ses($conn);
        $sns = $this->aws->sns($conn);

        $run('config_set', function () use ($ses, $conn) {
            try {
                $ses->createConfigurationSet([
                    'ConfigurationSetName' => SesConnection::CONFIG_SET,
                    'ReputationOptions' => ['ReputationMetricsEnabled' => true],
                    'SendingOptions' => ['SendingEnabled' => true],
                ]);
            } catch (AwsException $e) {
                if ($e->getAwsErrorCode() !== 'AlreadyExistsException') {
                    throw $e;
                }
            }
            $conn->configuration_set = SesConnection::CONFIG_SET;

            return null;
        });

        $run('topic', function () use ($sns, $conn) {
            $conn->sns_topic_arn = (string) $sns->createTopic(['Name' => SesConnection::TOPIC])['TopicArn'];
            try {
                $sns->setTopicAttributes(['TopicArn' => $conn->sns_topic_arn, 'AttributeName' => 'Policy', 'AttributeValue' => (string) json_encode([
                    'Version' => '2012-10-17',
                    'Statement' => [
                        ['Sid' => 'OwnerManages', 'Effect' => 'Allow', 'Principal' => ['AWS' => '*'], 'Action' => ['SNS:GetTopicAttributes', 'SNS:SetTopicAttributes', 'SNS:Subscribe', 'SNS:ListSubscriptionsByTopic', 'SNS:Publish'], 'Resource' => $conn->sns_topic_arn, 'Condition' => ['StringEquals' => ['AWS:SourceOwner' => $conn->aws_account_id]]],
                        ['Sid' => 'SesPublishes', 'Effect' => 'Allow', 'Principal' => ['Service' => 'ses.amazonaws.com'], 'Action' => 'SNS:Publish', 'Resource' => $conn->sns_topic_arn, 'Condition' => ['StringEquals' => ['AWS:SourceAccount' => $conn->aws_account_id]]],
                    ],
                ])]);
            } catch (AwsException $e) {
                return ['status' => 'done', 'detail' => 'Topic ready (its access policy was left as it was).'];
            }
            $this->retryPolicy($conn);

            return null;
        });

        $run('subscribe', function () use ($sns, $conn) {
            if (! $conn->sns_topic_arn) {
                throw new ApiException('setup_blocked', 'Waiting for the SNS topic.');
            }
            $r = $sns->subscribe(['TopicArn' => $conn->sns_topic_arn, 'Protocol' => 'https', 'Endpoint' => $conn->webhookUrl(), 'ReturnSubscriptionArn' => true]);
            $conn->subscription_arn = (string) ($r['SubscriptionArn'] ?? '');

            return $conn->subscription_confirmed_at ? null : ['status' => 'waiting', 'detail' => 'Waiting for AWS to confirm the subscription.'];
        });

        $run('events', function () use ($ses, $conn) {
            if (! $conn->sns_topic_arn || ! $conn->configuration_set) {
                throw new ApiException('setup_blocked', 'Waiting for the configuration set and SNS topic.');
            }
            $dest = ['Enabled' => true, 'MatchingEventTypes' => SesCatalog::EVENT_TYPES, 'SnsDestination' => ['TopicArn' => $conn->sns_topic_arn]];
            try {
                $ses->createConfigurationSetEventDestination(['ConfigurationSetName' => SesConnection::CONFIG_SET, 'EventDestinationName' => 'sendline', 'EventDestination' => $dest]);
            } catch (AwsException $e) {
                if ($e->getAwsErrorCode() !== 'AlreadyExistsException') {
                    throw $e;
                }
                $ses->updateConfigurationSetEventDestination(['ConfigurationSetName' => SesConnection::CONFIG_SET, 'EventDestinationName' => 'sendline', 'EventDestination' => $dest]);
            }

            return null;
        });

        $run('domains', function () use ($conn) {
            $n = $this->domains->importAll($conn);

            return ['status' => 'done', 'detail' => $n ? "Found $n domain".($n === 1 ? '' : 's').' in SES.' : 'No domains in SES yet. Add one next.'];
        });

        $run('reputation', function () use ($conn) {
            return $this->syncReputation($conn) ? null : ['status' => 'warn', 'detail' => 'These keys can’t read CloudWatch, so bounce and complaint rates come from '.Brand::name().'’s own events.'];
        });

        $conn->setup_steps = $steps;
        $failed = array_filter(self::REQUIRED, fn ($s) => ($steps[$s]['status'] ?? '') === 'failed');
        $conn->status = $failed ? 'error' : 'active';
        $conn->last_error = $failed ? ($steps[reset($failed)]['detail'] ?? null) : null;
        $conn->last_synced_at = now();
        $conn->save();

        return $conn;
    }

    /** Refreshes sending limits, sandbox state and reputation. */
    /**
     * Asks SNS to keep retrying a notice for about 45 minutes if our server doesn't answer (its default gives up after a
     * few tries), so a server restart or a busy minute loses nothing. Best effort; remembered once it worked.
     */
    public function retryPolicy(SesConnection $conn): bool
    {
        if (! $conn->sns_topic_arn) {
            return false;
        }
        try {
            $this->aws->sns($conn)->setTopicAttributes(['TopicArn' => $conn->sns_topic_arn, 'AttributeName' => 'DeliveryPolicy', 'AttributeValue' => (string) json_encode([
                'http' => ['defaultHealthyRetryPolicy' => ['minDelayTarget' => 20, 'maxDelayTarget' => 300, 'numRetries' => 20, 'numNoDelayRetries' => 0,
                    'numMinDelayRetries' => 2, 'numMaxDelayRetries' => 6, 'backoffFunction' => 'exponential'], 'disableSubscriptionOverrides' => false],
            ])]);
        } catch (AwsException $e) {
            report($e);

            return false;
        }
        $steps = $conn->setup_steps ?? [];
        $steps['retry_policy'] = ['status' => 'done'];
        $conn->setup_steps = $steps;

        return true;
    }

    public function sync(SesConnection $conn): SesConnection
    {
        if (empty(($conn->setup_steps ?? [])['retry_policy']) && $conn->subscription_confirmed_at) {
            $this->retryPolicy($conn); // connections made before this existed get it on the next hourly sync
        }
        try {
            $this->applyAccount($conn, $this->aws->ses($conn)->getAccount());
            if ($conn->status === 'error' && $conn->last_error && str_contains($conn->last_error, 'AWS didn’t accept')) {
                $conn->status = 'active';
                $conn->last_error = null;
            }
        } catch (AwsException $e) {
            $err = SesErrors::from($e, 'ses:GetAccount');
            $conn->forceFill(['status' => 'error', 'last_error' => $err->getMessage(), 'last_synced_at' => now()])->save();
            throw $err;
        }
        $this->syncReputation($conn);
        $conn->last_synced_at = now();
        $conn->save();

        return $conn;
    }

    /** Last-24h reputation from CloudWatch (fractions, e.g. 0.016). False when the keys can't read CloudWatch. */
    public function syncReputation(SesConnection $conn): bool
    {
        $q = fn (string $id, string $metric) => ['Id' => $id, 'ReturnData' => true, 'MetricStat' => ['Metric' => ['Namespace' => 'AWS/SES', 'MetricName' => $metric], 'Period' => 3600, 'Stat' => 'Average']];
        try {
            $r = $this->aws->cloudwatch($conn)->getMetricData([
                'StartTime' => now()->subDay(), 'EndTime' => now(), 'ScanBy' => 'TimestampDescending',
                'MetricDataQueries' => [$q('bounce', 'Reputation.BounceRate'), $q('complaint', 'Reputation.ComplaintRate')],
            ]);
        } catch (AwsException $e) {
            if (SesErrors::isDenied($e)) {
                return false;
            }
            throw $e;
        }
        foreach ($r['MetricDataResults'] ?? [] as $m) {
            $v = $m['Values'][0] ?? null;
            if ($v !== null) {
                $conn->{$m['Id'] === 'bounce' ? 'bounce_rate' : 'complaint_rate'} = (float) $v;
            }
        }

        return true;
    }

    /**
     * Asks AWS to move the account out of the sandbox.
     *
     * @param  list<string>  $contacts
     */
    public function requestProductionAccess(SesConnection $conn, string $mailType, string $website, string $useCase, array $contacts): void
    {
        try {
            $this->aws->ses($conn)->putAccountDetails(array_filter([
                'MailType' => $mailType, 'WebsiteURL' => $website, 'UseCaseDescription' => $useCase, 'ContactLanguage' => 'EN',
                'AdditionalContactEmailAddresses' => $contacts ?: null, 'ProductionAccessEnabled' => true,
            ], fn ($v) => $v !== null));
        } catch (AwsException $e) {
            throw SesErrors::from($e, 'ses:PutAccountDetails');
        }
        $conn->forceFill(['review_status' => 'PENDING'])->save();
    }

    /** @param Result<string, mixed> $a */
    private function applyAccount(SesConnection $conn, Result $a): void
    {
        $q = $a['SendQuota'] ?? [];
        $conn->production_access = (bool) ($a['ProductionAccessEnabled'] ?? false);
        $conn->sending_enabled = (bool) ($a['SendingEnabled'] ?? false);
        $conn->enforcement_status = $a['EnforcementStatus'] ?? null;
        $conn->max_24h_send = (float) ($q['Max24HourSend'] ?? 0);
        $conn->max_send_rate = (float) ($q['MaxSendRate'] ?? 0);
        $conn->sent_last_24h = (float) ($q['SentLast24Hours'] ?? 0);
        $conn->review_status = $a['Details']['ReviewDetails']['Status'] ?? $conn->review_status;
    }

    private function actionFor(string $step): string
    {
        return match ($step) {
            'config_set' => 'ses:CreateConfigurationSet',
            'topic' => 'sns:CreateTopic',
            'subscribe' => 'sns:Subscribe',
            'events' => 'ses:CreateConfigurationSetEventDestination',
            'domains' => 'ses:ListEmailIdentities',
            default => 'cloudwatch:GetMetricData',
        };
    }
}
