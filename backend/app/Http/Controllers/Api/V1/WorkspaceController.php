<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CodePurpose;
use App\Enums\WorkspaceRole;
use App\Exceptions\ApiException;
use App\Http\Controllers\Concerns\WorkspaceAccess;
use App\Http\Controllers\Controller;
use App\Jobs\PurgeWorkspace;
use App\Mail\CodeMail;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Audit;
use App\Services\AuthSession;
use App\Services\EmailCodeService;
use App\Services\SafeMail;
use App\Support\Edition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class WorkspaceController extends Controller
{
    use WorkspaceAccess;

    public const SLUG_PATTERN = '/^[a-z0-9](?:[a-z0-9-]{1,38})[a-z0-9]$/';

    public const RESERVED = ['api', 'app', 'admin', 'login', 'signup', 'auth', 'www', 'mail', 'help', 'docs', 'status', 'billing', 'settings', 'support', 'sendline', 'assets', 'static', 'cdn', 'blog', 'legal', 'security', 'root', 'system'];

    public const MAX_OWNED = 10;

    public function slugCheck(Request $request): JsonResponse
    {
        $slug = mb_strtolower(trim((string) $request->query('slug', '')));

        return response()->json(['slug' => $slug] + self::slugStatus($slug));
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'slug' => ['required', 'string'],
            'send_source' => ['nullable', 'string', Rule::in(['ses', 'smtp', 'migrate', 'explore'])],
        ]);
        $slug = mb_strtolower(trim($data['slug']));
        $status = self::slugStatus($slug);
        if (! $status['available']) {
            throw new ApiException('slug_unavailable', $status['reason'], 422, ['slug' => $status['reason']]);
        }
        if (Workspace::where('owner_id', $user->id)->count() >= self::MAX_OWNED) {
            throw new ApiException('workspace_limit', 'You can own up to '.self::MAX_OWNED.' workspaces.', 422);
        }

        $workspace = DB::transaction(function () use ($user, $data, $slug) {
            $workspace = Workspace::create([
                'name' => trim($data['name']),
                'slug' => $slug,
                'owner_id' => $user->id,
                'send_source' => $data['send_source'] ?? null,
                'trial_ends_at' => now()->addDays(14),
            ]);
            $workspace->members()->attach($user->id, ['role' => WorkspaceRole::Owner->value]);
            $user->forceFill(['current_workspace_id' => $workspace->id])->save();

            return $workspace;
        });
        Audit::log('workspace.created', $user, $workspace->id, ['slug' => $slug]);
        app(Edition::class)->workspaceCreated($workspace, $user); // Pro SaaS mode: starts the trial

        return response()->json(['workspace' => ['id' => $workspace->id, 'name' => $workspace->name, 'slug' => $workspace->slug]] + AuthSession::payload($user->fresh() ?? $user), 201);
    }

    /** @return array{available: bool, reason: string|null} */
    public static function slugStatus(string $slug): array
    {
        $reason = match (true) {
            strlen($slug) < 3 || strlen($slug) > 40 => 'Use 3 to 40 characters.',
            ! preg_match(self::SLUG_PATTERN, $slug) || str_contains($slug, '--') => 'Use lowercase letters, numbers and single hyphens.',
            in_array($slug, self::RESERVED, true) => 'That URL is reserved. Try another.',
            Workspace::where('slug', $slug)->exists() => 'That URL is taken. Try another.',
            default => null,
        };

        return ['available' => $reason === null, 'reason' => $reason];
    }

    /** Settings → General: rename the workspace or change its URL. Owners and admins only. */
    public function update(Request $request, Workspace $workspace): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->requireManager($request, 'change the workspace settings');
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:80'],
            'slug' => ['sometimes', 'string'],
        ]);
        $changed = [];
        if (isset($data['name']) && trim($data['name']) !== $workspace->name) {
            $workspace->name = trim($data['name']);
            $changed[] = 'name';
        }
        if (isset($data['slug'])) {
            $slug = mb_strtolower(trim($data['slug']));
            if ($slug !== $workspace->slug) {
                $status = self::slugStatus($slug);
                if (! $status['available']) {
                    throw new ApiException('slug_unavailable', (string) $status['reason'], 422, ['slug' => (string) $status['reason']]);
                }
                $workspace->slug = $slug;
                $changed[] = 'slug';
            }
        }
        if ($changed) {
            $workspace->save();
            Audit::log('workspace.updated', $user, $workspace->id, ['fields' => $changed]);
        }

        return response()->json(['workspace' => ['id' => $workspace->id, 'name' => $workspace->name, 'slug' => $workspace->slug]] + AuthSession::payload($user));
    }

    /** Accounts without a password confirm a workspace deletion with an emailed code instead. Owner only. */
    public function deleteCode(Request $request, Workspace $workspace, EmailCodeService $codes): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->requireOwner($request);
        if ($user->password !== null) {
            throw new ApiException('use_password', 'Confirm with your password instead.', 422);
        }
        $code = $codes->issue($user->email, CodePurpose::Confirm, $request->ip());

        return response()->json(['code_delivered' => SafeMail::send($user->email, new CodeMail($code, CodePurpose::Confirm))]);
    }

    /**
     * Deletes the workspace for everyone. Access ends now (memberships, keys, sending details, webhooks, running
     * campaigns and journeys); PurgeWorkspace then removes the data in chunks. Owner only, with the workspace's name
     * typed and the owner's password (or an emailed code). Nothing in the customer's own AWS account is touched.
     */
    public function destroy(Request $request, Workspace $workspace, EmailCodeService $codes, Edition $edition): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->requireOwner($request);
        $data = $request->validate([
            'confirm_name' => ['required', 'string', 'max:80'],
            'password' => ['nullable', 'string', 'max:128'],
            'code' => ['nullable', 'string', 'max:12'],
        ]);
        if (mb_strtolower(trim($data['confirm_name'])) !== mb_strtolower(trim($workspace->name))) {
            throw new ApiException('name_mismatch', 'Type the workspace’s name exactly to confirm.', 422, ['confirm_name' => 'Type the workspace’s name exactly to confirm.']);
        }
        if ($user->password !== null) {
            if (! Hash::check((string) ($data['password'] ?? ''), $user->password)) {
                throw new ApiException('wrong_password', 'That isn’t your password.', 422, ['password' => 'That isn’t your password.']);
            }
        } else {
            $codes->verify($user->email, CodePurpose::Confirm, (string) ($data['code'] ?? ''));
        }
        $edition->workspaceDeleting($workspace, $user); // Pro: refuses while a paid plan is still billing

        $members = DB::table('workspace_user')->where('workspace_id', $workspace->id)->pluck('user_id')->all();
        DB::transaction(function () use ($workspace, $members) {
            $id = $workspace->id;
            $workspace->forceFill(['deleting_at' => now()])->save();
            DB::table('workspace_user')->where('workspace_id', $id)->delete();
            if (Schema::hasTable('invitations')) { // Pro's team invitations: links stop working now
                DB::table('invitations')->where('workspace_id', $id)->delete();
            }
            DB::table('api_keys')->where('workspace_id', $id)->delete();
            DB::table('ses_connections')->where('workspace_id', $id)->delete();
            DB::table('smtp_connections')->where('workspace_id', $id)->delete();
            DB::table('delivery_hooks')->where('workspace_id', $id)->delete(); // provider webhooks stop applying now
            DB::table('site_connections')->where('workspace_id', $id)->whereNull('revoked_at')->update(['revoked_at' => now()]); // their keys are deleted above
            DB::table('webhook_endpoints')->where('workspace_id', $id)->update(['enabled' => false, 'disabled_reason' => 'Workspace deleted']);
            DB::table('campaigns')->where('workspace_id', $id)->whereIn('status', ['scheduled', 'sending', 'paused'])->update(['status' => 'cancelled', 'finished_at' => now()]);
            DB::table('journeys')->where('workspace_id', $id)->where('status', 'active')->update(['status' => 'paused']);
            // Anyone who had it open moves to another workspace of theirs (or to "create a workspace").
            foreach (User::whereIn('id', $members)->where('current_workspace_id', $id)->get() as $u) {
                $next = DB::table('workspace_user')->where('user_id', $u->id)->orderBy('created_at')->value('workspace_id');
                $u->forceFill(['current_workspace_id' => $next])->save();
            }
        });
        Audit::log('workspace.deleted', $user, $workspace->id, ['name' => $workspace->name, 'slug' => $workspace->slug, 'members' => count($members)]);
        PurgeWorkspace::dispatch($workspace->id);

        return response()->json(['deleted' => true] + AuthSession::payload($user->fresh() ?? $user));
    }

    private function requireOwner(Request $request): void
    {
        if ($this->role($request) !== WorkspaceRole::Owner) {
            throw new ApiException('forbidden', 'Only the workspace’s owner can delete it.', 403);
        }
    }

    /** Makes this workspace the current one (EnsureWorkspaceMember already checked membership). */
    public function switch(Request $request, Workspace $workspace): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->forceFill(['current_workspace_id' => $workspace->id])->save();

        return response()->json(AuthSession::payload($user));
    }
}
