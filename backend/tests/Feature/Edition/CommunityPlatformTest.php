<?php

use App\Mail\SystemTestMail;
use App\Services\Platform\Settings;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->admin = verifiedUser(['email' => 'admin@example.com', 'name' => 'Ada Admin']); // Community's one admin
});

it('locks the platform dashboard on Community, with a code the app shows as the Pro page', function () {
    $this->actingAs($this->admin);
    foreach (['/v1/platform/system', '/v1/platform/admins'] as $u) {
        $this->getJson($u)->assertForbidden()->assertJsonPath('error.code', 'pro_required');
    }
    $this->postJson('/v1/platform/backups')->assertForbidden()->assertJsonPath('error.code', 'pro_required');
    $this->postJson('/v1/platform/alerts/test')->assertForbidden();
    $this->putJson('/v1/platform/admins', ['emails' => ['admin@example.com']])->assertForbidden();
    $this->deleteJson('/v1/platform/brand/logo')->assertForbidden();
});

it('lets Community’s admin set only what its features need, and nothing else through the same endpoint', function () {
    $this->actingAs($this->admin);
    $g = $this->getJson('/v1/platform/settings')->assertOk()->json('groups');
    expect(array_keys($g))->toEqualCanonicalizing(['System email', 'AI', 'Sending', 'Setup checks', 'Complaint feedback loops'])
        ->and(collect($g['AI'])->pluck('key'))->toContain('ai.openrouter_key')->not->toContain('ai.chat_per_hour');
    $this->putJson('/v1/platform/settings', ['settings' => ['ai.model' => 'openai/gpt-5.6', 'mail.host' => 'smtp.example.net', 'brand.name' => 'Mine', 'retention.body_days' => '5']])
        ->assertOk()->assertJsonPath('changed', ['ai.model', 'mail.host']);
    expect(Settings::get('ai.model'))->toBe('openai/gpt-5.6')->and(Settings::get('brand.name'))->toBe('MailVesper')->and(Settings::get('retention.body_days'))->not->toBe(5);
});

it('still tests the system email on Community', function () {
    $this->actingAs($this->admin);
    Mail::fake();
    $this->postJson('/v1/platform/mail/test', ['to' => 'boss@example.com'])->assertOk()->assertJson(['ok' => true]);
    Mail::assertSent(SystemTestMail::class);
});

it('keeps Community’s settings to its admin', function () {
    $this->actingAs(verifiedUser(['email' => 'other@example.com']));
    $this->getJson('/v1/platform/settings')->assertForbidden()->assertJsonPath('error.code', 'forbidden');
});
