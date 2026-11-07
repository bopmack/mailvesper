<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Mail\SystemTestMail;
use App\Models\User;
use App\Services\Ai\AiClient;
use App\Services\Audit;
use App\Services\Ops\Alerts;
use App\Services\Ops\Backups;
use App\Services\Ops\Health;
use App\Services\Ops\Offsite;
use App\Services\Ops\State;
use App\Services\Platform\Brand;
use App\Services\Platform\Runtime;
use App\Services\Platform\Settings;
use App\Support\Edition;
use App\Support\Installation;
use App\Support\WebCron;
use App\Support\WebInstaller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The installation admin's panel: settings, AI, system status, backups, alerts. The panel itself is MailVesper Pro;
 * Community's admin sets only what its features need (system email, AI, sending, setup checks, complaint mailbox)
 * in Settings → Installation.
 */
class PlatformController extends Controller
{
    /** The settings groups Community's features need, which its admin edits in Settings → Installation. */
    public const COMMUNITY_GROUPS = ['System email', 'AI', 'Sending', 'Setup checks', 'Complaint feedback loops'];

    public function settings(Request $request): JsonResponse
    {
        $this->admin($request);

        return response()->json(['groups' => self::catalog()]);
    }

    /** @return array<string, list<array<string, mixed>>> the settings this edition's admin may see and change */
    private static function catalog(): array
    {
        $all = Settings::catalog();

        if (app(Edition::class)->isPro()) {
            return $all;
        }
        $mine = array_intersect_key($all, array_flip(self::COMMUNITY_GROUPS));
        $mine['AI'] = array_values(array_filter($mine['AI'] ?? [], fn ($f) => $f['key'] !== 'ai.chat_per_hour')); // Copilot chat is Pro

        return $mine;
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $user = $this->admin($request);
        $d = $request->validate(['settings' => ['required', 'array']]);
        $keys = Settings::keys();
        $shown = collect(self::catalog())->flatten(1)->pluck('key')->all(); // the license and admins have their own flows
        $changed = [];
        foreach ($d['settings'] as $k => $v) {
            if (! in_array($k, $shown, true)) {
                continue;
            }
            // Masked secrets stay as they were.
            if ($keys[$k][1] && is_string($v) && str_starts_with($v, '••••')) {
                continue;
            }
            if ($keys[$k][2] === 'int' && $v !== null && $v !== '' && (! is_numeric($v) || (int) $v < 0)) {
                throw new ApiException('validation_failed', 'Use a whole number.', 422, ["settings.$k" => 'Use a whole number.']);
            }
            if (is_string($v) && $v !== '' && ($why = self::invalid($k, trim($v))) !== null) {
                throw new ApiException('validation_failed', $why, 422, ["settings.$k" => $why]);
            }
            Settings::set($k, $v === '' ? null : $v, $user->id);
            $changed[] = $k;
        }
        Audit::log('platform.settings_changed', $user, null, ['keys' => $changed]);

        return response()->json(['groups' => self::catalog(), 'changed' => $changed]);
    }

