<?php

use App\Http\Controllers\Api\V1\Auth\OAuthController;
use Illuminate\Support\Facades\Route;

// The API root.
Route::get('/', fn () => response()->json(['name' => 'MailVesper API', 'version' => config('app.version'), 'docs' => '/api/v1/health']));

// Social sign-in: full-page browser redirects, so they live in the web (session) group.
Route::get('v1/auth/oauth/{provider}/redirect', [OAuthController::class, 'redirect'])->middleware('throttle:auth');
Route::match(['get', 'post'], 'v1/auth/oauth/{provider}/callback', [OAuthController::class, 'callback'])->middleware('throttle:auth');
