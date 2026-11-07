<?php

use App\Models\SesConnection;
use App\Models\Workspace;
use App\Services\Ses\AwsFactory;
use App\Services\Ses\DnsLookup;
use App\Services\Ses\SesHandoff;
use Tests\Support\FakeAws;
use Tests\Support\FakeDns;

beforeEach(function () {
    $this->aws = FakeAws::install();
    $this->app->instance(DnsLookup::class, new FakeDns);
    SesHandoff::$retryDelay = 0;
});

function handoffToken(string $command): string
{
    preg_match('#/api/v1/ses/setup/([A-Za-z0-9]{48})#', $command, $m);

    return $m[1];
}

function runScript($test, string $token, array $body = [])
{
    return $test->call('POST', "/v1/ses/handoff/$token", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'text/plain'],
        json_encode($body + ['access_key_id' => AWS_KEY, 'secret_access_key' => AWS_SECRET, 'region' => 'eu-west-1']));
}

it('connects with one command pasted into CloudShell', function () {
    [$ws] = sesWorkspace($this);
    $h = $this->postJson("/v1/workspaces/{$ws->id}/ses/handoff")->assertOk()->json();
    expect($h['command'])->toMatch('#^curl -fsSL https://send\.test/api/v1/ses/setup/[A-Za-z0-9]{48} \| bash$#')
        ->and($h['cloudshell_url'])->toStartWith('https://console.aws.amazon.com/cloudshell/');
    $token = handoffToken($h['command']);
    $this->getJson("/v1/workspaces/{$ws->id}/ses/handoff/{$h['id']}")->assertJsonPath('status', 'waiting');

    $script = $this->get("/v1/ses/setup/$token")->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8')->getContent();
    expect($script)->toContain("https://send.test/api/v1/ses/handoff/$token")->toContain('"Sid":"MailVesperSes"')->toContain('aws iam create-user');

    // The script is valid bash.
    $f = tempnam(sys_get_temp_dir(), 'sl');
    file_put_contents($f, $script);
    exec('bash -n '.escapeshellarg($f).' 2>&1', $out, $code);
    unlink($f);
    expect($code)->toBe(0, implode("\n", $out));

    $reply = runScript($this, $token)->assertOk()->getContent();
    expect($reply)->toContain('✓ Connected AWS account '.FakeAws::ACCOUNT)->toContain('Event tracking is set up');
    expect(SesConnection::first())->status->toBe('active')->connected_by->not->toBeNull();
    $this->getJson("/v1/workspaces/{$ws->id}/ses/handoff/{$h['id']}")->assertJsonPath('status', 'connected');

    runScript($this, $token)->assertStatus(409); // single use
});

it('waits for a brand-new key to start working', function () {
    [$ws] = sesWorkspace($this);
    $token = handoffToken($this->postJson("/v1/workspaces/{$ws->id}/ses/handoff")->json('command'));

    // AWS rejects the key twice (still propagating), then accepts it.
    $this->aws->fail('GetCallerIdentity', 'InvalidClientTokenId');
    $propagating = new class($this->aws)
    {
        public int $tries = 0;

        public function __construct(public FakeAws $fake) {}

        public function __invoke($cmd, $req)
        {
            if ($cmd->getName() === 'GetCallerIdentity' && ++$this->tries === 3) {
                $this->fake->responses['GetCallerIdentity'] = (new FakeAws)->responses['GetCallerIdentity'];
            }

            return ($this->fake)($cmd, $req);
        }
    };
    app(AwsFactory::class)->handler = $propagating;

    runScript($this, $token)->assertOk();
    expect($propagating->tries)->toBe(3)->and(SesConnection::count())->toBe(1);
});

it('reports a key AWS keeps refusing, in plain text for the terminal', function () {
    [$ws] = sesWorkspace($this);
    $h = $this->postJson("/v1/workspaces/{$ws->id}/ses/handoff")->json();
    $this->aws->fail('GetCallerIdentity', 'InvalidClientTokenId');
    $r = runScript($this, handoffToken($h['command']))->assertStatus(422);
    expect($r->getContent())->toContain('AWS hasn’t activated the new key yet');
    $this->getJson("/v1/workspaces/{$ws->id}/ses/handoff/{$h['id']}")->assertJsonPath('status', 'failed');
});

it('refuses expired, unknown and malformed handoffs', function () {
    [$ws] = sesWorkspace($this);
    $token = handoffToken($this->postJson("/v1/workspaces/{$ws->id}/ses/handoff")->json('command'));
    runScript($this, $token, ['access_key_id' => 'nope'])->assertStatus(422);
    runScript($this, str_repeat('x', 48))->assertStatus(410);
    expect($this->get('/v1/ses/setup/'.str_repeat('x', 48))->getContent())->toContain('expired');

    $this->travel(31)->minutes();
    runScript($this, $token)->assertStatus(410);
    expect(SesConnection::count())->toBe(0);
});

it('only lets owners and admins start one, and keeps status private to the workspace', function () {
    [$ws, $p] = sesWorkspace($this, ['member']);
    $id = $this->postJson("/v1/workspaces/{$ws->id}/ses/handoff")->json('id');
    $other = Workspace::create(['name' => 'Other', 'slug' => 'other', 'owner_id' => $p['owner']->id]);
    $other->members()->attach($p['owner']->id, ['role' => 'owner']);
    $this->getJson("/v1/workspaces/{$other->id}/ses/handoff/$id")->assertJsonPath('status', 'expired');

    app('auth')->forgetGuards();
    $this->postJson('/v1/auth/logout');
    app('auth')->forgetGuards();
    $this->postJson('/v1/auth/login', ['email' => 'member@example.com', 'password' => STRONG_PASSWORD])->assertOk();
    $this->postJson("/v1/workspaces/{$ws->id}/ses/handoff")->assertForbidden();
});

it('adds Route 53 records automatically when a domain is added', function () {
    [$ws] = sesWorkspace($this);
    $this->aws->responses['ListEmailIdentities'] = ['EmailIdentities' => []];
    sesConnect($this, $ws)->assertOk();
    $this->aws->responses['ListHostedZonesByName'] = ['IsTruncated' => false, 'MaxItems' => '1', 'HostedZones' => [['Id' => '/hostedzone/Z1', 'Name' => 'example.com.', 'CallerReference' => 'x']]];
    $this->postJson("/v1/workspaces/{$ws->id}/domains", ['name' => 'example.com'])->assertCreated()->assertJsonPath('auto_dns', 6);
    expect($this->aws->argsFor('ChangeResourceRecordSets'))->toHaveCount(1);
});
