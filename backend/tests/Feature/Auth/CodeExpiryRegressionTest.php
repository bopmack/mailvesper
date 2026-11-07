<?php

use App\Enums\CodePurpose;
use App\Exceptions\ApiException;
use App\Models\EmailCode;
use App\Services\EmailCodeService;
use Illuminate\Support\Facades\Schema;

it('stores code expiry as DATETIME so MariaDB cannot auto-update it', function () {
    expect(Schema::getColumnType('email_codes', 'expires_at'))->toBe('datetime');
});

it('keeps the expiry unchanged after a wrong attempt, so the right code still works', function () {
    $codes = app(EmailCodeService::class);
    $code = $codes->issue('amara@example.com', CodePurpose::Login);
    $before = EmailCode::firstOrFail()->expires_at->toIso8601String();

    $this->travel(1)->minutes();
    expect(fn () => $codes->verify('amara@example.com', CodePurpose::Login, $code === '000000' ? '111111' : '000000'))->toThrow(ApiException::class);
    expect(EmailCode::firstOrFail()->expires_at->toIso8601String())->toBe($before);

    $codes->verify('amara@example.com', CodePurpose::Login, $code); // must not throw
    expect(EmailCode::firstOrFail()->consumed_at)->not->toBeNull();
});
