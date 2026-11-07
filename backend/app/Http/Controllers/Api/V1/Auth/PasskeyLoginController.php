<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Services\AuthSession;
use App\Services\Passkeys;
use App\Services\Platform\Brand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Sign in with a passkey. User verification is required, so it satisfies two-step verification too. */
class PasskeyLoginController extends Controller
{
    public function options(Request $request, Passkeys $passkeys): JsonResponse
    {
        $this->needSession($request);

        return response()->json($passkeys->loginOptions($request));
    }

    public function verify(Request $request, Passkeys $passkeys, AuthSession $session): JsonResponse
    {
        $this->needSession($request);
        $data = $request->validate([
            'id' => ['required', 'string', 'max:512'],
            'client_data_json' => ['required', 'string', 'max:8192'],
            'authenticator_data' => ['required', 'string', 'max:8192'],
            'signature' => ['required', 'string', 'max:4096'],
        ]);
        $user = $passkeys->authenticate($request, $data);

        return response()->json($session->complete($request, $user, true, 'passkey'));
    }

    private function needSession(Request $request): void
    {
        if (! $request->hasSession()) {
            throw new ApiException('stateless_request', 'Sign-in must come from the '.Brand::name().' website.', 400);
        }
    }
}
