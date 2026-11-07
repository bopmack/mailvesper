<?php

namespace App\Services\Platform;

use App\Exceptions\ApiException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * The operator's brand, from Platform → Branding: the name shown everywhere instead of "MailVesper", the accent
 * colour, the company and postal address for email footers, and the logo, dark logo and icon. Uploads are decoded
 * and re-encoded as PNG (no SVG: it can carry script), so only real pixels are ever served.
 */
class Brand
{
    /** kind → [max width, max height] after scaling down */
    public const IMAGES = ['logo' => [800, 240], 'logo_dark' => [800, 240], 'icon' => [512, 512]];

    public const MAX_BYTES = 2 * 1024 * 1024;

    public static function name(): string
    {
        try {
            $n = trim((string) Settings::get('brand.name'));
        } catch (Throwable) {
            return 'MailVesper'; // no app or database (early boot, unit tests): never break a message over the name
        }

        return $n !== '' ? mb_substr($n, 0, 60) : 'MailVesper';
    }

    /** The accent colour as #RRGGBB, or null for the built-in one. */
    public static function accent(): ?string
    {
        $c = trim((string) Settings::get('brand.accent'));

        return preg_match('/^#[0-9A-Fa-f]{6}$/', $c) ? strtoupper($c) : null;
    }

    public static function company(): ?string
    {
        $c = trim((string) Settings::get('brand.company'));

        return $c !== '' ? mb_substr($c, 0, 120) : null;
    }

    public static function address(): ?string
    {
        $a = trim((string) Settings::get('brand.address'));

        return $a !== '' ? mb_substr($a, 0, 400) : null;
    }

    /** A public URL for an uploaded image, with its version so browsers fetch a new one after a change. */
    public static function url(string $kind): ?string
    {
        $path = (string) Settings::get('brand.'.$kind);
        if ($path === '' || ! str_starts_with($path, 'media/brand/') || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        return rtrim((string) config('app.frontend_url'), '/').'/'.$path;
    }

    /**
     * What the public pages, the app and emails need. Everything here is safe to show anyone.
     *
     * @return array{name: string, accent: ?string, company: ?string, address: ?string, logo: ?string, logo_dark: ?string, icon: ?string, custom: bool}
     */
    public static function public(): array
    {
        $out = ['name' => self::name(), 'accent' => self::accent(), 'company' => self::company(), 'address' => self::address(),
            'logo' => self::url('logo'), 'logo_dark' => self::url('logo_dark'), 'icon' => self::url('icon')];

        return $out + ['custom' => $out['name'] !== 'MailVesper' || $out['logo'] !== null || $out['icon'] !== null || $out['accent'] !== null];
    }

    /** Stores an uploaded logo or icon (replacing the previous one) and returns its URL. */
    public static function upload(string $kind, UploadedFile $file, ?string $userId): string
    {
        if (! isset(self::IMAGES[$kind])) {
            throw new ApiException('not_found', 'Unknown image.', 404);
        }
        if ($file->getSize() > self::MAX_BYTES) {
            throw new ApiException('file_too_large', 'Logos can be up to 2 MB.', 422, ['file' => 'Up to 2 MB.']);
        }
        $info = @getimagesize((string) $file->getRealPath());
        if (! $info || ! in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) {
            throw new ApiException('not_an_image', 'Upload a PNG, JPG or WebP image (PNG keeps a transparent background).', 422, ['file' => 'PNG, JPG or WebP.']);
        }
        [$w, $h, $type] = $info;
        if ($w < 16 || $h < 16 || $w > 6000 || $h > 6000) {
            throw new ApiException('not_an_image', 'Use an image between 16 and 6000 pixels on each side.', 422, ['file' => 'Wrong size.']);
        }
        $src = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg((string) $file->getRealPath()),
            IMAGETYPE_WEBP => @imagecreatefromwebp((string) $file->getRealPath()),
            default => @imagecreatefrompng((string) $file->getRealPath()),
        };
        if (! $src) {
            throw new ApiException('not_an_image', 'That image couldn’t be read. Try saving it again as PNG.', 422, ['file' => 'Unreadable.']);
        }
        [$maxW, $maxH] = self::IMAGES[$kind];
        $scale = min(1, $maxW / $w, $maxH / $h);
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, (int) imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        ob_start();
        imagepng($dst, null, 9);
        $png = (string) ob_get_clean();
        imagedestroy($src);
        imagedestroy($dst);

        $path = 'media/brand/'.$kind.'-'.strtolower((string) Str::ulid()).'.png';
        Storage::disk('public')->put($path, $png);
        self::removeFile($kind);
        Settings::set('brand.'.$kind, $path, $userId);

        return (string) self::url($kind);
    }

    public static function remove(string $kind, ?string $userId): void
    {
        if (! isset(self::IMAGES[$kind])) {
            throw new ApiException('not_found', 'Unknown image.', 404);
        }
        self::removeFile($kind);
        Settings::set('brand.'.$kind, null, $userId);
    }

    private static function removeFile(string $kind): void
    {
        $old = (string) Settings::get('brand.'.$kind);
        if ($old !== '' && str_starts_with($old, 'media/brand/')) {
            Storage::disk('public')->delete($old);
        }
    }
}
