<?php

use App\Services\Ops\Health;
use App\Services\Ops\State;
use App\Support\WebCron;
use Illuminate\Support\Carbon;

beforeEach(function () {
    verifiedUser(['email' => 'owner@example.com']); // installed
});

it('runs exactly the tasks whose time came since the last visit', function () {
    $now = Carbon::parse('2026-11-06 05:30:20');
    $this->travelTo($now);
    State::put('web_cron_at', $now->copy()->subHours(3)->toIso8601ZuluString()); // 02:30:20: 03:15, 04:10 and 05:00 were missed

    $ran = WebCron::run($now);

    expect($ran)->toContain('ops:heartbeat', 'contacts:engagement', 'retention:prune', 'copilot:scan', 'journeys:scan')
        ->and(array_filter($ran, fn ($c) => str_starts_with($c, 'queue:work database')))->toBe([])
        ->and(end($ran))->toBe('queue:work')
        ->and(State::get('web_heartbeat'))->not->toBeNull()
        ->and(State::get('heartbeat'))->toBeNull() // not mistaken for a cron job
        ->and(Health::snapshot()['scheduler'])->toMatchArray(['ok' => false, 'mode' => 'visits']); // a safety net, not a substitute for cron

    // A minute later only the every-minute tasks are due.
    $this->travelTo($now->copy()->addMinute());
    $again = WebCron::run(now());
    expect($again)->toContain('ops:heartbeat')->not->toContain('contacts:engagement')->not->toContain('journeys:scan');
});

it('stands down for a real cron job, and runs at most once a minute', function () {
    State::put('heartbeat', now()->toIso8601ZuluString());
    WebCron::maybeRun();
    expect(State::get('web_cron_at'))->toBeNull();

    State::put('heartbeat', now()->subMinutes(10)->toIso8601ZuluString()); // cron stopped
    State::put('web_cron_at', now()->subSeconds(20)->toIso8601ZuluString());
    WebCron::maybeRun();
    expect(State::get('web_heartbeat'))->toBeNull(); // ran 20 s ago: not yet

    $this->travel(1)->minutes();
    WebCron::maybeRun();
    expect(State::get('web_heartbeat'))->not->toBeNull();

    config(['sendline.web_cron' => false]);
    State::put('web_heartbeat', null);
    $this->travel(5)->minutes();
    WebCron::maybeRun();
    expect(State::get('web_heartbeat'))->toBeNull();
});

it('tells the installer and the admin whether the cron job runs, with the command for cPanel', function () {
    $this->getJson('/v1/auth/cron')->assertOk()->assertExactJson(['running' => false]);
    State::put('web_heartbeat', now()->toIso8601ZuluString()); // page visits don't count
    $this->getJson('/v1/auth/cron')->assertExactJson(['running' => false]);
    State::put('heartbeat', now()->toIso8601ZuluString());
    $this->getJson('/v1/auth/cron')->assertExactJson(['running' => true]);

    $this->getJson('/v1/platform/cron')->assertUnauthorized();
    $this->postJson('/v1/auth/login', ['email' => 'owner@example.com', 'password' => STRONG_PASSWORD])->assertOk();
    $r = $this->getJson('/v1/platform/cron')->assertOk();
    expect($r->json('running'))->toBeTrue()
        ->and($r->json('command'))->toStartWith('cd '.base_path().' && ')->toEndWith(' artisan schedule:run >> /dev/null 2>&1')
        ->and($r->json('line'))->toBe('* * * * * '.$r->json('command'));
});
