<?php

namespace App\Http\Controllers\Api\V1\Activity;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\WebhookEndpoint;
use App\Models\Workspace;
use App\Services\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public API (full-access keys): manage webhook endpoints from code, so an integration (e.g. a site's own MailVesper module)
 * can register its own endpoint when it connects and remove it when it disconnects. Same rules as the app.
 */
class WebhookApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => WebhookEndpoint::where('workspace_id', $this->workspace($request)->id)->orderBy('created_at')->get()
            ->map(fn (WebhookEndpoint $e) => WebhookController::present($e))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $ws = $this->workspace($request);
        $data = WebhookController::validated($request);
        if (WebhookEndpoint::where('workspace_id', $ws->id)->count() >= 20) {
            throw new ApiException('endpoint_limit', 'You can have up to 20 webhook endpoints.', 422);
        }
        $e = WebhookEndpoint::create($data + ['workspace_id' => $ws->id, 'secret' => WebhookEndpoint::newSecret()]);
        Audit::log('webhook.created', null, $ws->id, ['url' => $e->url, 'api_key' => $this->key($request)->prefix]);

        return response()->json(['endpoint' => WebhookController::present($e), 'secret' => $e->secret], 201);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $ws = $this->workspace($request);
        $e = WebhookEndpoint::where('workspace_id', $ws->id)->where('id', $id)->first() ?? throw new ApiException('not_found', 'No webhook endpoint with that id.', 404);
        $e->delete();
        Audit::log('webhook.deleted', null, $ws->id, ['url' => $e->url, 'api_key' => $this->key($request)->prefix]);

        return response()->json(['deleted' => true]);
    }

    private function workspace(Request $request): Workspace
    {
        /** @var Workspace $w */
        $w = $request->attributes->get('workspace');

        return $w;
    }

    private function key(Request $request): ApiKey
    {
        /** @var ApiKey $k */
        $k = $request->attributes->get('api_key');

        return $k;
    }
}
