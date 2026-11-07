<?php

namespace App\Http\Controllers\Api\V1\Sending;

use App\Exceptions\ApiException;
use App\Http\Controllers\Concerns\WorkspaceAccess;
use App\Http\Controllers\Controller;
use App\Models\EmailTemplate;
use App\Models\Message;
use App\Models\SmtpConnection;
use App\Models\Workspace;
use App\Services\Design\DocValidator;
use App\Services\Design\EmailRenderer;
use App\Services\Platform\Brand;
use App\Services\Sending\Mailer;
use App\Services\Sending\Senders;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Messages for the app (session auth): recent list, one message, "Send test" and Quick send. */
class MessageController extends Controller
{
    use WorkspaceAccess;

    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        $data = $request->validate(['before' => ['nullable', 'string', 'size:26'], 'status' => ['nullable', 'string', 'max:16']]);
        $rows = Message::where('workspace_id', $workspace->id)
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($data['before'] ?? null, fn ($q, $b) => $q->where('id', '<', $b))
            ->orderByDesc('id')->limit(51)->get();
        $more = $rows->count() > 50;

        return response()->json(['messages' => $rows->take(50)->map(fn (Message $m) => EmailController::present($m))->values(), 'next_before' => $more ? $rows[49]->id : null]);
    }

    public function show(Workspace $workspace, string $id): JsonResponse
    {
        $m = Message::where('workspace_id', $workspace->id)->find($id) ?? throw new ApiException('not_found', 'Not found.', 404);

        return response()->json(EmailController::present($m) + ['html' => $m->html, 'text' => $m->text]);
    }

    /** The "Send test" button: one email to one address, from a verified domain. */
    public function test(Request $request, Workspace $workspace, Mailer $mailer): JsonResponse
    {
        $this->requireEditor($request, 'send test emails');
        $via = Senders::method($workspace) === Senders::SMTP ? 'your SMTP server ('.(SmtpConnection::where('workspace_id', $workspace->id)->value('host') ?? 'SMTP').')' : 'your own Amazon SES';
        $payload = $mailer->validate($workspace, [
            'from' => $request->input('from'), 'to' => $request->input('to'), 'subject' => $request->input('subject', 'Test email from '.Brand::name()),
            'html' => $request->input('html') ?: '<p>Hello {{ first_name | there }},</p><p>This is a test email sent with '.Brand::name().' through '.e($via).'.</p><p style="color:#71717a;font-size:12px">Tracking code {{ tracking_code }}</p>',
            'variables' => ['first_name' => $request->input('first_name')],
        ]);
        if (count($payload['to']) !== 1) {
            throw new ApiException('validation_failed', 'Send a test to one address at a time.', 422, ['to' => 'One address only.']);
        }
        $messages = $mailer->submit($workspace, [$payload], 'test');

        return response()->json(EmailController::present($messages[0]), 202);
    }

    /**
     * Quick send: one real email to one person, from a template (saved or from the gallery, edited for this send
     * only: the template itself doesn't change) or a short plain message. Merge fields come from the contact when the address is one.
     */
    public function quick(Request $request, Workspace $workspace, Mailer $mailer, DocValidator $validator, EmailRenderer $renderer): JsonResponse
    {
        $this->requireEditor($request, 'send emails');
        $data = $request->validate([
            'template' => ['nullable', 'string', 'size:26'], 'doc' => ['nullable', 'array'], 'message' => ['nullable', 'string', 'max:20000'],
            'subject' => ['nullable', 'string', 'max:998'], 'variables' => ['nullable', 'array', 'max:100'], 'marketing' => ['nullable', 'boolean'],
        ]);
        $subject = trim((string) ($data['subject'] ?? ''));
        $template = isset($data['template']) ? (EmailTemplate::where('workspace_id', $workspace->id)->find($data['template'])
            ?? throw new ApiException('validation_failed', 'That template doesn’t exist in this workspace.', 422, ['template' => 'Unknown template.'])) : null;
        $doc = $data['doc'] ?? $template?->doc; // the design as edited for this send (a saved or a gallery template)
        if ($doc || $template) {
            $html = $doc ? $renderer->render($validator->validate($doc), $subject)['html'] : (string) $template->html; // imported HTML: as saved
            $text = null;
            $subject = $subject !== '' || ! $template ? $subject : (string) ($template->subject ?: $template->name);
            $template?->forceFill(['last_used_at' => now()])->saveQuietly();
        } else {
            $message = trim((string) ($data['message'] ?? ''));
            if ($message === '') {
                throw new ApiException('validation_failed', 'Write a message, or pick a template.', 422, ['message' => 'Write a message.']);
            }
            $paragraphs = preg_split('/\R{2,}/u', $message) ?: [];
            $html = '<div style="font-family:-apple-system,Segoe UI,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.6;color:#18181b">'
                .implode('', array_map(fn ($p) => '<p style="margin:0 0 14px">'.nl2br(e($p), false).'</p>', $paragraphs)).'</div>';
            $text = $message;
        }
        $vars = array_filter((array) ($data['variables'] ?? []), fn ($v) => is_scalar($v) && trim((string) $v) !== ''); // empty: the contact's own value
        $payload = $mailer->validate($workspace, ['from' => $request->input('from'), 'to' => $request->input('to'), 'subject' => $subject,
            'html' => $html, 'text' => $text, 'variables' => $vars, 'type' => ($data['marketing'] ?? false) ? 'marketing' : 'transactional']);
        if (count($payload['to']) !== 1) {
            throw new ApiException('validation_failed', 'Quick send goes to one person at a time. Use a campaign for more.', 422, ['to' => 'One address only.']);
        }

        return response()->json(EmailController::present($mailer->submit($workspace, [$payload], 'quick')[0]), 202);
    }
}
