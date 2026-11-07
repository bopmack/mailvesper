<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\Platform\Brand;
use App\Support\Edition;
use App\Support\Installation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** Single place where a user becomes logged in, so every login method behaves identically. */
class AuthSession
{
    /** @return array<string, mixed> */
    public function start(Request $request, User $user, bool $remember, string $method): array
    {
        if ($user->suspended_at) {
            throw new ApiException('account_suspended', 'This account is suspended'.($user->suspended_reason ? ': '.$user->suspended_reason : '.').' Contact the platform’s support.', 403);
        }
        if (! $request->hasSession()) {
            throw new ApiException('stateless_request', 'Sign-in must come from the '.Brand::name().' website.', 400);
        }

        if ($user->hasTwoFactor() && ! app(TwoFactor::class)->isTrusted($user, $request)) {
            $request->session()->put('auth.mfa', ['user_id' => $user->id, 'remember' => $remember, 'method' => $method, 'expires' => now()->addMinutes(10)->timestamp]);

            return ['mfa_required' => true];
        }

        Auth::guard('web')->login($user, $remember);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();
        Audit::log('auth.login', $user, meta: ['method' => $method]);

        return self::payload($user);
    }

    /**
     * Finish a sign-in that was waiting for the second step.
     *
     * @return array<string, mixed>
     */
    public function complete(Request $request, User $user, bool $remember, string $method): array
    {
        $request->session()->forget('auth.mfa');
        Auth::guard('web')->login($user, $remember);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();
        Audit::log('auth.login', $user, meta: ['method' => $method, 'two_factor' => true]);

        return self::payload($user);
    }

    /** @return array<string, mixed> */
    public static function payload(User $user): array
    {
        $workspaces = DB::table('workspace_user')
            ->join('workspaces', 'workspaces.id', '=', 'workspace_user.workspace_id')
            ->where('workspace_user.user_id', $user->id)
            ->orderBy('workspaces.created_at')
            ->get(['workspaces.id', 'workspaces.name', 'workspaces.slug', 'workspace_user.role'])
            ->map(fn (object $w) => ['id' => $w->id, 'name' => $w->name, 'slug' => $w->slug, 'role' => $w->role])
            ->values();

        $paused = $user->current_workspace_id ? DB::table('workspaces')->where('id', $user->current_workspace_id)->first(['suspended_at', 'suspended_reason']) : null;
        $me = [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified' => $user->hasVerifiedEmail(),
                'has_password' => $user->password !== null,
                'two_factor' => $user->hasTwoFactor(),
                'recovery_codes_left' => $user->hasTwoFactor() ? count($user->two_factor_recovery_codes ?? []) : null,
                'timezone' => $user->timezone ?? 'UTC',
            ],
            'workspaces' => $workspaces,
            'current_workspace_id' => $user->current_workspace_id,
            'invitations' => [], // Pro (teams) fills this in
            'edition' => app(Edition::class)->toArray(),
            'platform_admin' => Installation::isAdmin($user),
            // A workspace whose sending the platform paused (abuse, unpaid…): the app shows why.
            'workspace_suspended' => $paused && $paused->suspended_at ? ($paused->suspended_reason ?: 'Paused by the platform.') : null,
        ];
        $me = app(Edition::class)->me($user, $me);

        // What the front end should show next.
        return $me + ['next' => match (true) {
            ! $user->hasVerifiedEmail() => 'verify_email',
            $workspaces->isEmpty() && ! empty($me['invitations']) => 'accept_invite',
            $workspaces->isEmpty() => 'create_workspace',
            default => 'app',
        }];
    }
}
