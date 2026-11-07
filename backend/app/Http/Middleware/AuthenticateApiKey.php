<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\ApiKey;
use App\Models\Workspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Public API: "Authorization: Bearer sl_live_…". Sets the key and its workspace on the request. */
class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next, string $scope = 'send'): Response
    {
        $token = (string) $request->bearerToken();
        if (! preg_match('/^sl_live_[A-Za-z0-9]{40}$/', $token)) {
            throw new ApiException('unauthenticated', 'Send your API key as "Authorization: Bearer sl_live_…".', 401);
        }
        $key = ApiKey::where('key_hash', ApiKey::hash($token))->whereNull('revoked_at')->first();
        $workspace = $key ? Workspace::find($key->workspace_id) : null;
        if (! $key || ! $workspace) {
            throw new ApiException('unauthenticated', 'This API key isn’t valid or was revoked.', 401);
        }
        if ($scope === 'full' && $key->scope !== 'full') {
            throw new ApiException('forbidden', 'This API key can only send email.', 403);
        }
        if (! $key->last_used_at || $key->last_used_at->lt(now()->subMinute())) {
            $key->forceFill(['last_used_at' => now(), 'last_used_ip' => $request->ip()])->saveQuietly();
        }
        $request->attributes->set('api_key', $key);
        $request->attributes->set('workspace', $workspace);

        return $next($request);
    }
}
