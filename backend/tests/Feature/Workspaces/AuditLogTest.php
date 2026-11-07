<?php

use App\Models\AuditLog;
use App\Models\Workspace;
use App\Services\Webhooks\UrlGuard;

function auditWorkspace(): array
{
    $owner = verifiedUser(['email' => 'owner@example.com', 'name' => 'Olu Owner']);
    $ws = Workspace::create(['name' => 'Acme', 'slug' => 'acme', 'owner_id' => $owner->id]);
    $ws->members()->attach($owner->id, ['role' => 'owner']);
    $owner->forceFill(['current_workspace_id' => $ws->id])->save();

    return [$ws, $owner];
}

function auditLogin($test, string $email): void
{
    app('auth')->forgetGuards();
    $test->postJson('/v1/auth/logout');
    app('auth')->forgetGuards();
    $test->postJson('/v1/auth/login', ['email' => $email, 'password' => STRONG_PASSWORD])->assertOk();
}

it('shows the workspace’s events newest first with who did it', function () {
    [$ws] = auditWorkspace();
    auditLogin($this, 'owner@example.com');
    UrlGuard::$resolver = fn (string $host) => ['93.184.216.34'];
    $this->postJson("/v1/workspaces/{$ws->id}/webhooks", ['url' => 'https://hooks.example.com/sendline', 'events' => ['*']])->assertCreated();
    UrlGuard::$resolver = null;

    $events = $this->getJson("/v1/workspaces/{$ws->id}/audit-logs")->assertOk()->json('events');
    expect($events[0]['action'])->toBe('webhook.created')
        ->and($events[0]['actor'])->toBe(['name' => 'Olu Owner', 'email' => 'owner@example.com'])
        ->and($events[0]['meta'])->toMatchArray(['url' => 'https://hooks.example.com/sendline'])
        ->and($events[1]['action'])->toBe('auth.login')
        ->and($events[0]['at'])->toMatch('/^\d{4}-\d\d-\d\dT/');
});

it('filters by category and pages with next_before', function () {
    [$ws, $owner] = auditWorkspace();
    foreach (range(1, 60) as $i) {
        AuditLog::create(['action' => $i % 2 ? 'team.invited' : 'security.two_factor_enabled', 'user_id' => $owner->id, 'workspace_id' => $ws->id]);
    }
    auditLogin($this, 'owner@example.com');

    $page = $this->getJson("/v1/workspaces/{$ws->id}/audit-logs")->assertOk();
    expect($page->json('events'))->toHaveCount(50)->and($page->json('next_before'))->toBeInt();
    $rest = $this->getJson("/v1/workspaces/{$ws->id}/audit-logs?before=".$page->json('next_before'))->assertOk();
    expect($rest->json('events'))->toHaveCount(11)->and($rest->json('next_before'))->toBeNull(); // 10 seeded + the sign-in

    $team = $this->getJson("/v1/workspaces/{$ws->id}/audit-logs?category=team")->json('events');
    expect(collect($team)->pluck('action')->unique()->all())->toBe(['team.invited']);
    $this->getJson("/v1/workspaces/{$ws->id}/audit-logs?category=nope")->assertStatus(422);
});

it('never shows another workspace’s events', function () {
    [$ws, $owner] = auditWorkspace();
    $other = Workspace::create(['name' => 'Other', 'slug' => 'other', 'owner_id' => $owner->id]);
    AuditLog::create(['action' => 'team.invited', 'workspace_id' => $other->id, 'meta' => ['email' => 'secret@example.com']]);
    auditLogin($this, 'owner@example.com');
    expect(collect($this->getJson("/v1/workspaces/{$ws->id}/audit-logs")->json('events'))->pluck('meta.email')->filter()->all())->toBe([]);
    $this->getJson("/v1/workspaces/{$other->id}/audit-logs")->assertNotFound(); // not a member
});

it('is for owners and admins only', function () {
    [$ws] = auditWorkspace();
    foreach (['admin' => 200, 'member' => 403, 'viewer' => 403] as $role => $status) {
        $u = verifiedUser(['email' => "$role@example.com"]);
        $ws->members()->attach($u->id, ['role' => $role]);
        auditLogin($this, "$role@example.com");
        $this->getJson("/v1/workspaces/{$ws->id}/audit-logs")->assertStatus($status);
    }
    app('auth')->forgetGuards();
    $this->postJson('/v1/auth/logout');
    app('auth')->forgetGuards();
    $this->getJson("/v1/workspaces/{$ws->id}/audit-logs")->assertUnauthorized();
});
