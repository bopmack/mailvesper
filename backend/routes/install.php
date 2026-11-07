<?php

use App\Http\Controllers\InstallController;
use Illuminate\Support\Facades\Route;

// The browser installer (see InstallController). 404 once the installation has its admin. No rate limit: the
// cache may not exist yet, and every step after the first needs a random one-time token.
Route::prefix('v1/install')->group(function () {
    Route::get('/', [InstallController::class, 'status']);
    Route::post('database', [InstallController::class, 'database']);
    Route::post('settings', [InstallController::class, 'settings']);
    Route::post('tables', [InstallController::class, 'tables']);
    Route::post('admin', [InstallController::class, 'admin']);
});
