<?php

use App\Jobs\SendMessages;
use App\Models\ApiKey;
use App\Models\Message;
use App\Models\SesConnection;
use App\Models\Suppression;
use App\Services\Sending\Pacer;
use App\Services\Ses\AwsFactory;
use App\Services\Ses\DnsLookup;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeAws;
use Tests\Support\FakeDns;

beforeEach(function () {
    $this->aws = FakeAws::install();
    $this->app->instance(DnsLookup::class, new FakeDns);
    [$this->ws] = sesWorkspace($this);
    sesConnect($this, $this->ws)->assertOk();
    [, $this->key] = ApiKey::generate($this->ws->id, 'Test', 'send', null);
    app('auth')->forgetGuards();
});

function bulk($test, array $to): void
{
    $emails = array_map(fn ($chunk) => ['from' => 'Acme <news@acme.com>', 'to' => $chunk, 'subject' => 'Hi', 'html' => '<p>Hello</p>'], array_chunk($to, 50));
    $test->withHeaders(['Authorization' => 'Bearer '.$test->key])->postJson('/v1/emails/batch', $emails)->assertStatus(202);
}

it('sends a large send as grouped jobs, in parallel, never above the account’s send rate', function () {
    SesConnection::query()->update(['max_send_rate' => 40]);
    $perSecond = [];
    $this->aws->responses['SendEmail'] = function () use (&$perSecond) {
        $s = time();
        $perSecond[$s] = ($perSecond[$s] ?? 0) + 1;

        return ['MessageId' => '0100'.bin2hex(random_bytes(12)).'-000000'];
    };
    Queue::fake();
    bulk($this, array_map(fn ($i) => "r$i@example.com", range(1, 120)));
    $jobs = Queue::pushed(SendMessages::class);
    expect($jobs)->toHaveCount(3)->and($jobs->every(fn ($j) => count($j->messageIds) <= SendMessages::SIZE))->toBeTrue();

    foreach ($jobs as $job) {
        app()->call([$job, 'handle']);
    }
    expect(Message::where('status', 'sent')->count())->toBe(120)
        ->and($this->aws->argsFor('SendEmail'))->toHaveCount(120)
        ->and(max($perSecond))->toBeLessThanOrEqual(40);
});

it('settles each email of a parallel group on its own: sent, rejected, retried or suppressed', function () {
    Suppression::create(['workspace_id' => $this->ws->id, 'email' => 'bounced@example.com', 'reason' => 'hard_bounce', 'source' => 'ses']);
    $this->aws->responses['SendEmail'] = fn (array $a) => match ($a['Destination']['ToAddresses'][0]) {
        'rejected@example.com' => '!MessageRejected',
        'slowdown@example.com' => '!Throttling',
        default => ['MessageId' => '0100'.bin2hex(random_bytes(12)).'-000000'],
    };
    Queue::fake();
    bulk($this, ['ok1@example.com', 'rejected@example.com', 'slowdown@example.com', 'ok2@example.com', 'bounced@example.com']);
    Queue::assertPushed(SendMessages::class, 1);
    app()->call([Queue::pushed(SendMessages::class)->first(), 'handle']);

    $status = Message::pluck('status', 'to_email')->all();
    expect($status)->toMatchArray(['ok1@example.com' => 'sent', 'ok2@example.com' => 'sent', 'rejected@example.com' => 'rejected',
        'slowdown@example.com' => 'queued', 'bounced@example.com' => 'suppressed'])
        ->and(Message::where('to_email', 'slowdown@example.com')->value('error'))->toContain('slow down')
        // The suppressed address never reached SES (the SDK itself retries a throttled call twice before giving up).
        ->and(collect($this->aws->argsFor('SendEmail'))->map(fn ($a) => $a['Destination']['ToAddresses'][0])->unique()->sort()->values()->all())
        ->toBe(['ok1@example.com', 'ok2@example.com', 'rejected@example.com', 'slowdown@example.com']);
});

it('hands out send-rate slots across workers, a second at a time', function () {
    $conn = SesConnection::firstOrFail();
    $conn->forceFill(['max_send_rate' => 5])->save();
    $pacer = app(Pacer::class);
    $start = time();
    expect($pacer->take($conn, 8))->toBe(5)
        ->and($pacer->take($conn, 3))->toBe(3)          // this second is full: waits for the next one
        ->and(time())->toBeGreaterThan($start)
        ->and($pacer->take($conn, 1, 0))->toBeIn([0, 1]); // no waiting allowed: only if a slot is left
});

it('reuses one SES client per connection, and makes a new one when the key changes', function () {
    $conn = SesConnection::firstOrFail();
    $aws = app(AwsFactory::class);
    $a = $aws->sender($conn);
    expect($aws->sender($conn))->toBe($a);
    $conn->secret_access_key = 'a-rotated-secret-key-value-000000000000';
    expect($aws->sender($conn))->not->toBe($a);
});
