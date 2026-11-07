<?php

namespace App\Http\Controllers\Api\V1\Activity;

use App\Exceptions\ApiException;
use App\Http\Controllers\Concerns\WorkspaceAccess;
use App\Http\Controllers\Controller;
use App\Jobs\DeliverWebhook;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Models\Workspace;
use App\Services\Audit;
use App\Services\Platform\Brand;
use App\Services\Webhooks\UrlGuard;
use App\Services\Webhooks\Webhooks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Webhook endpoints, their deliveries, test pings and replays. Owners and admins only. */
class WebhookController extends Controller
{
    use WorkspaceAccess;

    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        $this->requireManager($request, 'manage webhooks');
        $stats = DB::table('webhook_deliveries')->where('workspace_id', $workspace->id)->where('created_at', '>=', now()->subDay())->where('event_type', '!=', 'email.test')
            ->selectRaw("endpoint_id, count(*) as total, sum(case when status = 'success' then 1 else 0 end) as ok, avg(case when status = 'success' then duration_ms end) as ms,
                sum(case when status = 'retrying' then 1 else 0 end) as retrying")->groupBy('endpoint_id')->get()->keyBy('endpoint_id');

        return response()->json([
            'events' => collect(Webhooks::EVENTS)->map(fn ($d, $k) => ['type' => $k, 'description' => $d])->values(),
            'endpoints' => WebhookEndpoint::where('workspace_id', $workspace->id)->orderBy('created_at')->get()->map(fn (WebhookEndpoint $e) => self::present($e) + [
                'stats_24h' => ['total' => (int) ($stats[$e->id]->total ?? 0), 'success' => (int) ($stats[$e->id]->ok ?? 0), 'retrying' => (int) ($stats[$e->id]->retrying ?? 0),
                    'avg_ms' => isset($stats[$e->id]->ms) ? (int) round((float) $stats[$e->id]->ms) : null],
            ]),
        ]);
    }

    public function store(Request $request, Workspace $workspace): JsonResponse
    {
        $this->requireManager($request, 'manage webhooks');
        $data = $this->validated($request);
        if (WebhookEndpoint::where('workspace_id', $workspace->id)->count() >= 20) {
            throw new ApiException('endpoint_limit', 'You can have up to 20 webhook endpoints.', 422);
        }
        $e = WebhookEndpoint::create($data + ['workspace_id' => $workspace->id, 'secret' => WebhookEndpoint::newSecret(), 'created_by' => $this->currentUser($request)->id]);
        Audit::log('webhook.created', $this->currentUser($request), $workspace->id, ['url' => $e->url]);

        return response()->json(['endpoint' => self::present($e), 'secret' => $e->secret], 201);
    }

    public function update(Request $request, Workspace $workspace, string $id): JsonResponse
    {
        $this->requireManager($request, 'manage webhooks');
        $e = $this->endpoint($workspace, $id);
        $data = $this->validated($request, partial: true);
        if (array_key_exists('enabled', $data) && $data['enabled'] && ! $e->enabled) {
            $data += ['failure_streak' => 0, 'disabled_reason' => null];
        }
        $e->forceFill($data)->save();
        Audit::log('webhook.updated', $this->currentUser($request), $workspace->id, ['url' => $e->url] + array_intersect_key($data, ['enabled' => 1]));

        return response()->json(['endpoint' => self::present($e)]);
    }

    public function destroy(Request $request, Workspace $workspace, string $id): JsonResponse
    {
        $this->requireManager($request, 'manage webhooks');
        $e = $this->endpoint($workspace, $id);
        $e->delete();
        Audit::log('webhook.deleted', $this->currentUser($request), $workspace->id, ['url' => $e->url]);

        return response()->json(['deleted' => true]);
    }

    public function secret(Request $request, Workspace $workspace, string $id): JsonResponse
    {
        $this->requireManager($request, 'see webhook secrets');

        return response()->json(['secret' => $this->endpoint($workspace, $id)->secret]);
    }

    public function rotate(Request $request, Workspace $workspace, string $id): JsonResponse
    {
        $this->requireManager($request, 'manage webhooks');
        $e = $this->endpoint($workspace, $id);
        $e->forceFill(['secret' => WebhookEndpoint::newSecret()])->save();
        Audit::log('webhook.secret_rotated', $this->currentUser($request), $workspace->id, ['url' => $e->url]);

        return response()->json(['secret' => $e->secret]);
    }

    /** Sends a sample event right now and returns what the endpoint answered. */
    public function test(Request $request, Workspace $workspace, string $id): JsonResponse
    {
        $this->requireManager($request, 'manage webhooks');
        $e = $this->endpoint($workspace, $id);
        $d = WebhookDelivery::create(['endpoint_id' => $e->id, 'workspace_id' => $workspace->id, 'event_type' => 'email.test', 'status' => 'sending', 'attempts' => 1, 'payload' => [
            'type' => 'email.test', 'created_at' => now()->toIso8601ZuluString(),
            'data' => ['id' => '01JTEST00000000000000000000', 'tracking_code' => 'SL-TEST-0000', 'to' => 'amara@example.com', 'subject' => 'Test event from '.Brand::name(), 'provider' => 'google'],
        ]]);
        DeliverWebhook::attempt($d, $e);

        return response()->json(['delivery' => self::presentDelivery($d->refresh(), true)]);
    }

