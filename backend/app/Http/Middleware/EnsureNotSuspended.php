<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** A suspended account is signed out on its next request. */
class EnsureNotSuspended
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $u */
        $u = Auth::guard('web')->user();
        if ($u && $u->suspended_at) {
            Auth::guard('web')->logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
            }
            throw new ApiException('account_suspended', 'This account is suspended'.($u->suspended_reason ? ': '.$u->suspended_reason : '.').' Contact the platform’s support.', 403);
        }

        return $next($request);
    }
}