    public function testAi(Request $request, AiClient $ai): JsonResponse
    {
        $this->admin($request);
        $t = microtime(true);
        try {
            $r = $ai->chat('ping', [['role' => 'user', 'content' => 'Reply with the single word: ready']], ['max_tokens' => 10]);

            return response()->json(['ok' => true, 'answer' => trim($r['text']), 'model' => $r['model'], 'ms' => (int) ((microtime(true) - $t) * 1000)]);
        } catch (ApiException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    public function system(Request $request): JsonResponse
    {
        $this->proAdmin($request);

        return response()->json(['health' => Health::snapshot(),
            'errors' => DB::table('ops_errors')->whereNull('resolved_at')->orderByDesc('last_seen_at')->limit(30)->get(),
            'slow_queries' => DB::table('ops_slow_queries')->orderByDesc('id')->limit(15)->get(),
            'backups' => DB::table('ops_backups')->orderByDesc('id')->limit(10)->get(['id', 'file', 'size', 'status', 'error', 'drill_status', 'drill_at', 'drill_detail', 'offsite_status', 'offsite_error', 'offsite_at', 'started_at', 'finished_at']),
            'alerts' => ['recipients' => Alerts::recipients(), 'firing' => DB::table('ops_state')->where('key', 'like', 'alert:%')->pluck('value', 'key')]]);
    }

    public function resolveError(Request $request, int $id): JsonResponse
    {
        $this->proAdmin($request);
        DB::table('ops_errors')->where('id', $id)->update(['resolved_at' => now()]);

        return response()->json(['resolved' => true]);
    }

    public function backup(Request $request, Backups $backups): JsonResponse
    {
        $user = $this->proAdmin($request);
        $r = $request->input('drill') ? $backups->drill() : $backups->run();
        Audit::log($request->input('drill') ? 'platform.backup_drill' : 'platform.backup', $user, null, ['status' => $r['drill_status'] ?? $r['status'] ?? null]);

        return response()->json(['backup' => $r]);
    }

    public function testOffsite(Request $request): JsonResponse
    {
        $this->proAdmin($request);

        return response()->json(Offsite::test());
    }

    public function testAlert(Request $request): JsonResponse
    {
        $this->proAdmin($request);
        $sent = Alerts::send('['.Brand::name().'] Test alert', ['This is a test from Platform → System. If you can read it, alerts reach you.']);

        return response()->json(['sent' => $sent, 'recipients' => Alerts::recipients()]);
    }

    /** Why a value can't be saved, for settings with a shape. */
    private static function invalid(string $key, string $v): ?string
    {
        if (isset(Settings::OPTIONS[$key]) && ! in_array($v, Settings::OPTIONS[$key], true)) {
            return 'Choose one of: '.implode(', ', Settings::OPTIONS[$key]).'.';
        }

        return match ($key) {
            'brand.accent' => preg_match('/^#[0-9A-Fa-f]{6}$/', $v) ? null : 'Use a colour like #5B5BF7.',
            'brand.name' => preg_match("/^[\\p{L}\\p{N} .,&'()!+:_-]{1,60}$/u", $v) ? null : 'Up to 60 letters, digits, spaces and . , & \' ( ) ! + : _ -',
            'mail.from_address', 'site.support_email' => filter_var($v, FILTER_VALIDATE_EMAIL) ? null : 'Use an email address.',
            'mail.port' => (int) $v >= 1 && (int) $v <= 65535 ? null : 'Use a port between 1 and 65535.',
            'mail.host' => preg_match('/^[A-Za-z0-9.-]{1,253}$/', $v) ? null : 'Use a host name like smtp.example.com.',
            'site.privacy_url', 'site.terms_url' => preg_match('#^https?://\S+$#i', $v) ? null : 'Use a full https:// address.',
            'oauth.apple_private_key' => str_contains($v, 'BEGIN PRIVATE KEY') ? null : 'Paste the whole .p8 key, including the BEGIN and END lines.',
            default => null,
        };
    }

    /** Uploads the logo, dark logo or icon (Branding). */
    public function uploadBrand(Request $request, string $kind): JsonResponse
    {
        $user = $this->proAdmin($request);
        $request->validate(['file' => ['required', 'file']]);
        $url = Brand::upload($kind, $request->file('file'), $user->id);
        Audit::log('platform.brand_changed', $user, null, ['image' => $kind]);

        return response()->json(['url' => $url, 'brand' => Brand::public()]);
    }

    public function removeBrand(Request $request, string $kind): JsonResponse
    {
        $user = $this->proAdmin($request);
        Brand::remove($kind, $user->id);
        Audit::log('platform.brand_changed', $user, null, ['image' => $kind, 'removed' => true]);

        return response()->json(['brand' => Brand::public()]);
    }

    /** Sends a test through the system email settings (as saved), and says exactly what the server answered. */
    public function testMail(Request $request): JsonResponse
    {
        $user = $this->admin($request);
        $d = $request->validate(['to' => ['required', 'email', 'max:254']]);
        Runtime::apply(); // the settings as just saved
        try {
            Mail::mailer()->to($d['to'])->send(new SystemTestMail);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 400)]);
        }
        Audit::log('platform.mail_tested', $user, null, ['to' => $d['to']]);

        return response()->json(['ok' => true, 'mailer' => (string) config('mail.default'), 'from' => (string) config('mail.from.address')]);
    }

    /** The platform admins, by email. */
    public function admins(Request $request): JsonResponse
    {
        $this->proAdmin($request);

        return response()->json(['admins' => User::whereIn('id', Installation::adminIds())->orderBy('created_at')->get(['id', 'name', 'email'])]);
    }

    /** Names the platform admins by email. You can't remove yourself (so nobody locks the platform out). */
    public function saveAdmins(Request $request): JsonResponse
    {
        $user = $this->proAdmin($request);
        $d = $request->validate(['emails' => ['required', 'array', 'min:1', 'max:20'], 'emails.*' => ['email', 'max:254']]);
        $emails = array_values(array_unique(array_map(fn ($e) => mb_strtolower(trim((string) $e)), $d['emails'])));
        $users = User::whereIn(DB::raw('LOWER(email)'), $emails)->get(['id', 'email']);
        $missing = array_values(array_diff($emails, $users->pluck('email')->map(fn ($e) => mb_strtolower((string) $e))->all()));
        if ($missing) {
            throw new ApiException('validation_failed', 'No account uses '.implode(', ', $missing).'. They need to sign up first.', 422, ['emails' => 'Unknown: '.implode(', ', $missing)]);
        }
        if (! $users->contains('id', $user->id)) {
            throw new ApiException('validation_failed', 'Keep yourself on the list: ask another admin to remove you.', 422, ['emails' => 'You can’t remove yourself.']);
        }
        Settings::set('platform.admins', $users->pluck('id')->implode(','), $user->id);
        Audit::log('platform.admins_changed', $user, null, ['admins' => $users->pluck('email')->all()]);

        return $this->admins($request);
    }

    /** The parts of the panel that are MailVesper Pro (Community shows them as a locked page). */
    private function proAdmin(Request $request): User
    {
        $u = $this->admin($request);
        if (! app(Edition::class)->isPro()) {
            throw new ApiException('pro_required', 'The platform admin dashboard is part of MailVesper Pro.', 403);
        }

        return $u;
    }

    /** The cron job, for the installation admin: is it running, and the command to set it up. */
    public function cron(Request $request): JsonResponse
    {
        $this->admin($request);
        $beat = State::get('heartbeat');

        return response()->json(['running' => WebCron::cronIsRunning(), 'last_run' => is_string($beat) ? $beat : null,
            'command' => WebInstaller::cronCommand(), 'line' => WebInstaller::cronLine()]);
    }

    /** Public, for the installer's last step: has the cron job run yet? Says nothing else. */
    public function cronStatus(): JsonResponse
    {
        return response()->json(['running' => WebCron::cronIsRunning()]);
    }

    private function admin(Request $request): User
    {
        /** @var User $u */
        $u = $request->user();
        if (! Installation::isAdmin($u)) {
            throw new ApiException('forbidden', 'Only the platform admin can do this.', 403);
        }

        return $u;
    }
}