    public function deliveries(Request $request, Workspace $workspace, string $id): JsonResponse
    {
        $this->requireManager($request, 'manage webhooks');
        $e = $this->endpoint($workspace, $id);
        $data = $request->validate(['status' => ['nullable', 'in:success,failed,retrying,pending'], 'before' => ['nullable', 'string', 'size:26']]);
        $rows = WebhookDelivery::where('endpoint_id', $e->id)->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($data['before'] ?? null, fn ($q, $b) => $q->where('id', '<', $b))->orderByDesc('id')->limit(51)->get();

        return response()->json(['deliveries' => $rows->take(50)->map(fn ($d) => self::presentDelivery($d))->values(), 'next_before' => $rows->count() > 50 ? $rows[49]->id : null]);
    }

    public function delivery(Request $request, Workspace $workspace, string $deliveryId): JsonResponse
    {
        $this->requireManager($request, 'manage webhooks');

        return response()->json(['delivery' => self::presentDelivery($this->findDelivery($workspace, $deliveryId), true)]);
    }

    /** Sends the same payload again now, as a new delivery. */
    public function replay(Request $request, Workspace $workspace, string $deliveryId): JsonResponse
    {
        $this->requireManager($request, 'manage webhooks');
        $old = $this->findDelivery($workspace, $deliveryId);
        $e = $this->endpoint($workspace, $old->endpoint_id);
        $d = WebhookDelivery::create(['endpoint_id' => $e->id, 'workspace_id' => $workspace->id, 'event_type' => $old->event_type, 'message_id' => $old->message_id,
            'payload' => $old->payload, 'status' => 'sending', 'attempts' => 1]);
        DeliverWebhook::attempt($d, $e);

        return response()->json(['delivery' => self::presentDelivery($d->refresh(), true)]);
    }

    /** @return array<string, mixed> */
    public static function present(WebhookEndpoint $e): array
    {
        return ['id' => $e->id, 'url' => $e->url, 'description' => $e->description, 'events' => $e->events, 'enabled' => $e->enabled, 'disabled_reason' => $e->disabled_reason,
            'secret_hint' => 'whsec_••••'.substr($e->secret, -4), 'failure_streak' => $e->failure_streak,
            'last_success_at' => $e->last_success_at?->toIso8601ZuluString(), 'last_failure_at' => $e->last_failure_at?->toIso8601ZuluString(), 'created_at' => $e->created_at?->toIso8601ZuluString()];
    }

    /** @return array<string, mixed> */
    public static function presentDelivery(WebhookDelivery $d, bool $full = false): array
    {
        return ['id' => $d->id, 'endpoint_id' => $d->endpoint_id, 'event_type' => $d->event_type, 'status' => $d->status, 'attempts' => $d->attempts, 'response_code' => $d->response_code,
            'error' => $d->error, 'duration_ms' => $d->duration_ms, 'next_attempt_at' => $d->next_attempt_at?->toIso8601ZuluString(), 'created_at' => $d->created_at?->toIso8601ZuluString(),
            'tracking_code' => $d->payload['data']['tracking_code'] ?? null]
            + ($full ? ['payload' => $d->payload, 'response_body' => $d->response_body] : []);
    }

    /** @return array<string, mixed> */
    public static function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';
        $data = $request->validate([
            'url' => [$req, 'string', 'max:1000'], 'description' => ['nullable', 'string', 'max:120'],
            'events' => [$req, 'array', 'min:1'], 'events.*' => ['string', 'in:*,'.implode(',', array_keys(Webhooks::EVENTS))],
            'enabled' => ['sometimes', 'boolean'],
        ]);
        if (isset($data['url']) && ($p = UrlGuard::problem($data['url']))) {
            throw new ApiException('validation_failed', $p, 422, ['url' => $p]);
        }
        if (isset($data['events'])) {
            $data['events'] = in_array('*', $data['events'], true) ? ['*'] : array_values(array_unique($data['events']));
        }

        return $data;
    }

    private function endpoint(Workspace $workspace, string $id): WebhookEndpoint
    {
        return WebhookEndpoint::where('workspace_id', $workspace->id)->find($id) ?? throw new ApiException('not_found', 'Not found.', 404);
    }

    private function findDelivery(Workspace $workspace, string $id): WebhookDelivery
    {
        return WebhookDelivery::where('workspace_id', $workspace->id)->find($id) ?? throw new ApiException('not_found', 'Not found.', 404);
    }
}
