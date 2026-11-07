<?php

namespace App\Http\Controllers\Api\V1\Campaigns;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Workspace;
use App\Services\Campaigns\CampaignReport;
use Illuminate\Http\JsonResponse;

/** Public, read-only campaign report (no recipient addresses). */
class SharedReportController extends Controller
{
    public function __invoke(string $token, CampaignReport $report): JsonResponse
    {
        $c = Campaign::where('share_token', $token)->first() ?? throw new ApiException('not_found', 'This report link isn’t valid any more.', 404);
        $r = $report->build($c);
        unset($r['average']);

        return response()->json(['campaign' => ['name' => $c->name, 'subject' => $c->subject, 'sender' => Workspace::where('id', $c->workspace_id)->value('name'),
            'started_at' => $c->started_at?->toIso8601ZuluString(), 'status' => $c->status], 'report' => $r]);
    }
}
