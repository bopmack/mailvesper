<?php

use App\Enums\CodePurpose;
use App\Http\Controllers\Api\V1\Auth\OAuthController;
use App\Mail\CodeMail;
use App\Mail\SystemTestMail;
use App\Services\Platform\Brand;
use App\Services\Platform\Runtime;
use App\Services\Platform\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->admin = verifiedUser(['email' => 'admin@example.com', 'name' => 'Ada Admin']); // the first account: the platform admin
    Storage::fake('public');
    asProEdition(); // the panel is MailVesper Pro; Community's part is in CommunityPlatformTest
});

/** A real PNG of the given size (transparent background with a square). */
function pngUpload(int $w = 400, int $h = 120, string $name = 'logo.png'): UploadedFile
{
    $im = imagecreatetruecolor($w, $h);
    imagesavealpha($im, true);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
    imagefilledrectangle($im, 10, 10, (int) ($w / 2), (int) ($h / 2), imagecolorallocate($im, 91, 91, 247));
    $path = tempnam(sys_get_temp_dir(), 'png');
    imagepng($im, $path);

    return new UploadedFile($path, $name, 'image/png', null, true);
}

it('keeps the whole panel to platform admins', function () {
    $this->actingAs(verifiedUser(['email' => 'someone@example.com']));
    $this->putJson('/v1/platform/settings', ['settings' => ['brand.name' => 'Mine']])->assertForbidden();
    $this->postJson('/v1/platform/brand/logo', ['file' => pngUpload()])->assertForbidden();
    $this->postJson('/v1/platform/mail/test', ['to' => 'x@example.com'])->assertForbidden();
    $this->putJson('/v1/platform/admins', ['emails' => ['someone@example.com']])->assertForbidden();
});

it('rebrands the installation: name, colour and footer, shown publicly and in its own emails', function () {
    $this->actingAs($this->admin);
    $this->putJson('/v1/platform/settings', ['settings' => ['brand.name' => 'Northwind Mail', 'brand.accent' => '#e0a800', 'brand.company' => 'Northwind Ltd', 'brand.address' => '1 Marina, Lagos']])->assertOk();
    $this->getJson('/v1/auth/setup')->assertOk()->assertJsonPath('brand.name', 'Northwind Mail')->assertJsonPath('brand.accent', '#E0A800')->assertJsonPath('brand.custom', true);

    $mail = new CodeMail('123456', CodePurpose::Login);
    expect($mail->envelope()->subject)->toBe('123456 is your Northwind Mail sign-in code');
    $html = $mail->render();
    expect($html)->toContain('Northwind Mail')->toContain('Northwind Ltd · 1 Marina, Lagos')->not->toContain('MailVesper');
});

it('refuses brand values that could break a page, an email or a script', function () {
    $this->actingAs($this->admin);
    foreach (['<b>x</b>', 'A $(rm -rf /)', 'Back`tick`', 'Quote"d', str_repeat('a', 61)] as $bad) {
        $this->putJson('/v1/platform/settings', ['settings' => ['brand.name' => $bad]])->assertStatus(422)->assertJsonPath('error.fields', fn ($f) => isset($f['settings.brand.name']));
    }
    $this->putJson('/v1/platform/settings', ['settings' => ['brand.accent' => 'red']])->assertStatus(422);
    $this->putJson('/v1/platform/settings', ['settings' => ['brand.name' => "O'Brien & Co. (Mail)"]])->assertOk();
    expect(Brand::name())->toBe("O'Brien & Co. (Mail)");
});

it('takes a logo, dark logo and icon as re-encoded PNGs, and removes them', function () {
    $this->actingAs($this->admin);
    $url = $this->post('/v1/platform/brand/logo', ['file' => pngUpload(1600, 400)], ['Accept' => 'application/json'])->assertOk()->json('url');
    $path = (string) Settings::get('brand.logo');
    expect($path)->toStartWith('media/brand/logo-')->toEndWith('.png')->and($url)->toEndWith($path);
    Storage::disk('public')->assertExists($path);
    [$w, $h] = getimagesizefromstring(Storage::disk('public')->get($path));
    expect([$w, $h])->toBe([800, 200]); // scaled down to fit 800 × 240

    $this->post('/v1/platform/brand/icon', ['file' => pngUpload(64, 64, 'i.png')], ['Accept' => 'application/json'])->assertOk();
    $setup = $this->getJson('/v1/auth/setup')->json('brand');
    expect($setup['logo'])->toBe($url)->and($setup['icon'])->toEndWith('.png')->and($setup['custom'])->toBeTrue();

    // Replacing deletes the old file; removing clears it.
    $this->post('/v1/platform/brand/logo', ['file' => pngUpload()], ['Accept' => 'application/json'])->assertOk();
    Storage::disk('public')->assertMissing($path);
    $newPath = (string) Settings::get('brand.logo');
    $this->deleteJson('/v1/platform/brand/logo')->assertOk()->assertJsonPath('brand.logo', null);
    Storage::disk('public')->assertMissing($newPath);
});

