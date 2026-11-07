<?php

namespace App\Http\Controllers\Api\V1\Mailbox;

use App\Exceptions\ApiException;
use App\Http\Controllers\Concerns\WorkspaceAccess;
use App\Http\Controllers\Controller;
use App\Models\Mailbox;
use App\Models\Workspace;
use App\Services\Audit;
use App\Services\Mailbox\ImapClient;
use App\Services\Mailbox\MailboxError;
use App\Services\Mailbox\MailboxReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Sending → Mailbox: the IMAP mailbox replies, late bounces and DMARC reports are read from (owners and admins). */
class MailboxController extends Controller
{
    use WorkspaceAccess;

    public function show(Workspace $workspace): JsonResponse
    {
        $box = Mailbox::where('workspace_id', $workspace->id)->first();

        return response()->json(['mailbox' => $box ? self::present($box) : null]);
    }

    public function save(Request $request, Workspace $workspace): JsonResponse
    {
        $this->requireManager($request, 'set up the mailbox');
        $box = Mailbox::where('workspace_id', $workspace->id)->first();
        $d = $request->validate([
            'host' => [$box ? 'sometimes' : 'required', 'string', 'max:255', 'regex:/^[A-Za-z0-9.-]+$/'],
            'port' => ['sometimes', 'integer', Rule::in([993, 143])],
            'encryption' => ['sometimes', Rule::in(['ssl', 'tls'])],
            'username' => [$box ? 'sometimes' : 'required', 'string', 'max:255'],
            'password' => [$box ? 'sometimes' : 'required', 'string', 'max:500'],
            'folder' => ['sometimes', 'string', 'max:100'],
            'read_replies' => ['sometimes', 'boolean'], 'read_bounces' => ['sometimes', 'boolean'], 'read_dmarc' => ['sometimes', 'boolean'], 'enabled' => ['sometimes', 'boolean'],
        ], ['host.regex' => 'Enter the mail server’s name, like imap.gmail.com.']);
        $box ??= new Mailbox(['workspace_id' => $workspace->id]);
        $connectionChanged = ! $box->exists || array_intersect_key($d, array_flip(['host', 'port', 'encryption', 'username', 'password', 'folder'])) !== [];
        $box->fill($d);
        if (isset($d['encryption']) && ! isset($d['port'])) {
            $box->port = $d['encryption'] === 'ssl' ? 993 : 143;
        }
        if ($connectionChanged) {
            // Only working settings are saved; the check also sets where reading starts (now).
            try {
                $conn = ImapClient::connect($box);
                ['validity' => $validity, 'next' => $next] = $conn->select($box->folder ?: 'INBOX');
                $conn->close();
            } catch (MailboxError $e) {
                throw new ApiException('mailbox_failed', $e->getMessage(), 422);
            }
            $box->forceFill(['uid_validity' => $validity, 'last_uid' => max(0, $next - 1), 'last_error' => null, 'last_checked_at' => now()]);
        }
        $box->save();
        $box->refresh(); // the table's defaults (folder, what to read) for a new mailbox
        Audit::log('mailbox.saved', $this->currentUser($request), $workspace->id, ['host' => $box->host]);

        return response()->json(['mailbox' => self::present($box)]);
    }

    public function check(Request $request, Workspace $workspace, MailboxReader $reader): JsonResponse
    {
        $this->requireManager($request, 'check the mailbox');
        $box = Mailbox::where('workspace_id', $workspace->id)->first() ?? throw new ApiException('not_found', 'Set up the mailbox first.', 404);
        try {
            $found = $reader->check($box, 20);
        } catch (MailboxError $e) {
            throw new ApiException('mailbox_failed', $e->getMessage(), 422);
        }

        return response()->json(['found' => $found, 'mailbox' => self::present($box->refresh())]);
    }

    public function destroy(Request $request, Workspace $workspace): JsonResponse
    {
        $this->requireManager($request, 'remove the mailbox');
        Mailbox::where('workspace_id', $workspace->id)->delete();
        Audit::log('mailbox.removed', $this->currentUser($request), $workspace->id);

        return response()->json(['deleted' => true]);
    }

    /** @return array<string, mixed> */
    public static function present(Mailbox $b): array
    {
        return $b->only(['host', 'port', 'encryption', 'username', 'folder', 'read_replies', 'read_bounces', 'read_dmarc', 'enabled', 'replies', 'bounces', 'complaints', 'reports', 'last_error'])
            + ['last_checked_at' => $b->last_checked_at?->toIso8601ZuluString()];
    }
}
