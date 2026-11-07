<?php

namespace App\Http\Controllers\Api\V1\Integrations;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Services\Integrations\Ingest;
use App\Services\Integrations\Shopify;
use App\Services\Integrations\Stripe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Where Stripe and Shopify post their webhooks. Only signed requests are read. */
class IntegrationWebhookController extends Controller
{
    public function __construct(private Ingest $ingest) {}

    public function stripe(Request $request, string $token): JsonResponse
    {
        $i = $this->integration('stripe', $token);
        $raw = $request->getContent();
        if (! $i->secret || ! Stripe::verify((string) $request->header('Stripe-Signature'), $raw, $i->secret)) {
            return $this->refuse($i, 'A request with a missing or wrong Stripe signature was refused. Check the signing secret.');
        }
        $event = json_decode($raw, true);

        return $this->ok($i, is_array($event) ? $this->ingest->handle($i, (string) ($event['id'] ?? ''), $event) : 'ignored: not JSON');
    }

    public function shopify(Request $request, string $token): JsonResponse
    {
        $i = $this->integration('shopify', $token);
        $raw = $request->getContent();
        if (! $i->secret || ! Shopify::verify((string) $request->header('X-Shopify-Hmac-Sha256'), $raw, $i->secret)) {
            return $this->refuse($i, 'A request with a missing or wrong Shopify signature was refused. Check the signing secret.');
        }
        $payload = json_decode($raw, true);
        $id = (string) ($request->header('X-Shopify-Webhook-Id') ?: $request->header('X-Shopify-Event-Id') ?: hash('sha256', $raw));

        return $this->ok($i, is_array($payload) ? $this->ingest->handle($i, $id, $payload, (string) $request->header('X-Shopify-Topic')) : 'ignored: not JSON');
    }

    private function integration(string $provider, string $token): Integration
    {
        $i = Integration::where('provider', $provider)->where('token', $token)->first();
        if (! $i || ! $i->enabled) {
            throw new ApiException('not_found', 'This webhook address isn’t active.', 404);
        }

        return $i;
    }

    private function refuse(Integration $i, string $why): JsonResponse
    {
        $i->forceFill(['last_error' => $why, 'last_error_at' => now()])->save();

        return response()->json(['error' => ['code' => 'bad_signature', 'message' => 'Signature check failed.']], 400);
    }

    private function ok(Integration $i, string $result): JsonResponse
    {
        if ($i->last_error && ! str_starts_with($result, 'ignored')) {
            $i->forceFill(['last_error' => null, 'last_error_at' => null])->save();
        }

        return response()->json(['received' => true, 'result' => $result]);
    }
}
