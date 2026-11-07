<?php

use App\Mail\LimitAlertMail;
use App\Models\SesConnection;
use App\Services\Ses\DnsLookup;
use App\Services\Ses\LimitWatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\FakeAws;
use Tests\Support\FakeDns;

beforeEach(function () {
    FakeAws::install();
    $this->app->instance(DnsLookup::class, new FakeDns);
    [$this->ws, $this->people] = sesWorkspace($this, ['admin', 'member']);
    sesConnect($this, $this->ws)->assertOk();
    $this->conn = SesConnection::firstOrFail();
    Mail::fake();
});

it('warns owners and admins before the SES quota and AWS review lines, once a day while it lasts', function () {
    $this->conn->forceFill(['max_24h_send' => 50000, 'sent_last_24h' => 41000, 'bounce_rate' => 0.042, 'complaint_rate' => 0.0002, 'production_access' => true])->save();
    $watch = app(LimitWatch::class);
    expect($watch->run($this->ws))->toBe(['quota', 'bounce']);
    Mail::assertSent(LimitAlertMail::class, 2); // owner and admin, not the member
    Mail::assertSent(LimitAlertMail::class, fn ($m) => $m->hasTo('admin@example.com') && str_starts_with($m->envelope()->subject, 'Heads up: You’ve used 82% of your SES daily quota'));
    Mail::assertNotSent(LimitAlertMail::class, fn ($m) => $m->hasTo('member@example.com'));

    expect($watch->run($this->ws))->toBe([]); // nothing new: no second email the same hour
    $this->conn->forceFill(['bounce_rate' => 0.051])->save();
    expect($watch->run($this->ws))->toBe(['bounce']); // worse: right away
    Mail::assertSent(LimitAlertMail::class, fn ($m) => str_starts_with($m->envelope()->subject, 'Action needed: Your bounce rate is 5.1%'));
    $this->travel(25)->hours();
    expect($watch->run($this->ws))->toBe(['bounce', 'quota']); // a daily reminder, the urgent one first
    $this->conn->forceFill(['sent_last_24h' => 1000, 'bounce_rate' => 0.01])->save();
    expect($watch->run($this->ws))->toBe([])->and(LimitWatch::active($this->ws->id))->toBe([]);
    expect(DB::table('workspace_alerts')->whereNotNull('cleared_at')->count())->toBe(2);
});

it('treats a paused or reviewed account and spam reports as urgent, and shows them on the Sending page', function () {
    $this->conn->forceFill(['sending_enabled' => false, 'enforcement_status' => 'PROBATION', 'complaint_rate' => 0.0012])->save();
    expect(app(LimitWatch::class)->run($this->ws))->toBe(['sending_paused', 'enforcement', 'complaint']);
    $alerts = $this->getJson("/v1/workspaces/{$this->ws->id}/smtp")->assertOk()->json('alerts');
    expect(collect($alerts)->pluck('level')->unique()->all())->toBe(['urgent'])
        ->and(collect($alerts)->firstWhere('kind', 'complaint')['title'])->toBe('Spam reports are at 0.12%');
    $this->artisan('limits:check')->assertSuccessful();
});

it('uses its own numbers when AWS has none, and only with enough sends', function () {
    $this->conn->forceFill(['bounce_rate' => null, 'complaint_rate' => null])->save();
    $row = fn (string $status, int $i) => ['id' => strtolower((string) Str::ulid()), 'workspace_id' => $this->ws->id, 'tracking_code' => 'SL-T'.str_pad((string) $i, 7, '0', STR_PAD_LEFT),
        'from_email' => 'news@acme.com', 'to_email' => "r$i@example.com", 'subject' => 's', 'status' => $status, 'source' => 'api', 'type' => 'marketing', 'sent_at' => now()->subDay(), 'created_at' => now()->subDay(), 'updated_at' => now()];
    DB::table('messages')->insert(array_map(fn ($i) => $row($i <= 6 ? 'bounced' : 'delivered', $i), range(1, 60)));
    expect(app(LimitWatch::class)->conditions($this->ws))->toBe([]); // 60 sends: too few to judge
    DB::table('messages')->insert(array_map(fn ($i) => $row('delivered', $i), range(61, 120)));
    expect(app(LimitWatch::class)->conditions($this->ws)['bounce']['title'])->toBe('Your bounce rate is 5%')
        ->and(app(LimitWatch::class)->conditions($this->ws)['bounce']['detail'])->toStartWith('Measured by MailVesper (AWS has no numbers yet).');
});

it('writes round percentages in full', function () {
    $this->conn->forceFill(['max_24h_send' => 200, 'sent_last_24h' => 200, 'bounce_rate' => 0.1, 'complaint_rate' => 0])->save();
    $c = app(LimitWatch::class)->conditions($this->ws);
    expect($c['quota']['title'])->toBe('You’ve used 100% of your SES daily quota')->and($c['bounce']['title'])->toBe('Your bounce rate is 10%');
});
