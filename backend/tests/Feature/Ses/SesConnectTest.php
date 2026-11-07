<?php

use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\SesConnection;
use App\Services\Ses\DnsLookup;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeAws;
use Tests\Support\FakeDns;

beforeEach(function () {
    $this->aws = FakeAws::install();
    $this->app->instance(DnsLookup::class, $this->dns = new FakeDns);
});

it('connects with access keys and sets up event tracking in the customer’s account', function () {
    [$ws] = sesWorkspace($this);
    $c = sesConnect($this, $ws)->assertOk()->json('connection');

    expect($c['status'])->toBe('active')
        ->and($c['aws_account_id'])->toBe(FakeAws::ACCOUNT)
        ->and($c['access_key'])->toBe('AKIA…MPLE')
        ->and($c['production_access'])->toBeFalse()
        ->and($c['quota'])->toMatchArray(['max_24h' => 200, 'max_rate' => 14, 'sent_24h' => 3])
        ->and($c['reputation'])->toMatchArray(['bounce_rate' => 0.012, 'complaint_rate' => 0.0004])
        ->and($c['steps']['config_set']['status'])->toBe('done')
        ->and($c['steps']['subscribe']['status'])->toBe('waiting')
        ->and($c['steps']['events']['status'])->toBe('done')
        ->and($c['steps']['domains']['detail'])->toBe('Found 1 domain in SES.');

    // Configuration set, topic, subscription to our webhook, all 10 event types.
    expect($this->aws->argsFor('CreateConfigurationSet')[0]['ConfigurationSetName'])->toBe('sendline-events');
    $sub = $this->aws->argsFor('Subscribe')[0];
    expect($sub['Protocol'])->toBe('https')->and($sub['Endpoint'])->toStartWith('https://send.test/api/v1/webhooks/ses/');
    $dest = $this->aws->argsFor('CreateConfigurationSetEventDestination')[0]['EventDestination'];
    expect($dest['MatchingEventTypes'])->toHaveCount(10)->and($dest['SnsDestination']['TopicArn'])->toEndWith(':sendline-events');
    $policy = json_decode($this->aws->argsFor('SetTopicAttributes')[0]['AttributeValue'], true);
    expect($policy['Statement'][1]['Principal'])->toBe(['Service' => 'ses.amazonaws.com']);

    // Existing SES domain imported and checked.
    expect(Domain::where('name', 'acme.com')->first())->status->toBe('verified')->imported->toBeTrue();

    // Keys stored encrypted, never returned.
    $raw = DB::table('ses_connections')->first();
    expect($raw->secret_access_key)->not->toContain('EXAMPLEKEY')->and($raw->access_key_id)->not->toBe(AWS_KEY);
    $this->getJson("/v1/workspaces/{$ws->id}/ses")->assertOk()->assertJsonMissingPath('connection.secret_access_key')
        ->assertDontSee(AWS_SECRET, false);
    expect(AuditLog::where('action', 'ses.connected')->exists())->toBeTrue();
});

it('explains bad keys, root keys, temporary keys and missing permissions', function () {
    [$ws] = sesWorkspace($this);
    sesConnect($this, $ws, ['access_key_id' => 'nope'])->assertStatus(422)->assertJsonPath('error.fields.access_key_id', 'An access key ID is 20 characters and starts with AKIA.');
    sesConnect($this, $ws, ['region' => 'mars-1'])->assertStatus(422);
    sesConnect($this, $ws, ['access_key_id' => 'ASIAIOSFODNN7EXAMPLE'])->assertStatus(422)->assertJsonPath('error.code', 'aws_temporary_keys');

    $this->aws->fail('GetCallerIdentity', 'InvalidClientTokenId');
    sesConnect($this, $ws)->assertStatus(422)->assertJsonPath('error.code', 'aws_keys_invalid');

    $this->aws->responses['GetCallerIdentity'] = ['Account' => FakeAws::ACCOUNT, 'Arn' => 'arn:aws:iam::'.FakeAws::ACCOUNT.':root', 'UserId' => FakeAws::ACCOUNT];
    sesConnect($this, $ws)->assertStatus(422)->assertJsonPath('error.code', 'aws_root_keys');

    $this->aws->responses['GetCallerIdentity'] = (new FakeAws)->responses['GetCallerIdentity'];
    $this->aws->fail('GetAccount', 'AccessDeniedException');
    sesConnect($this, $ws)->assertStatus(422)->assertJsonPath('error.code', 'aws_permission_missing')
        ->assertJsonPath('error.message', fn ($m) => str_contains($m, 'ses:GetAccount'));
    expect(SesConnection::count())->toBe(0);
});

it('saves the connection but reports which setup step lacks permission, then retries', function () {
    [$ws] = sesWorkspace($this);
    $this->aws->fail('CreateTopic', 'AuthorizationError')->fail('GetMetricData', 'AccessDenied');
    $c = sesConnect($this, $ws)->assertOk()->json('connection');
    expect($c['status'])->toBe('error')
        ->and($c['steps']['topic']['status'])->toBe('failed')
        ->and($c['steps']['topic']['detail'])->toContain('sns:CreateTopic')
        ->and($c['steps']['events']['status'])->toBe('failed')
        ->and($c['steps']['reputation']['status'])->toBe('warn')
        ->and($c['last_error'])->toContain('sns:CreateTopic');

    $this->aws->responses['CreateTopic'] = (new FakeAws)->responses['CreateTopic'];
    $this->aws->fail('CreateConfigurationSet', 'AlreadyExistsException')->fail('CreateConfigurationSetEventDestination', 'AlreadyExistsException');
    $c = $this->postJson("/v1/workspaces/{$ws->id}/ses/retry")->assertOk()->json('connection');
    expect($c['status'])->toBe('active')->and($c['last_error'])->toBeNull();
    expect($this->aws->argsFor('UpdateConfigurationSetEventDestination'))->toHaveCount(1);
});