it('refuses SVGs, fake images and unknown slots', function () {
    $this->actingAs($this->admin);
    $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
    $this->post('/v1/platform/brand/logo', ['file' => $svg], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('error.code', 'not_an_image');
    $fake = UploadedFile::fake()->createWithContent('logo.png', 'not really a png');
    $this->post('/v1/platform/brand/logo', ['file' => $fake], ['Accept' => 'application/json'])->assertStatus(422);
    $this->post('/v1/platform/brand/banner', ['file' => pngUpload()], ['Accept' => 'application/json'])->assertNotFound();
    expect(Storage::disk('public')->allFiles())->toBe([]);
});

it('sends the installation’s own email through the server set in the panel', function () {
    $this->actingAs($this->admin);
    $this->putJson('/v1/platform/settings', ['settings' => ['mail.encryption' => 'starttls']])->assertStatus(422);
    $this->putJson('/v1/platform/settings', ['settings' => ['mail.from_address' => 'not-an-email']])->assertStatus(422);
    $this->putJson('/v1/platform/settings', ['settings' => ['mail.custom' => '1', 'mail.host' => 'smtp.example.net', 'mail.port' => '465', 'mail.encryption' => 'ssl',
        'mail.username' => 'apikey', 'mail.password' => 's3cret-pass', 'mail.from_address' => 'no-reply@northwind.test', 'brand.name' => 'Northwind Mail']])->assertOk();
    expect(DB::table('platform_settings')->where('key', 'mail.password')->value('value'))->not->toContain('s3cret');

    Runtime::apply();
    expect(config('mail.default'))->toBe('smtp')->and(config('mail.mailers.smtp.host'))->toBe('smtp.example.net')->and(config('mail.mailers.smtp.port'))->toBe(465)
        ->and(config('mail.mailers.smtp.scheme'))->toBe('smtps')->and(config('mail.mailers.smtp.password'))->toBe('s3cret-pass')
        ->and(config('mail.from.address'))->toBe('no-reply@northwind.test')->and(config('mail.from.name'))->toBe('Northwind Mail');

    Mail::fake();
    $this->postJson('/v1/platform/mail/test', ['to' => 'boss@northwind.test'])->assertOk()->assertJson(['ok' => true]);
    Mail::assertSent(SystemTestMail::class, fn ($m) => $m->hasTo('boss@northwind.test') && $m->envelope()->subject === 'Test email from Northwind Mail');
});

it('turns on sign-in buttons from keys saved in the panel', function () {
    $this->actingAs($this->admin);
    expect(OAuthController::configured('google'))->toBeFalse();
    $this->putJson('/v1/platform/settings', ['settings' => ['oauth.google_id' => 'abc.apps.googleusercontent.com', 'oauth.google_secret' => 'gsecret']])->assertOk();
    $this->putJson('/v1/platform/settings', ['settings' => ['oauth.apple_private_key' => 'just text']])->assertStatus(422);
    Runtime::apply();
    expect(OAuthController::configured('google'))->toBeTrue();
    $this->getJson('/v1/auth/providers')->assertOk()->assertJsonPath('google', true)->assertJsonPath('github', false);
});

it('names platform admins by email, never an unknown one, and never removes yourself', function () {
    $this->actingAs($this->admin);
    verifiedUser(['email' => 'boss@northwind.test', 'name' => 'The Boss']);
    $this->putJson('/v1/platform/admins', ['emails' => ['admin@example.com', 'Boss@Northwind.test']])->assertOk()->assertJsonCount(2, 'admins');
    $this->putJson('/v1/platform/admins', ['emails' => ['admin@example.com', 'ghost@example.com']])->assertStatus(422)->assertJsonPath('error.message', fn ($m) => str_contains($m, 'ghost@example.com'));
    $this->putJson('/v1/platform/admins', ['emails' => ['boss@northwind.test']])->assertStatus(422)->assertJsonPath('error.message', fn ($m) => str_contains($m, 'Keep yourself'));
    $this->getJson('/v1/platform/admins')->assertOk()->assertJsonCount(2, 'admins');
});

it('shows each section’s fields with their choices', function () {
    $this->actingAs($this->admin);
    $g = $this->getJson('/v1/platform/settings')->assertOk()->json('groups');
    expect($g)->toHaveKeys(['Branding', 'System email', 'Sign in with Google', 'Sign in with Apple', 'AI']);
    expect(collect($g['System email'])->firstWhere('key', 'mail.encryption')['options'])->toBe(['tls', 'ssl', 'none'])
        ->and(collect($g['System email'])->firstWhere('key', 'mail.password')['secret'])->toBeTrue()
        ->and(collect($g['Branding'])->pluck('key')->all())->not->toContain('brand.logo'); // set by upload only
});
