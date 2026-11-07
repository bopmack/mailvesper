<?php

namespace App\Services\Ses;

use App\Models\SesConnection;
use Aws\CloudWatch\CloudWatchClient;
use Aws\Route53\Route53Client;
use Aws\SesV2\SesV2Client;
use Aws\Sns\SnsClient;
use Aws\Sts\StsClient;

/**
 * Builds AWS clients from a customer's keys. Tests swap in a fake HTTP handler via $handler,
 * so no test ever reaches AWS.
 */
class AwsFactory
{
    /** @var callable|null */
    public $handler = null;

    /** @var array<string, SesV2Client> */
    private array $senders = [];

    /** @return array<string, mixed> */
    private function config(string $key, string $secret, string $region): array
    {
        $cfg = [
            'version' => 'latest',
            'region' => $region,
            'credentials' => ['key' => $key, 'secret' => $secret],
            'retries' => 2,
            'http' => ['connect_timeout' => 5, 'timeout' => 20],
        ];
        if ($this->handler) {
            $cfg['handler'] = $this->handler;
        }

        return $cfg;
    }

    /**
     * @param  SesConnection|array{key: string, secret: string, region: string}  $c
     * @return array{0: string, 1: string, 2: string}
     */
    private function creds(SesConnection|array $c): array
    {
        return $c instanceof SesConnection ? [$c->access_key_id, $c->secret_access_key, $c->region] : [$c['key'], $c['secret'], $c['region']];
    }

    /** @param SesConnection|array{key: string, secret: string, region: string} $c */
    public function sts(SesConnection|array $c): StsClient
    {
        [$k, $s, $r] = $this->creds($c);

        return new StsClient($this->config($k, $s, $r));
    }

    /** @param SesConnection|array{key: string, secret: string, region: string} $c */
    public function ses(SesConnection|array $c): SesV2Client
    {
        [$k, $s, $r] = $this->creds($c);

        return new SesV2Client($this->config($k, $s, $r));
    }

    /**
     * The SES client used for sending, kept for the life of the worker so its HTTPS connections are reused instead
     * of a new TLS handshake for every email. A changed key or region gets a new client.
     */
    public function sender(SesConnection $c): SesV2Client
    {
        $key = $c->id.'|'.hash('sha256', $c->access_key_id.'|'.$c->secret_access_key.'|'.$c->region).'|'.($this->handler ? spl_object_id($this->handler) : 0);

        return $this->senders[$key] ??= $this->ses($c);
    }

    public function sns(SesConnection $c): SnsClient
    {
        return new SnsClient($this->config($c->access_key_id, $c->secret_access_key, $c->region));
    }

    public function cloudwatch(SesConnection $c): CloudWatchClient
    {
        return new CloudWatchClient($this->config($c->access_key_id, $c->secret_access_key, $c->region));
    }

    public function route53(SesConnection $c): Route53Client
    {
        return new Route53Client($this->config($c->access_key_id, $c->secret_access_key, 'us-east-1'));
    }
}
