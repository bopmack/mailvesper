<?php

use App\Models\Message;
use App\Models\Suppression;
use App\Services\Sending\Mailer;
use App\Services\Ses\DnsLookup;
use App\Services\Ses\EventProcessor;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeAws;
use Tests\Support\FakeDns;

beforeEach(function () {
    $this->aws = FakeAws::install();
    $this->app->instance(DnsLookup::class, new FakeDns);
    [$this->ws] = sesWorkspace($this);
    sesConnect($this, $this->ws)->assertOk();
});

function sendOne($test, string $to, string $type = 'transactional'): Message
{
    $mailer = app(Mailer::class);
    $p = $mailer->validate($test->ws, ['from' => 'news@acme.com', 'to' => $to, 'subject' => 'Hi', 'html' => '<p>Hi</p>', 'type' => $type]);
    $m = $mailer->submit($test->ws, [$p], 'api')[0];

    return $m->refresh();
}

function sesEvent(Message $m, string $type, array $extra = []): array
{
    return ['eventType' => $type, 'mail' => ['messageId' => $m->ses_message_id, 'destination' => [$m->to_email], 'tags' => ['sl_message' => [$m->id]]]] + $extra;
}

it('moves messages forward with events and never backwards', function () {
    $m = sendOne($this, 'amara@example.com');
    $p = app(EventProcessor::class);
    $p->apply($this->ws->id, sesEvent($m, 'Delivery', ['delivery' => ['timestamp' => '2026-10-24T09:30:02.000Z']]));
    $p->apply($this->ws->id, sesEvent($m, 'Send')); // late
    expect($m->refresh())->status->toBe('delivered')->delivered_at->toDateTimeString()->toBe('2026-10-24 09:30:02');
});

it('finds the message by tag when the event beats the sent write', function () {
    $m = sendOne($this, 'amara@example.com');
    $m->forceFill(['ses_message_id' => null])->save();
    app(EventProcessor::class)->apply($this->ws->id, ['eventType' => 'Delivery', 'mail' => ['messageId' => 'ses-123', 'tags' => ['sl_message' => [$m->id]]], 'delivery' => ['timestamp' => '2026-10-24T09:30:02Z']]);
    expect($m->refresh())->status->toBe('delivered')->ses_message_id->toBe('ses-123');
});

it('suppresses hard bounces and complaints; soft bounces show as bounced but are not suppressed', function () {
    $p = app(EventProcessor::class);
    $soft = sendOne($this, 'full@example.com');
    $p->apply($this->ws->id, sesEvent($soft, 'Bounce', ['bounce' => ['bounceType' => 'Transient', 'bouncedRecipients' => [['emailAddress' => 'full@example.com']]]]));
    $hard = sendOne($this, 'gone@example.com');
    $p->apply($this->ws->id, sesEvent($hard, 'Bounce', ['bounce' => ['bounceType' => 'Permanent', 'bounceSubType' => 'General', 'bouncedRecipients' => [['emailAddress' => 'gone@example.com', 'diagnosticCode' => 'smtp; 550 5.1.1 user unknown']]]]));
    $spam = sendOne($this, 'angry@example.com');
    $p->apply($this->ws->id, sesEvent($spam, 'Complaint', ['complaint' => ['complaintFeedbackType' => 'abuse', 'complainedRecipients' => [['emailAddress' => 'angry@example.com']]]]));

    expect(DB::table('suppressions')->pluck('reason', 'email')->all())->toBe(['gone@example.com' => 'hard_bounce', 'angry@example.com' => 'complaint'])
        ->and($hard->refresh()->status)->toBe('bounced')->and($spam->refresh()->status)->toBe('complained')->and($soft->refresh()->status)->toBe('bounced'); // SES gave up, so it wasn't delivered, but a full mailbox isn't suppressed
    expect(sendOne($this, 'gone@example.com')->status)->toBe('suppressed');
});

it('unsubscribes with one click and takes effect immediately', function () {
    $m = sendOne($this, 'amara@example.com', 'marketing');
    $this->getJson("/v1/unsubscribe/{$m->unsubscribe_token}")->assertOk()->assertJson(['sender' => 'Acme', 'email' => 'am•••@example.com', 'unsubscribed' => false]);

    // What Gmail sends (RFC 8058): a form POST with List-Unsubscribe=One-Click, no cookies.
    $this->call('POST', "/v1/unsubscribe/{$m->unsubscribe_token}", ['List-Unsubscribe' => 'One-Click'], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'])->assertOk();
    expect(Suppression::check($this->ws->id, ['amara@example.com']))->toBe(['amara@example.com' => 'unsubscribe']);
    expect(sendOne($this, 'amara@example.com', 'marketing')->status)->toBe('suppressed');

    $this->postJson("/v1/unsubscribe/{$m->unsubscribe_token}/resubscribe")->assertOk()->assertJsonPath('unsubscribed', false);
    $this->getJson('/v1/unsubscribe/'.str_repeat('a', 40))->assertNotFound();
});

it('never lets a resubscribe undo a bounce or complaint', function () {
    $m = sendOne($this, 'amara@example.com', 'marketing');
    $this->postJson("/v1/unsubscribe/{$m->unsubscribe_token}")->assertOk();
    Suppression::add($this->ws->id, 'amara@example.com', 'complaint');
    $this->postJson("/v1/unsubscribe/{$m->unsubscribe_token}/resubscribe")->assertOk()->assertJsonPath('unsubscribed', true);
    expect(DB::table('suppressions')->value('reason'))->toBe('complaint');
});

it('stops marketing after an unsubscribe but still delivers transactional email; bounces and complaints stop both', function () {
    Suppression::add($this->ws->id, 'left@example.com', 'unsubscribe');
    Suppression::add($this->ws->id, 'gone@example.com', 'hard_bounce');
    Suppression::add($this->ws->id, 'angry@example.com', 'complaint');
    expect(sendOne($this, 'left@example.com', 'marketing')->status)->toBe('suppressed')
        ->and(sendOne($this, 'left@example.com')->status)->not->toBe('suppressed') // a password reset still arrives
        ->and(sendOne($this, 'gone@example.com')->status)->toBe('suppressed')
        ->and(sendOne($this, 'angry@example.com')->status)->toBe('suppressed');
});
