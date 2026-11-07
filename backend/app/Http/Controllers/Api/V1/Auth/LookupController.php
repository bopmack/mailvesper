<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\Passkey;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Email-first sign-in: tells the page which methods this account can use.
 * This reveals whether an account exists (as Google, Slack and Linear do); the endpoint is rate limited.
 */
class LookupController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email:rfc', 'max:254']]);
        $user = User::where('email', User::normalizeEmail($data['email']))->first();

        if (! $user) {
            return response()->json(['exists' => false, 'methods' => [], 'sso' => null]);
        }

        $methods = array_values(array_filter([
            $user->password !== null ? 'password' : null,
            'code',
            Passkey::where('user_id', $user->id)->exists() ? 'passkey' : null,
            ...$user->socialAccounts()->pluck('provider')->all(),
        ]));

        return response()->json(['exists' => true, 'methods' => $methods, 'sso' => null]);
    }
}
