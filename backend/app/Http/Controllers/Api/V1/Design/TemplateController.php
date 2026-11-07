<?php

namespace App\Http\Controllers\Api\V1\Design;

use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\Sending\EmailController;
use App\Http\Controllers\Concerns\Paginates;
use App\Http\Controllers\Concerns\WorkspaceAccess;
use App\Http\Controllers\Controller;
use App\Models\EmailTemplate;
use App\Models\Workspace;
use App\Services\Audit;
use App\Services\Design\DocValidator;
use App\Services\Design\EmailRenderer;
use App\Services\Sending\Mailer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Saved email designs. Every save renders the HTML on the server with the builder's own renderer. */
class TemplateController extends Controller
{
    use Paginates;
    use WorkspaceAccess;

    public function __construct(private DocValidator $validator, private EmailRenderer $renderer) {}

    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        $search = trim((string) $request->input('q', ''));
        $query = EmailTemplate::where('workspace_id', $workspace->id)->when($search !== '', fn ($q) => $q->where('name', 'like', '%'.addcslashes($search, '%_\\').'%'))->orderByDesc('updated_at')->orderByDesc('id')
            ->select(['id', 'name', 'subject', 'category', 'size_kb', 'checks', 'updated_at', 'last_used_at', 'updated_by']);
        [$rows, $meta] = $this->paged($request) ? $this->page($request, $query, 48) : [$query->get(), null];

        return response()->json(['templates' => $rows->map(fn (EmailTemplate $t) => self::summary($t))] + ($meta ? ['meta' => $meta] : []));
    }

    public function show(Workspace $workspace, string $id): JsonResponse
    {
        $t = $this->find($workspace, $id);

        return response()->json(['template' => self::summary($t) + ['doc' => $t->doc, 'html' => $t->html]]);
    }

    public function store(Request $request, Workspace $workspace): JsonResponse
    {
        $this->requireEditor($request, 'save templates');
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'subject' => ['nullable', 'string', 'max:255'], 'category' => ['nullable', 'string', 'max:40'], 'doc' => ['required']]);
        if (EmailTemplate::where('workspace_id', $workspace->id)->count() >= 500) {
            throw new ApiException('template_limit', 'You can have up to 500 templates.', 422);
        }
        $doc = $this->validator->validate($data['doc']);
        $r = $this->renderer->render($doc, (string) ($data['subject'] ?? ''));
        $user = $this->currentUser($request);
        $t = EmailTemplate::create(['workspace_id' => $workspace->id, 'name' => trim($data['name']), 'subject' => $data['subject'] ?? null, 'category' => $data['category'] ?? null,
            'doc' => $doc, 'html' => $r['html'], 'size_kb' => $r['kb'], 'checks' => $r['checks'], 'created_by' => $user->id, 'updated_by' => $user->id]);
        Audit::log('template.created', $user, $workspace->id, ['name' => $t->name]);

        return response()->json(['template' => self::summary($t) + ['html' => $t->html]], 201);
    }

    public function update(Request $request, Workspace $workspace, string $id): JsonResponse
    {
        $this->requireEditor($request, 'edit templates');
        $t = $this->find($workspace, $id);
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:120'], 'subject' => ['nullable', 'string', 'max:255'], 'category' => ['nullable', 'string', 'max:40'], 'doc' => ['sometimes']]);
        if (array_key_exists('doc', $data)) {
            $doc = $this->validator->validate($data['doc']);
            $r = $this->renderer->render($doc, (string) ($data['subject'] ?? $t->subject ?? ''));
            $t->fill(['doc' => $doc, 'html' => $r['html'], 'size_kb' => $r['kb'], 'checks' => $r['checks']]);
        }
        $t->fill(array_intersect_key($data, array_flip(['name', 'subject', 'category'])));
        $t->updated_by = $this->currentUser($request)->id;
        $t->save();

        return response()->json(['template' => self::summary($t) + ['html' => $t->html]]);
    }

    public function duplicate(Request $request, Workspace $workspace, string $id): JsonResponse
    {
        $this->requireEditor($request, 'save templates');
        $t = $this->find($workspace, $id)->replicate(['last_used_at']);
        $t->name = mb_substr($t->name.' (copy)', 0, 120);
        $t->created_by = $t->updated_by = $this->currentUser($request)->id;
        $t->save();

        return response()->json(['template' => self::summary($t)], 201);
    }

    public function destroy(Request $request, Workspace $workspace, string $id): JsonResponse
    {
        $this->requireEditor($request, 'delete templates');
        $t = $this->find($workspace, $id);
        $t->delete();
        Audit::log('template.deleted', $this->currentUser($request), $workspace->id, ['name' => $t->name]);

        return response()->json(['deleted' => true]);
    }

    /** Renders a design without saving it: the HTML exactly as it would be sent, plus the checks. */
    public function render(Request $request, Workspace $workspace): JsonResponse
    {
        $doc = $this->validator->validate($request->input('doc'));

        return response()->json($this->renderer->render($doc, (string) $request->input('subject', '')));
    }

    /** Sends the saved design to one address, through the real sending pipeline. */
    public function test(Request $request, Workspace $workspace, string $id, Mailer $mailer): JsonResponse
    {
        $this->requireEditor($request, 'send test emails');
        $t = $this->find($workspace, $id);
        $payload = $mailer->validate($workspace, ['from' => $request->input('from'), 'to' => $request->input('to'), 'template' => $t->id,
            'subject' => '[Test] '.($request->input('subject') ?: ($t->subject ?: $t->name))]);
        if (count($payload['to']) !== 1) {
            throw new ApiException('validation_failed', 'Send a test to one address at a time.', 422, ['to' => 'One address only.']);
        }

        return response()->json(EmailController::present($mailer->submit($workspace, [$payload], 'test')[0]), 202);
    }

    private function find(Workspace $workspace, string $id): EmailTemplate
    {
        return EmailTemplate::where('workspace_id', $workspace->id)->find($id) ?? throw new ApiException('not_found', 'Template not found.', 404);
    }

    /** @return array<string, mixed> */
    public static function summary(EmailTemplate $t): array
    {
        $checks = collect($t->checks ?? []);

        return ['id' => $t->id, 'name' => $t->name, 'subject' => $t->subject, 'category' => $t->category, 'size_kb' => $t->size_kb, 'checks' => $t->checks,
            'issues' => $checks->whereIn('status', ['bad', 'warn'])->count(), 'updated_at' => $t->updated_at?->toIso8601ZuluString(), 'last_used_at' => $t->last_used_at?->toIso8601ZuluString()];
    }
}
