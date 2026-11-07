<?php

namespace App\Http\Controllers\Api\V1\Sending;

use App\Exceptions\ApiException;
use App\Http\Controllers\Concerns\WorkspaceAccess;
use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\Workspace;
use App\Services\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Settings → API keys. Owners and admins manage them; the full key is shown once. */
class ApiKeyController extends Controller
{
    use WorkspaceAccess;

    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        $this->requireManager($request, 'see API keys');

        return response()->json(['keys' => ApiKey::where('workspace_id', $workspace->id)->whereNull('revoked_at')->orderByDesc('created_at')->get()->map(fn (ApiKey $k) => self::present($k))]);
    }

    public function store(Request $request, Workspace $workspace): JsonResponse
    {
        $this->requireManager($request, 'create API keys');
        $data = $request->validate(['name' => ['required', 'string', 'min:1', 'max:80'], 'scope' => ['nullable', Rule::in(ApiKey::SCOPES)]]);
        if (ApiKey::where('workspace_id', $workspace->id)->whereNull('revoked_at')->count() >= 50) {
            throw new ApiException('key_limit', 'You can have up to 50 active API keys.', 422);
        }
        [$key, $plain] = ApiKey::generate($workspace->id, trim($data['name']), $data['scope'] ?? 'send', $this->currentUser($request)->id);
        Audit::log('api_key.created', $this->currentUser($request), $workspace->id, ['name' => $key->name, 'scope' => $key->scope, 'prefix' => $key->prefix]);

        return response()->json(['key' => self::present($key), 'secret' => $plain], 201);
    }

    public function destroy(Request $request, Workspace $workspace, string $id): JsonResponse
    {
        $this->requireManager($request, 'revoke API keys');
        $key = ApiKey::where('workspace_id', $workspace->id)->whereNull('revoked_at')->find($id) ?? throw new ApiException('not_found', 'Not found.', 404);
        $key->forceFill(['revoked_at' => now()])->save();
        Audit::log('api_key.revoked', $this->currentUser($request), $workspace->id, ['name' => $key->name, 'prefix' => $key->prefix]);

        return response()->json(['revoked' => true]);
    }

    /** @return array<string, mixed> */
    public static function present(ApiKey $k): array
    {
        return ['id' => $k->id, 'name' => $k->name, 'scope' => $k->scope, 'prefix' => $k->prefix, 'last_used_at' => $k->last_used_at?->toIso8601String(), 'created_at' => $k->created_at?->toIso8601String()];
    }
}
