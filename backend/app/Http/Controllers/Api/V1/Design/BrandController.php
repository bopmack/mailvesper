<?php

namespace App\Http\Controllers\Api\V1\Design;

use App\Exceptions\ApiException;
use App\Http\Controllers\Concerns\WorkspaceAccess;
use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Services\Design\DocValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Brand kit: logo, colours, font, company details and social links, applied to new designs. */
class BrandController extends Controller
{
    use WorkspaceAccess;

    public const COLORS = ['button', 'link', 'text', 'heading', 'background', 'content'];

    public const SOCIALS = ['x', 'instagram', 'linkedin', 'youtube', 'facebook', 'tiktok'];

    public function show(Workspace $workspace): JsonResponse
    {
        return response()->json(['brand' => $workspace->brand ?? (object) [], 'fonts' => DocValidator::FONTS]);
    }

    public function update(Request $request, Workspace $workspace): JsonResponse
    {
        $this->requireManager($request, 'change the brand kit');
        $in = $request->all();
        $out = [];
        $url = fn ($v) => is_string($v) && preg_match('#^https://[^\s"<>]+$#', $v) && strlen($v) <= 500;
        if (! empty($in['logo_url'])) {
            $url($in['logo_url']) ? $out['logo_url'] = $in['logo_url'] : throw new ApiException('validation_failed', 'The logo must be an https:// image link.', 422, ['logo_url' => 'Use an https:// link.']);
        }
        foreach (self::COLORS as $k) {
            $v = $in['colors'][$k] ?? null;
            if ($v !== null && $v !== '') {
                is_string($v) && preg_match('/^#[0-9a-f]{6}$/i', $v) ? $out['colors'][$k] = strtoupper($v) : throw new ApiException('validation_failed', "The $k colour must look like #5B5BF7.", 422, ["colors.$k" => 'Use a hex colour like #5B5BF7.']);
            }
        }
        if (! empty($in['font'])) {
            in_array($in['font'], DocValidator::FONTS, true) ? $out['font'] = $in['font'] : throw new ApiException('validation_failed', 'Pick one of the email-safe fonts.', 422, ['font' => 'Unknown font.']);
        }
        foreach (['company' => 100, 'address' => 200] as $k => $max) {
            if (isset($in[$k]) && is_string($in[$k]) && trim($in[$k]) !== '') {
                $out[$k] = mb_substr(trim($in[$k]), 0, $max);
            }
        }
        foreach (self::SOCIALS as $k) {
            $v = $in['socials'][$k] ?? null;
            if ($v !== null && $v !== '') {
                $url($v) ? $out['socials'][$k] = $v : throw new ApiException('validation_failed', "The $k link must start with https://.", 422, ["socials.$k" => 'Use an https:// link.']);
            }
        }
        $workspace->forceFill(['brand' => $out ?: null])->save();

        return response()->json(['brand' => $out ?: (object) []]);
    }
}
