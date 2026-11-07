<?php

use App\Models\Message;
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

function deliver($test, string $to, string $type = 'transactional'): Message
{
    $mailer = app(Mailer::class);
    $m = $mailer->submit($test->ws, [$mailer->validate($test->ws, ['from' => 'news@acme.com', 'to' => $to, 'subject' => 'Hello there', 'html' => '<p>Hi</p>', 'type' => $type])], 'api')[0]->refresh();

    return $m;
}

/** Feeds an SES event (stored raw first, like the SNS webhook does). */
function sesFeed($test, Message $m, string $type, array $section = [], string $at = '2026-10-24T09:00:00Z'): void
{
    $key = lcfirst($type);
    $event = ['eventType' => $type, 'mail' => ['messageId' => $m->ses_message_id, 'timestamp' => $at, 'destination' => [$m->to_email], 'tags' => ['sl_message' => [$m->id]]], $key => $section + ['timestamp' => $at]];
    $id = DB::table('ses_events')->insertGetId(['workspace_id' => $test->ws->id, 'event_type' => $type, 'message_id' => $m->ses_message_id, 'sns_message_id' => (string) Str::uuid(),
        'payload' => json_encode($event), 'created_at' => now()]);
    app(EventProcessor::class)->apply($test->ws->id, $event, $id);
}

const HUMAN_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

it('records the full journey of an email with real and machine opens', function () {
    $m = deliver($this, 'ceo@acme.io');
    $t = fn (int $sec) => now()->addSeconds($sec)->toIso8601ZuluString(); // after the real 'accepted' time
    sesFeed($this, $m, 'Send', [], $t(1));
    sesFeed($this, $m, 'Delivery', ['smtpResponse' => '250 2.0.0 OK 1729760000 gsmtp', 'remoteMtaIp' => '142.250.1.1', 'processingTimeMillis' => 812], $t(2));
    sesFeed($this, $m, 'Open', ['userAgent' => 'Mozilla/5.0', 'ipAddress' => '17.58.0.1'], $t(10));                  // Apple privacy proxy
    sesFeed($this, $m, 'Click', ['link' => 'https://acme.io/a', 'userAgent' => HUMAN_UA, 'ipAddress' => '1.2.3.4'], $t(3)); // scanner (1 s after delivery)
    sesFeed($this, $m, 'Open', ['userAgent' => HUMAN_UA, 'ipAddress' => '1.2.3.4'], $t(720));
    sesFeed($this, $m, 'Click', ['link' => 'https://acme.io/pricing', 'userAgent' => HUMAN_UA, 'ipAddress' => '1.2.3.4'], $t(750));

    $r = $this->getJson("/v1/workspaces/{$this->ws->id}/activity/{$m->tracking_code}")->assertOk();
    expect($r->json('status'))->toBe('clicked')->and($r->json('provider'))->toBe('google')
        ->and($r->json('counts'))->toBe(['opens' => 1, 'machine_opens' => 1, 'clicks' => 1]);
    $types = collect($r->json('events'))->map(fn ($e) => $e['type'].($e['machine'] ? '*' : ''))->all();
    expect($types)->toBe(['accepted', 'sent', 'delivered', 'clicked*', 'opened*', 'opened', 'clicked']);
    expect(collect($r->json('events'))->firstWhere('type', 'delivered')['data'])->toMatchArray(['remote_mta_ip' => '142.250.1.1', 'processing_ms' => 812]);
    expect($this->getJson("/v1/workspaces/{$this->ws->id}/activity/{$m->id}/raw")->json('events'))->toHaveCount(6);
});

it('lists, filters, searches and counts', function () {
    $a = deliver($this, 'amara@gmail.com');
    sesFeed($this, $a, 'Delivery', ['smtpResponse' => '250 ok gsmtp']);
    $b = deliver($this, 'bo@outlook.com');
    sesFeed($this, $b, 'Bounce', ['bounceType' => 'Permanent', 'bounceSubType' => 'General', 'bouncedRecipients' => [['emailAddress' => 'bo@outlook.com', 'diagnosticCode' => 'smtp; 550 5.1.1']]]);
    $c = deliver($this, 'bo@outlook.com'); // now suppressed

    $base = "/v1/workspaces/{$this->ws->id}/activity";
    $all = $this->getJson($base)->assertOk();
    expect($all->json('messages'))->toHaveCount(3)->and($all->json('counts'))->toMatchArray(['delivered' => 1, 'bounced' => 1, 'suppressed' => 1]);
    expect($this->getJson("$base?status=bounced")->json('messages.0.id'))->toBe($b->id)
        ->and($this->getJson("$base?q={$c->tracking_code}")->json('messages.0.id'))->toBe($c->id)
        ->and($this->getJson("$base?q=AMARA")->json('messages'))->toHaveCount(1)
        ->and($this->getJson("$base?provider=microsoft")->json('messages'))->toHaveCount(2);
    $csv = $this->get("$base/export?status=bounced")->assertOk()->streamedContent();
    expect(substr_count(trim($csv), "\n"))->toBe(1)->and($csv)->toContain($b->tracking_code);
});

it('computes Overview numbers that match the timeline', function () {
    $this->travelTo(now()->setTime(12, 0)); // "an hour ago" must still be today, whatever time the suite runs
    foreach (['a@gmail.com', 'b@gmail.com', 'c@icloud.com'] as $to) {
        $m = deliver($this, $to, 'marketing');
        sesFeed($this, $m, 'Send', [], now()->subHour()->toIso8601ZuluString());
        sesFeed($this, $m, 'Delivery', ['smtpResponse' => '250 ok'], now()->subHour()->toIso8601ZuluString());
    }
    $ms = Message::orderBy('id')->get();
    sesFeed($this, $ms[0], 'Open', ['userAgent' => HUMAN_UA], now()->subMinutes(30)->toIso8601ZuluString());
    sesFeed($this, $ms[0], 'Open', ['userAgent' => HUMAN_UA], now()->subMinutes(20)->toIso8601ZuluString()); // same person again: unique opens stay 1
    sesFeed($this, $ms[0], 'Click', ['link' => 'https://acme.com', 'userAgent' => HUMAN_UA], now()->subMinutes(20)->toIso8601ZuluString());
    sesFeed($this, $ms[2], 'Open', ['userAgent' => 'Mozilla/5.0'], now()->subMinutes(50)->toIso8601ZuluString());

    $s = $this->getJson("/v1/workspaces/{$this->ws->id}/stats?days=7")->assertOk();
    expect($s->json('totals'))->toBe(['sent' => 3, 'delivered' => 3, 'bounced' => 0, 'complained' => 0, 'opened' => 1, 'machine' => 1, 'clicked' => 1])
        ->and($s->json('series'))->toHaveCount(7)->and($s->json('series.6.delivered'))->toBe(3)
        ->and(collect($s->json('providers'))->pluck('sent', 'provider')->all())->toBe(['google' => 2, 'apple' => 1]);
    expect($this->getJson("/v1/workspaces/{$this->ws->id}/stats?type=transactional")->json('totals.sent'))->toBe(0);
});
