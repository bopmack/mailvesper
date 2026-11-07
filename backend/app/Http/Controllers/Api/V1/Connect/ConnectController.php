<?php

namespace App\Http\Controllers\Api\V1\Connect;

use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\Contacts\SegmentController;
use App\Http\Controllers\Concerns\WorkspaceAccess;
use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\Segment;
use App\Models\SiteConnection;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Connect\Presets;
use App\Services\Connect\SiteAudience;
use App\Services\Connect\SiteConnect;
use App\Services\Platform\Brand;
use App\Support\Edition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Connect to MailVesper" for websites. Public: what this installation is (GET /v1/meta) and the code exchange.
 * Signed in: the request check, a workspace's readiness, approving, the Connected sites list. With the site's
 * own key: its status (can it send from its address?) and disconnecting.
 */
class ConnectController extends Controller
{
    use WorkspaceAccess;

    public function __construct(private SiteConnect $connect) {}

    /** Tells a site this address is a MailVesper installation, and where to connect and call the API. */
    public function meta(): JsonResponse
    {
        $api = rtrim((string) config('app.url'), '/').'/v1';

        return response()->json(['product' => 'sendline', 'name' => config('app.name'), 'version' => (string) config('app.version'),
            'edition' => app(Edition::class)->isPro() ? 'pro' : 'community', 'api_base' => $api,
            'connect_url' => rtrim((string) config('app.frontend_url'), '/').'/app/#/connect', 'token_url' => $api.'/connect/token',
            'features' => ['connect' => 1, 'contacts_batch' => 1, 'events_batch' => 1, 'webhooks' => 1]]);
    }

    /** The connect page checks the site's return address before showing anything; also gives the Cancel address. */
    public function check(Request $request): JsonResponse
    {
        $d = $request->validate(['redirect_uri' => ['required', 'string', 'max:500'], 'state' => ['nullable', 'string', 'max:200']]);
        [$uri, $site] = SiteConnect::redirect($d['redirect_uri']);

        return response()->json(['site_url' => $site, 'host' => parse_url($site, PHP_URL_HOST),
            'cancel_url' => SiteConnect::back($uri, array_filter(['error' => 'access_denied', 'state' => $d['state'] ?? null]))]);
    }

    public function readiness(Workspace $workspace): JsonResponse
    {
        return response()->json(['readiness' => SiteConnect::readiness($workspace)]);
    }

    public function approve(Request $request, Workspace $workspace): JsonResponse
    {
        $this->requireManager($request, 'connect sites');
        $d = $request->validate(['redirect_uri' => ['required', 'string', 'max:500'], 'state' => ['required', 'string', 'max:200'], 'code_challenge' => ['required', 'string', 'max:128'],
            'code_challenge_method' => ['required', 'in:S256'], 'name' => ['nullable', 'string', 'max:120']]);
        $url = $this->connect->approve($workspace, $this->currentUser($request), $d['redirect_uri'], $d['state'], $d['code_challenge'], (string) ($d['name'] ?? ''));

        return response()->json(['redirect' => $url]);
    }

    /** Public: the site exchanges its one-time code and verifier for its key. */
    public function token(Request $request): JsonResponse
    {
        $d = $request->validate(['code' => ['required', 'string', 'max:100'], 'code_verifier' => ['required', 'string', 'max:200'], 'redirect_uri' => ['required', 'string', 'max:500']]);

        return response()->json($this->connect->exchange($d['code'], $d['code_verifier'], $d['redirect_uri']) + ['api_base' => rtrim((string) config('app.url'), '/').'/v1']);
    }

    /** Settings → Connected sites. */
    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        $this->requireManager($request, 'see connected sites');
        $rows = SiteConnection::where('workspace_id', $workspace->id)->whereNull('revoked_at')->whereNotNull('connected_at')->orderByDesc('connected_at')->get();
        $keys = ApiKey::whereIn('id', $rows->pluck('api_key_id')->filter())->get()->keyBy('id');
        $users = User::whereIn('id', $rows->pluck('created_by')->filter())->pluck('email', 'id');

