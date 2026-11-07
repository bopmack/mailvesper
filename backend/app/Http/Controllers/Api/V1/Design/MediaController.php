<?php

namespace App\Http\Controllers\Api\V1\Design;

use App\Exceptions\ApiException;
use App\Http\Controllers\Concerns\WorkspaceAccess;
use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Models\Workspace;
use App\Services\Design\MediaStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/** The workspace's image library for emails. */
class MediaController extends Controller
{
    use WorkspaceAccess;

    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        $rows = Media::where('workspace_id', $workspace->id)->when($request->input('before'), fn ($q, $b) => $q->where('id', '<', $b))->orderByDesc('id')->limit(61)->get();

        return response()->json(['media' => $rows->take(60)->map(fn (Media $m) => self::present($m))->values(), 'next_before' => $rows->count() > 60 ? $rows[59]->id : null]);
    }

    public function store(Request $request, Workspace $workspace, MediaStore $store): JsonResponse
    {
        $this->requireEditor($request, 'upload images');
        $file = $request->file('file');
        if (! $file instanceof UploadedFile || ! $file->isValid()) {
            throw new ApiException('validation_failed', 'Choose an image to upload.', 422, ['file' => 'Choose an image.']);
        }
        if (Media::where('workspace_id', $workspace->id)->count() >= 2000) {
            throw new ApiException('media_limit', 'Your image library is full (2,000 images). Delete some first.', 422);
        }

        return response()->json(['media' => self::present($store->store($workspace, $this->currentUser($request), $file))], 201);
    }

    public function destroy(Request $request, Workspace $workspace, string $id): JsonResponse
    {
        $this->requireEditor($request, 'delete images');
        $m = Media::where('workspace_id', $workspace->id)->find($id) ?? throw new ApiException('not_found', 'Not found.', 404);
        Storage::disk('public')->delete($m->path);
        $m->delete();

        return response()->json(['deleted' => true]);
    }

    /** @return array<string, mixed> */
    public static function present(Media $m): array
    {
        return ['id' => $m->id, 'filename' => $m->filename, 'url' => $m->url, 'mime' => $m->mime, 'size' => $m->size, 'width' => $m->width, 'height' => $m->height, 'created_at' => $m->created_at?->toIso8601ZuluString()];
    }
}
