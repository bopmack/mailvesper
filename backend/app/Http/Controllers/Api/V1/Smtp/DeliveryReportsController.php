<?php

namespace App\Http\Controllers\Api\V1\Smtp;

use App\Http\Controllers\Concerns\WorkspaceAccess;
use App\Http\Controllers\Controller;
use App\Models\DeliveryHook;
use App\Models\Mailbox;
use App\Models\SmtpConnection;
use App\Models\Workspace;
use App\Services\Audit;
use App\Services\Mailbox\PlatformMailbox;
use App\Services\Platform\Settings;
use App\Services\Sending\Senders;
use App\Services\Smtp\RelayEvents;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Sending → Delivery reports: what SMTP sending can't tell by itself. The relay provider's event webhook (deliveries,
 * bounces, complaints), complaint feedback loops (the installation's complaint address), the mailbox (late bounces),
 * and the complaints received so far, by who reported them.
 */
class DeliveryReportsController extends Controller
{
    use WorkspaceAccess;

    public function show(Request $request, Workspace $workspace): JsonResponse
    {
        $hook = DeliveryHook::where('workspace_id', $workspace->id)->first();
        $smtp = SmtpConnection::where('workspace_id', $workspace->id)->first();
        $box = Mailbox::where('workspace_id', $workspace->id)->first();
        $manager = in_array($this->role($request)->value, ['owner', 'admin'], true);
        $by = [];
        foreach (DB::table('message_events')->where('workspace_id', $workspace->id)->where('type', 'complained')
            ->where('occurred_at', '>', now()->subDays(30)->toDateTimeString())->limit(5000)->pluck('data') as $data) {
            $d = (array) json_decode((string) $data, true);
            $who = (string) ($d['via'] ?? (isset($d['reported_by']) ? 'Feedback loop · '.$d['reported_by'] : 'Amazon SES'));
            $by[$who] = ($by[$who] ?? 0) + 1;
        }
        arsort($by);

        return response()->json([
            'method' => Senders::method($workspace->id),
            'smtp_host' => $smtp?->host,
            'provider_guess' => RelayEvents::guess($smtp?->host),
            'providers' => collect(RelayEvents::PROVIDERS)->map(fn ($name, $key) => ['key' => $key, 'name' => $name, 'signed' => in_array($key, RelayEvents::SIGNED, true)])->values(),
            'hook' => $hook ? [
                'provider' => $hook->provider, 'url' => $manager ? $hook->url() : null, 'signed' => (bool) $hook->signing_key,
                'events' => $hook->events, 'last_event_at' => $hook->last_event_at?->toIso8601ZuluString(), 'last_error' => $hook->last_error,
            ] : null,
            'fbl' => ['address' => PlatformMailbox::configured('fbl') ? Settings::get('fbl.address') : null],
            'mailbox' => $box ? ['enabled' => $box->enabled, 'read_bounces' => $box->read_bounces, 'bounces' => $box->bounces, 'complaints' => $box->complaints] : null,
            'complaints' => ['days' => 30, 'total' => array_sum($by), 'by' => collect($by)->map(fn ($n, $who) => ['source' => $who, 'count' => $n])->values()],
        ]);
    }

    /** Turns the provider webhook on, or changes its provider or signing key. The URL stays the same. */
    public function save(Request $request, Workspace $workspace): JsonResponse
    {
        $this->requireManager($request, 'set up delivery reports');
        $d = $request->validate([
            'provider' => ['required', Rule::in(array_keys(RelayEvents::PROVIDERS))],
            'signing_key' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
        $hook = DeliveryHook::where('workspace_id', $workspace->id)->first();
        if (! $hook) {
            $token = Str::random(48);
            $hook = new DeliveryHook(['workspace_id' => $workspace->id, 'token' => $token, 'token_hash' => DeliveryHook::hashToken($token)]);
        }
        $changed = $hook->provider !== $d['provider'];
        $hook->provider = $d['provider'];
        if (array_key_exists('signing_key', $d)) {
            $hook->signing_key = trim((string) $d['signing_key']) ?: null;
        } elseif ($changed) {
            $hook->signing_key = null; // one provider's key never verifies another's
        }
        if (! in_array($hook->provider, RelayEvents::SIGNED, true)) {
            $hook->signing_key = null;
        }
        $hook->last_error = null;
        $hook->save();
        Audit::log('delivery_reports.saved', $this->currentUser($request), $workspace->id, ['provider' => $hook->provider, 'signed' => (bool) $hook->signing_key]);

        return $this->show($request, $workspace);
    }

    /** A new secret URL: the old one stops working at once (paste the new one at the provider). */
    public function rotate(Request $request, Workspace $workspace): JsonResponse
    {
        $this->requireManager($request, 'change the webhook address');
        $hook = DeliveryHook::where('workspace_id', $workspace->id)->firstOrFail();
        $token = Str::random(48);
        $hook->forceFill(['token' => $token, 'token_hash' => DeliveryHook::hashToken($token)])->save();
        Audit::log('delivery_reports.rotated', $this->currentUser($request), $workspace->id);

        return $this->show($request, $workspace);
    }

    public function destroy(Request $request, Workspace $workspace): JsonResponse
    {
        $this->requireManager($request, 'turn off delivery reports');
        DeliveryHook::where('workspace_id', $workspace->id)->delete();
        Audit::log('delivery_reports.removed', $this->currentUser($request), $workspace->id);

        return $this->show($request, $workspace);
    }
}