        return response()->json(['sites' => $rows->map(fn (SiteConnection $c) => ['id' => $c->id, 'name' => $c->name, 'site_url' => $c->site_url, 'connected_at' => $c->connected_at?->toIso8601ZuluString(),
            'connected_by' => $users[$c->created_by] ?? null, 'last_used_at' => ($keys[$c->api_key_id] ?? null)?->last_used_at?->toIso8601ZuluString(),
            'key_prefix' => ($keys[$c->api_key_id] ?? null)?->prefix, 'live' => SiteAudience::live($c), 'audience_ok_at' => $c->audience_ok_at?->toIso8601ZuluString(),
            'audience_error' => $c->audience_error, 'fields' => $c->fields ?? []])->values()]);
    }

    /**
     * A connected site's people, read live (never stored): searched by email or name, optionally inside one of the
     * site's segments, 50 a page.
     */
    public function people(Request $request, Workspace $workspace, string $id): JsonResponse
    {
        $c = SegmentController::site($workspace, $id);
        $rules = null;
        if ($request->filled('segment')) {
            $seg = Segment::where('workspace_id', $workspace->id)->where('site_connection_id', $c->id)->find((string) $request->query('segment'))
                ?? throw new ApiException('not_found', 'That segment isn’t one of this site’s.', 404);
            $rules = $seg->rules;
        }
        $page = max(1, (int) $request->query('page', '1'));
        $r = SiteAudience::page($c, $rules, trim((string) $request->query('q', '')), $page, 50, $request->boolean('subscribed'));

        return response()->json(['site' => ['id' => $c->id, 'name' => $c->name], 'people' => $r['people'],
            'meta' => ['page' => $page, 'per_page' => 50, 'total' => $r['total'], 'has_more' => $page * 50 < $r['total']]]);
    }

    public function destroy(Request $request, Workspace $workspace, string $id): JsonResponse
    {
        $this->requireManager($request, 'disconnect sites');
        $c = SiteConnection::where('workspace_id', $workspace->id)->whereNull('revoked_at')->find($id) ?? throw new ApiException('not_found', 'Not found.', 404);
        $this->connect->revoke($c, $this->currentUser($request));

        return response()->json(['disconnected' => true]);
    }

    /** With the site's key: who it's connected to, and whether it can send from an address (?from=). */
    public function status(Request $request): JsonResponse
    {
        /** @var ApiKey $key */
        $key = $request->attributes->get('api_key');
        /** @var Workspace $ws */
        $ws = $request->attributes->get('workspace');
        $from = mb_strtolower(trim((string) $request->query('from', '')));
        $c = SiteConnect::forKey($key);

        return response()->json(['workspace' => ['id' => $ws->id, 'name' => $ws->name], 'connection' => $c ? ['id' => $c->id, 'site_url' => $c->site_url, 'connected_at' => $c->connected_at?->toIso8601ZuluString()] : null,
            'sending' => SiteConnect::readiness($ws), 'from' => $from !== '' ? ['address' => $from, 'allowed' => SiteConnect::canSendFrom($ws, $from)] : null,
            'app_url' => rtrim((string) config('app.frontend_url'), '/').'/app/']);
    }

    /** With the site's key: its contact fields and ready-made segments (the "creator_platform" preset), grouped under the site's name. */
    /** The connected site tells MailVesper where to read its users (live, read-only). */
    public function audienceHook(Request $request): JsonResponse
    {
        $d = $request->validate(['url' => ['required', 'string', 'max:500']]);

        return response()->json(['secret' => SiteConnect::setAudienceHook($this->siteFor($request), $d['url'])]);
    }

    public function preset(Request $request, Presets $presets): JsonResponse
    {
        $d = $request->validate(['preset' => ['required', 'string', 'in:creator_platform'], 'group' => ['nullable', 'string', 'max:120'],
            'types' => ['nullable', 'array', 'max:60'], 'types.*' => ['string', 'in:'.implode(',', SiteAudience::TYPES)]]);
        $c = $this->siteFor($request);
        /** @var Workspace $ws */
        $ws = $request->attributes->get('workspace');

        return response()->json($presets->apply($ws, $d['preset'], (string) ($d['group'] ?? '') ?: $c->name, $c, (array) ($d['types'] ?? [])));
    }

    /** The connection a site's key belongs to. */
    private function siteFor(Request $request): SiteConnection
    {
        /** @var ApiKey $key */
        $key = $request->attributes->get('api_key');

        return SiteConnect::forKey($key) ?? throw new ApiException('not_connected', 'This key wasn’t made by “Connect to '.Brand::name().'”. Connect the site from its admin.', 422);
    }

    /** With the site's key: the site disconnects itself (its key stops working). */
    public function disconnect(Request $request): JsonResponse
    {
        /** @var ApiKey $key */
        $key = $request->attributes->get('api_key');
        $c = SiteConnect::forKey($key);
        if ($c) {
            $this->connect->revoke($c, null);
        } else {
            $key->forceFill(['revoked_at' => now()])->save();
        }

        return response()->json(['disconnected' => true]);
    }
}
