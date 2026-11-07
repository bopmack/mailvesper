<?php

namespace App\Http\Controllers\Api\V1\Activity;

use App\Http\Controllers\Controller;
use App\Models\SesConnection;
use App\Models\SmtpConnection;
use App\Models\Workspace;
use App\Services\Sending\Senders;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/** The sidebar usage meter: emails sent this month, and today's use of the SES daily quota. */
class UsageController extends Controller
{
    public function __invoke(Workspace $workspace): JsonResponse
    {
        $method = Senders::method($workspace);
        $c = $method === Senders::SES ? SesConnection::where('workspace_id', $workspace->id)->first() : null;
        $smtp = $method === Senders::SMTP ? SmtpConnection::where('workspace_id', $workspace->id)->first() : null;

        return response()->json(['usage' => [
            'month' => DB::table('messages')->where('workspace_id', $workspace->id)->whereNotNull('sent_at')->whereNotIn('source', ['test', 'inbox_test'])
                ->where('created_at', '>=', now()->utc()->startOfMonth()->toDateTimeString())->count(),
            'method' => $method,
            'ses' => $c ? ['sent_24h' => (int) $c->sent_last_24h, 'max_24h' => (int) $c->max_24h_send, 'synced_at' => $c->last_synced_at?->toIso8601ZuluString()] : null,
            'smtp' => $smtp ? ['host' => $smtp->host, 'sent_today' => DB::table('messages')->where('workspace_id', $workspace->id)->where('sent_at', '>=', now()->startOfDay()->toDateTimeString())->count(),
                'max_per_day' => $smtp->max_per_day] : null,
        ]]);
    }
}
