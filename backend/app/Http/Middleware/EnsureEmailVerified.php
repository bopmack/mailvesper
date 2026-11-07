<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureEmailVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->hasVerifiedEmail()) {
            throw new ApiException('email_unverified', 'Verify your email address to continue.', 403);
        }

        return $next($request);
    }
}
