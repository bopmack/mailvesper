<?php

use App\Services\Webhooks\UrlGuard;
use Tests\Support\FakeAws;

afterEach(fn () => UrlGuard::$resolver = null);

it('connects to exactly the address it checked, so DNS can’t switch to a private one in between', function () {
    $calls = 0;
    UrlGuard::$resolver = function (string $host) use (&$calls) {
        return ++$calls === 1 ? ['93.184.216.34'] : ['127.0.0.1']; // a rebinding DNS server
    };
    expect(UrlGuard::vet('https://hooks.example.com/x'))->toBe([null, '93.184.216.34'])->and($calls)->toBe(1);
    expect(UrlGuard::vet('https://hooks.example.com/x'))->toBe(['That address points to a private network. Use a public URL.', null]);
});

it('keeps viewers from exporting contacts or making the app call AWS, and members from bulk export', function () {
    FakeAws::install();
    [$ws] = sesWorkspace($this, ['viewer', 'member']);
    sesConnect($this, $ws)->assertOk();
    switchTo($this, 'viewer@example.com');
    $this->get("/v1/workspaces/{$ws->id}/contacts/export")->assertForbidden();
    $this->postJson("/v1/workspaces/{$ws->id}/ses/sync")->assertForbidden();
    $this->postJson("/v1/workspaces/{$ws->id}/copilot/rescan")->assertForbidden();
    switchTo($this, 'member@example.com');
    $this->get("/v1/workspaces/{$ws->id}/contacts/export")->assertForbidden();
    switchTo($this, 'owner@example.com');
    $this->get("/v1/workspaces/{$ws->id}/contacts/export")->assertOk();
});
