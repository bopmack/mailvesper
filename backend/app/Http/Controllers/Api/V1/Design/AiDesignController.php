<?php

namespace App\Http\Controllers\Api\V1\Design;

use App\Exceptions\ApiException;
use App\Http\Controllers\Concerns\WorkspaceAccess;
use App\Http\Controllers\Controller;
use App\Models\EmailTemplate;
use App\Models\Workspace;
use App\Services\Ai\AiClient;
use App\Services\Audit;
use App\Services\Design\AiDesigner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** "Design with AI": a written brief becomes an editable template (Community feature). */
class AiDesignController extends Controller
{
    use WorkspaceAccess;

    /** What the page needs: can AI design here, and with which tones. */
    public function show(Workspace $workspace, AiClient $ai): JsonResponse
    {
        return response()->json(['available' => $ai->configured(), 'tones' => array_keys(AiDesigner::TONES)]);
    }

    public function store(Request $request, Workspace $workspace, AiDesigner $designer): JsonResponse
    {
        $this->requireEditor($request, 'design templates');
        $data = $request->validate([
            'brief' => ['required', 'string', 'min:10', 'max:1500'],
            'link' => ['required', 'string', 'max:500', 'regex:#^https://[^\s<>"]+$#i'],
            'tone' => ['nullable', 'string', 'in:'.implode(',', array_keys(AiDesigner::TONES))],
        ], [
            'brief.min' => 'Tell the AI a little more about the email (at least a sentence).',
            'link.regex' => 'Use a full https:// link, like https://yourshop.com/sale.',
        ]);
        if (EmailTemplate::where('workspace_id', $workspace->id)->count() >= 500) {
            throw new ApiException('template_limit', 'You can have up to 500 templates.', 422);
        }
        $user = $this->currentUser($request);
        $t = $designer->design($workspace, $user, $data['brief'], $data['link'], $data['tone'] ?? 'friendly');
        Audit::log('template.created', $user, $workspace->id, ['name' => $t->name, 'via' => 'ai']);

        return response()->json(['template' => TemplateController::summary($t)], 201);
    }
}
