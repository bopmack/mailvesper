<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Ops\Health;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    /** ?deep=1 also checks the scheduler, the queue and disk space (for an external uptime monitor). */
    public function __invoke(Request $request): JsonResponse
    {
        if ($request->boolean('deep')) {
            $h = Health::snapshot();

            return response()->json(['status' => ($h['ok'] ?? false) ? 'ok' : 'degraded', 'database' => ($h['database']['ok'] ?? false) ? 'ok' : 'error',
                'scheduler' => ($h['scheduler']['ok'] ?? false) ? 'ok' : 'stale', 'queue' => ($h['queue']['ok'] ?? false) ? 'ok' : 'backlog', 'disk' => ($h['disk']['ok'] ?? false) ? 'ok' : 'low',
                'release' => basename(base_path()), 'time' => now()->toIso8601String()], ($h['ok'] ?? false) ? 200 : 503);
        }
        try {
            DB::select('select 1');
            $db = 'ok';
        } catch (Throwable) {
            $db = 'error';
        }

        return response()->json([
            'status' => $db === 'ok' ? 'ok' : 'degraded',
            'database' => $db,
            'version' => config('app.version'),
            'release' => basename(base_path()),
            'time' => now()->toIso8601String(),
        ], $db === 'ok' ? 200 : 503);
    }
}
