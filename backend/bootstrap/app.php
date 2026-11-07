<?php

use App\Exceptions\ApiException;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\EnsureNotSuspended;
use App\Http\Middleware\RunBackgroundTasks;
use App\Services\Ops\ErrorLog;
use App\Support\ApiErrorRenderer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        apiPrefix: '', // the API is served at <your address>/api (the web root's api link), so routes are /v1/...
        // The browser installer: outside the api/web groups, so it needs no session, cookies or database.
        then: fn () => Route::group([], __DIR__.'/../routes/install.php'),
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi(); // Sanctum cookie sessions for the MailVesper front end
        $middleware->prepend(AssignRequestId::class);
        $middleware->append(RunBackgroundTasks::class); // no cron job: background tasks after responses
        $middleware->alias(['api.key' => AuthenticateApiKey::class]);
        $middleware->redirectGuestsTo(fn () => null); // an API: signed-out requests get a JSON 401, never a redirect to a login route
        $middleware->appendToGroup('api', EnsureNotSuspended::class); // suspended accounts are signed out
        // Apple posts the OAuth callback cross-site; it is protected by our one-time state instead.
        $middleware->validateCsrfTokens(except: ['v1/auth/oauth/*/callback']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport(ApiException::class); // expected, user-facing errors are not incidents
        $exceptions->report(fn (Throwable $e) => ErrorLog::capture($e)); // grouped in Platform → System (also logged as usual)
        $exceptions->shouldRenderJsonWhen(fn () => true);
        $exceptions->render(fn (Throwable $e) => ApiErrorRenderer::render($e));
    })->create();