it('keeps the webhook address when reconnecting with new keys in the same region', function () {
    [$ws] = sesWorkspace($this);
    sesConnect($this, $ws)->assertOk();
    $first = $this->aws->argsFor('Subscribe')[0]['Endpoint'];
    sesConnect($this, $ws, ['access_key_id' => 'AKIAIOSFODNN7EXAMPL2'])->assertOk();
    expect($this->aws->argsFor('Subscribe')[1]['Endpoint'])->toBe($first)->and(SesConnection::count())->toBe(1);
});

it('syncs limits, sandbox state and reputation', function () {
    [$ws] = sesWorkspace($this);
    sesConnect($this, $ws)->assertOk();
    $this->travel(1)->minute();
    $this->aws->responses['GetAccount'] = ['ProductionAccessEnabled' => true, 'SendingEnabled' => true, 'EnforcementStatus' => 'HEALTHY', 'SendQuota' => ['Max24HourSend' => 50000.0, 'MaxSendRate' => 14.0, 'SentLast24Hours' => 6012.0]];
    $c = $this->postJson("/v1/workspaces/{$ws->id}/ses/sync")->assertOk()->json('connection');
    expect($c['production_access'])->toBeTrue()->and($c['quota']['max_24h'])->toEqual(50000);

    $this->artisan('ses:sync')->assertSuccessful();
});

it('asks AWS for production access with a real use case', function () {
    [$ws] = sesWorkspace($this);
    sesConnect($this, $ws)->assertOk();
    $this->postJson("/v1/workspaces/{$ws->id}/ses/production-access", ['mail_type' => 'MARKETING', 'website_url' => 'https://acme.com', 'use_case' => 'short'])->assertStatus(422);
    $useCase = str_repeat('We send a monthly newsletter to customers who opted in at checkout. ', 3);
    $this->postJson("/v1/workspaces/{$ws->id}/ses/production-access", ['mail_type' => 'MARKETING', 'website_url' => 'https://acme.com', 'use_case' => $useCase, 'contacts' => ['ops@acme.com']])
        ->assertOk()->assertJsonPath('connection.review_status', 'PENDING');
    expect($this->aws->argsFor('PutAccountDetails')[0])->toMatchArray(['MailType' => 'MARKETING', 'ProductionAccessEnabled' => true, 'AdditionalContactEmailAddresses' => ['ops@acme.com']]);
});

it('lets owners and admins change the connection, and everyone in the workspace see it', function () {
    [$ws, $p] = sesWorkspace($this, ['admin', 'member']);
    sesConnect($this, $ws)->assertOk();
    foreach (['admin' => 200, 'member' => 403] as $role => $status) {
        app('auth')->forgetGuards();
        $this->postJson('/v1/auth/logout');
        app('auth')->forgetGuards();
        $this->postJson('/v1/auth/login', ['email' => "$role@example.com", 'password' => STRONG_PASSWORD])->assertOk();
        $this->getJson("/v1/workspaces/{$ws->id}/ses")->assertOk()->assertJsonPath('connection.status', 'active');
        $this->postJson("/v1/workspaces/{$ws->id}/ses/retry")->assertStatus($status);
    }
    $this->deleteJson("/v1/workspaces/{$ws->id}/ses")->assertForbidden();
});

it('disconnects and forgets the domains, without touching AWS', function () {
    [$ws] = sesWorkspace($this);
    sesConnect($this, $ws)->assertOk();
    $before = count($this->aws->calls);
    $this->deleteJson("/v1/workspaces/{$ws->id}/ses")->assertOk();
    expect(SesConnection::count())->toBe(0)->and(Domain::count())->toBe(0)->and(count($this->aws->calls))->toBe($before);
    $this->getJson("/v1/workspaces/{$ws->id}/ses")->assertOk()->assertJsonPath('connection', null);
});

it('gives the IAM policy and regions to copy', function () {
    sesWorkspace($this);
    $r = $this->getJson('/v1/ses/catalog')->assertOk();
    expect($r->json('regions'))->toContain(['id' => 'eu-west-1', 'name' => 'Europe (Ireland)'])
        ->and(collect($r->json('policy.Statement'))->pluck('Action')->flatten())->toContain('ses:SendRawEmail', 'sns:Subscribe', 'route53:ChangeResourceRecordSets');
});

it('saves the SNS topic before subscribing, because SNS confirms while setup is still running', function () {
    [$ws] = sesWorkspace($this);
    $seen = 'not called';
    $this->aws->responses['Subscribe'] = function (array $a) use (&$seen) {
        $seen = SesConnection::query()->value('sns_topic_arn'); // what the webhook would find at this moment

        return ['SubscriptionArn' => $a['TopicArn'].':abc'];
    };
    sesConnect($this, $ws)->assertOk();
    expect($seen)->toBe('arn:aws:sns:eu-west-1:'.FakeAws::ACCOUNT.':sendline-events');
});
