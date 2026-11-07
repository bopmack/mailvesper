<?php

namespace App\Http\Middleware;

use App\Support\WebCron;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** After the response has gone to the browser: scheduled tasks and the queue, when there's no cron job (WebCron). */
class RunBackgroundTasks
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! app()->runningUnitTests()) {
            WebCron::maybeRun();
        }
    }
}
