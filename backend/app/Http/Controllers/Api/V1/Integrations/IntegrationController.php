<?php

namespace App\Http\Controllers\Api\V1\Integrations;

use App\Http\Controllers\Concerns\WorkspaceAccess;
use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Models\Workspace;
use App\Services\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Stripe and Shopify connections (owners and admins: they hold signing secrets). */
class IntegrationController extends Controller
{
    use WorkspaceAccess;

    public function index(Workspace $workspace): JsonResponse
    {
        $have = Integration::where('workspace_id', $workspace->id)->get()->keyBy('provider');

        return response()->json(['integrations' => array_map(fn ($p) => isset($have[$p]) ? self::present($have[$p]) : ['provider' => $p, 'connected' => false], Integration::PROVIDERS)]);
    }

    public function save(Request $request, Workspace $workspace, string $provider): JsonResponse
    {
        $this->requireManager($request, 'connect integrations');
        $i = Integration::where('workspace_id', $workspace->id)->where('provider', $provider)->first();
        $d = $request->validate([
            'secret' => [$i ? 'sometimes' : 'required', 'string', 'max:300', $provider === 'stripe' ? 'regex:/^whsec_[A-Za-z0-9]+$/' : 'regex:/^[A-Za-z0-9_\-]{10,300}$/'],
            'shop_domain' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/i'],
            'enabled' => ['sometimes', 'boolean'],
        ], ['secret.regex' => $provider === 'stripe' ? 'Paste the endpoint’s signing secret: it starts with whsec_.' : 'Paste the webhook signing secret shown under Settings → Notifications → Webhooks.',
            'shop_domain.regex' => 'Use the store’s .myshopify.com address, like acme.myshopify.com.']);
        $i ??= new Integration(['workspace_id' => $workspace->id, 'provider' => $provider, 'token' => Str::lower(Str::random(40))]);
        $i->fill(array_intersect_key($d, array_flip(['secret', 'shop_domain', 'enabled'])))->save();
        Audit::log('integration.saved', $this->currentUser($request), $workspace->id, ['provider' => $provider]);

        return response()->json(['integration' => self::present($i)]);
    }

    public function destroy(Request $request, Workspace $workspace, string $provider): JsonResponse
    {
        $this->requireManager($request, 'disconnect integrations');
        Integration::where('workspace_id', $workspace->id)->where('provider', $provider)->delete();
        Audit::log('integration.removed', $this->currentUser($request), $workspace->id, ['provider' => $provider]);

        return response()->json(['deleted' => true]);
    }

    /** @return array<string, mixed> */
    public static function present(Integration $i): array
    {
        return ['provider' => $i->provider, 'connected' => true, 'enabled' => $i->enabled, 'has_secret' => (bool) $i->secret, 'shop_domain' => $i->shop_domain,
            'webhook_url' => rtrim((string) config('app.url'), '/')."/v1/integrations/{$i->provider}/{$i->token}",
            'events_received' => $i->events_received, 'last_event_at' => $i->last_event_at?->toIso8601ZuluString(),
            'last_error' => $i->last_error, 'last_error_at' => $i->last_error_at?->toIso8601ZuluString()];
    }
}
