<?php

namespace App\Http\Controllers\Api\V1\Ses;

use App\Enums\WorkspaceRole;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Audit;
use App\Services\Platform\Brand;
use App\Services\Ses\SesCatalog;
use App\Services\Ses\SesHandoff;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** One-command connect through AWS CloudShell. */
class SesHandoffController extends Controller
{
    public function __construct(private SesHandoff $handoff) {}

    /** The page asks for a fresh command. */
    public function start(Request $request, Workspace $workspace): JsonResponse
    {
        /** @var WorkspaceRole $role */
        $role = $request->attributes->get('workspace_role');
        if (! $role->canManageTeam()) {
            throw new ApiException('forbidden', 'Only owners and admins can connect Amazon SES.', 403);
        }
        /** @var User $user */
        $user = $request->user();
        $h = $this->handoff->start($workspace, $user);
        $script = rtrim((string) config('app.frontend_url'), '/').'/api/v1/ses/setup/'.$h['token'];

        return response()->json([
            'id' => $h['id'], 'expires_at' => $h['expires_at'], 'script_url' => $script,
            'command' => "curl -fsSL $script | bash",
            'cloudshell_url' => 'https://console.aws.amazon.com/cloudshell/home',
        ]);
    }

    /** The page polls this while the customer runs the command. */
    public function status(Workspace $workspace, string $id): JsonResponse
    {
        $s = $this->handoff->status($workspace, $id) ?? ['status' => 'expired', 'message' => 'This command expired. Get a new one.'];

        return response()->json($s);
    }

    /** CloudShell downloads the script. */
    public function script(string $token): Response
    {
        if (! $this->handoff->find($token)) {
            return response("echo '✗ This ".Brand::name().' setup command has expired. Go back to '.Brand::name()." and copy a fresh one.'; exit 1\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        return response($this->handoff->script($token), 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex']);
    }

    /** The script posts the new key. Answers in plain text for the terminal. */
    public function complete(Request $request, string $token): Response
    {
        set_time_limit(150); // AWS setup plus waiting for a brand-new key to propagate
        $v = Validator::make($request->all(), [
            'access_key_id' => ['required', 'string', 'regex:/^AKIA[A-Z0-9]{16}$/'],
            'secret_access_key' => ['required', 'string', 'size:40'],
            'region' => ['required', Rule::in(array_keys(SesCatalog::REGIONS))],
        ]);
        if ($v->fails()) {
            return $this->text('The setup script sent something unexpected. Copy a fresh command from '.Brand::name().' and run it again.', 422);
        }
        $data = $v->validated();
        try {
            $conn = $this->handoff->complete($token, $data['access_key_id'], $data['secret_access_key'], $data['region']);
        } catch (ApiException $e) {
            return $this->text($e->getMessage(), $e->status);
        }
        $user = User::find($conn->connected_by);
        Audit::log('ses.connected', $user, $conn->workspace_id, ['account' => $conn->aws_account_id, 'region' => $conn->region, 'method' => 'cloudshell']);

        $lines = ['✓ Connected AWS account '.$conn->aws_account_id.' ('.(SesCatalog::REGIONS[$conn->region] ?? $conn->region).') to '.Brand::name().'.'];
        $lines[] = $conn->status === 'active' ? '✓ Event tracking is set up.' : '! Some setup steps need attention. '.Brand::name().' shows what to do.';
        $lines[] = '';
        $lines[] = 'You can close CloudShell and go back to '.Brand::name().'. It has already moved on.';

        return $this->text(implode("\n", $lines), 200);
    }

    private function text(string $message, int $status): Response
    {
        return response($message."\n", $status, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
