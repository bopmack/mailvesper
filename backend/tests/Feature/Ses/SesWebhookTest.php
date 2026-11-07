<?php

use App\Models\SesConnection;
use App\Services\Ses\DnsLookup;
use Aws\Sns\Message;
use Aws\Sns\MessageValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeAws;
use Tests\Support\FakeDns;

const SNS_CERT_URL = 'https://sns.eu-west-1.amazonaws.com/SimpleNotificationService-test.pem';

beforeEach(function () {
    FakeAws::install();
    $this->app->instance(DnsLookup::class, new FakeDns);

    // A stand-in for Amazon's SNS signing certificate.
    $this->snsKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    $cert = openssl_csr_sign(openssl_csr_new(['commonName' => 'sns.amazonaws.com'], $this->snsKey), null, $this->snsKey, 1);
    openssl_x509_export($cert, $pem);
    $this->app->instance(MessageValidator::class, new MessageValidator(fn (string $url) => $url === SNS_CERT_URL ? $pem : throw new RuntimeException('unexpected cert url')));

    Http::fake(['sns.eu-west-1.amazonaws.com/*' => Http::response('<ConfirmSubscriptionResponse/>', 200), 'api.pwnedpasswords.com/*' => Http::response('', 200)]);

    [$this->ws] = sesWorkspace($this);
    sesConnect($this, $this->ws)->assertOk();
    $this->conn = SesConnection::first();
    $this->url = '/v1/webhooks/ses/'.$this->conn->webhook_token;
});

/** Builds an SNS message signed like Amazon does (SignatureVersion 2, SHA256). */
function snsMessage($test, array $fields, $key = null): array
{
    $m = $fields + [
        'MessageId' => (string) Str::uuid(), 'TopicArn' => $test->conn->sns_topic_arn, 'Timestamp' => now()->toIso8601ZuluString(),
        'SignatureVersion' => '2', 'SigningCertURL' => SNS_CERT_URL,
    ];
    openssl_sign((new MessageValidator)->getStringToSign(new Message($m + ['Signature' => 'x'])), $sig, $key ?? $test->snsKey, OPENSSL_ALGO_SHA256);

    return $m + ['Signature' => base64_encode($sig)];
}

function postSns($test, array $message, ?string $url = null)
{
    return $test->call('POST', $url ?? $test->url, [], [], [], ['CONTENT_TYPE' => 'text/plain; charset=UTF-8', 'HTTP_X_AMZ_SNS_MESSAGE_TYPE' => $message['Type']], json_encode($message));
}

it('confirms the subscription by visiting Amazon’s SubscribeURL', function () {
    $m = snsMessage($this, ['Type' => 'SubscriptionConfirmation', 'Message' => 'You have chosen to subscribe', 'Token' => 'tok',
        'SubscribeURL' => 'https://sns.eu-west-1.amazonaws.com/?Action=ConfirmSubscription&TopicArn=x&Token=tok']);
    postSns($this, $m)->assertOk();
    Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://sns.eu-west-1.amazonaws.com/?Action=ConfirmSubscription'));
    $this->getJson("/v1/workspaces/{$this->ws->id}/ses")->assertJsonPath('connection.events_receiving', true)->assertJsonPath('connection.steps.subscribe.status', 'done');
});

it('stores SES events once, even when SNS delivers twice', function () {
    $event = ['eventType' => 'Delivery', 'mail' => ['messageId' => '0102-abc', 'timestamp' => '2026-10-24T09:30:00.000Z', 'destination' => ['amara@example.com']],
        'delivery' => ['timestamp' => '2026-10-24T09:30:02.000Z', 'smtpResponse' => '250 2.0.0 OK']];
    $m = snsMessage($this, ['Type' => 'Notification', 'Message' => json_encode($event)]);
    postSns($this, $m)->assertOk();
    postSns($this, $m)->assertOk();

    $row = DB::table('ses_events')->first();
    expect(DB::table('ses_events')->count())->toBe(1)
        ->and($row->event_type)->toBe('Delivery')->and($row->message_id)->toBe('0102-abc')
        ->and($row->occurred_at)->toBe('2026-10-24 09:30:02')->and(json_decode($row->recipients))->toBe(['amara@example.com']);
    expect(SesConnection::first()->last_event_at)->not->toBeNull();
});

it('rejects forged, tampered and foreign-topic messages', function () {
    $event = json_encode(['eventType' => 'Bounce', 'mail' => ['messageId' => 'm1']]);
    $forger = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    postSns($this, snsMessage($this, ['Type' => 'Notification', 'Message' => $event], $forger))->assertForbidden();

    $tampered = snsMessage($this, ['Type' => 'Notification', 'Message' => $event]);
    $tampered['Message'] = json_encode(['eventType' => 'Complaint', 'mail' => ['messageId' => 'm1']]);
    postSns($this, $tampered)->assertForbidden();

    postSns($this, snsMessage($this, ['Type' => 'Notification', 'Message' => $event, 'TopicArn' => 'arn:aws:sns:eu-west-1:999999999999:sendline-events']))->assertForbidden();

    $badCert = snsMessage($this, ['Type' => 'Notification', 'Message' => $event]);
    $badCert['SigningCertURL'] = 'https://evil.example.com/cert.pem';
    postSns($this, $badCert)->assertForbidden();

    $this->call('POST', $this->url, [], [], [], ['CONTENT_TYPE' => 'text/plain'], 'not json')->assertForbidden();
    postSns($this, snsMessage($this, ['Type' => 'Notification', 'Message' => $event]), '/v1/webhooks/ses/'.str_repeat('a', 48))->assertNotFound();
    expect(DB::table('ses_events')->count())->toBe(0);
});

it('never follows a SubscribeURL that isn’t Amazon SNS', function () {
    $m = snsMessage($this, ['Type' => 'SubscriptionConfirmation', 'Message' => 'x', 'Token' => 't', 'SubscribeURL' => 'https://evil.example.com/steal']);
    postSns($this, $m)->assertForbidden();
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'evil.example.com'));
    expect(SesConnection::first()->subscription_confirmed_at)->toBeNull();
});
