<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * For endpoints any website may call from the browser (signup forms): allows every origin, never with cookies,
 * on every answer including errors, so the embed script can show the real message.
 */
class PublicCors
{
    public function handle(Request $request, Closure $next): Response
    {
        $r = $request->isMethod('OPTIONS') ? response('', 204)->header('Access-Control-Max-Age', '86400') : $next($request);
        $r->headers->set('Access-Control-Allow-Origin', '*');
        $r->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
        $r->headers->set('Access-Control-Allow-Headers', 'Content-Type, Accept');

        return $r;
    }
}
