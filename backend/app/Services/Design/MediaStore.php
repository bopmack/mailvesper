<?php

namespace App\Services\Design;

use App\Exceptions\ApiException;
use App\Models\Media;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Images for emails. Every upload is decoded and re-encoded (which strips metadata and anything that
 * isn't a real image), and anything wider than 1600 px is scaled down. GIFs keep their animation.
 */
class MediaStore
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    public const MAX_WIDTH = 1600;

    public const TYPES = [IMAGETYPE_JPEG => ['image/jpeg', 'jpg'], IMAGETYPE_PNG => ['image/png', 'png'], IMAGETYPE_GIF => ['image/gif', 'gif'], IMAGETYPE_WEBP => ['image/webp', 'webp']];

    public function store(Workspace $ws, User $user, UploadedFile $file): Media
    {
        if ($file->getSize() > self::MAX_BYTES) {
            throw new ApiException('file_too_large', 'Images can be up to 5 MB.', 422, ['file' => 'Up to 5 MB.']);
        }
        $info = @getimagesize((string) $file->getRealPath());
        if (! $info || ! isset(self::TYPES[$info[2]])) {
            throw new ApiException('not_an_image', 'Upload a JPG, PNG, GIF or WebP image.', 422, ['file' => 'JPG, PNG, GIF or WebP.']);
        }
        [$w, $h, $type] = $info;
        if ($w < 1 || $h < 1 || $w > 8000 || $h > 8000) {
            throw new ApiException('not_an_image', 'Images can be up to 8000 × 8000 pixels.', 422, ['file' => 'Too large.']);
        }
        [$mime, $ext] = self::TYPES[$type];
        $id = strtolower((string) Str::ulid());
        $path = "media/{$ws->id}/$id.$ext";
        $bytes = $type === IMAGETYPE_GIF ? (string) file_get_contents((string) $file->getRealPath()) : $this->reencode((string) $file->getRealPath(), $type, $w, $h);
        Storage::disk('public')->put($path, $bytes);

        $media = new Media([
            'workspace_id' => $ws->id, 'filename' => mb_substr($file->getClientOriginalName(), 0, 255), 'path' => $path,
            'url' => rtrim((string) config('app.frontend_url'), '/').'/'.$path, 'mime' => $mime, 'size' => strlen($bytes), 'width' => $w, 'height' => $h,
            'created_by' => $user->id, 'created_at' => now(),
        ]);
        $media->id = $id; // the file is named after the record
        $media->save();

        return $media;
    }

    /** @param-out int $w @param-out int $h */
    private function reencode(string $src, int $type, int &$w, int &$h): string
    {
        $img = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($src),
            IMAGETYPE_PNG => @imagecreatefrompng($src),
            default => @imagecreatefromwebp($src),
        };
        if (! $img) {
            throw new ApiException('not_an_image', 'This image couldn’t be read. Try saving it again as JPG or PNG.', 422, ['file' => 'Unreadable image.']);
        }
        if ($w > self::MAX_WIDTH) {
            $nh = (int) round($h * self::MAX_WIDTH / $w);
            $scaled = imagescale($img, self::MAX_WIDTH, $nh, IMG_BICUBIC);
            if ($scaled) {
                imagedestroy($img);
                $img = $scaled;
                [$w, $h] = [self::MAX_WIDTH, $nh];
            }
        }
        ob_start();
        match ($type) {
            IMAGETYPE_JPEG => imagejpeg($img, null, 85),
            IMAGETYPE_PNG => (function () use ($img) {
                imagesavealpha($img, true);
                imagepng($img, null, 7);
            })(),
            default => imagewebp($img, null, 85),
        };
        imagedestroy($img);

        return (string) ob_get_clean();
    }
}
