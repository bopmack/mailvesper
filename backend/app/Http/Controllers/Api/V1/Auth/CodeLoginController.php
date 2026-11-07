<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\CodePurpose;
use App\Http\Controllers\Controller;
use App\Mail\CodeMail;
use App\Models\User;
use App\Services\Audit;
use App\Services\AuthSession;
use App\Services\EmailCodeService;
use App\Services\SafeMail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Passwordless sign-in with a 6-digit emailed code. */
class CodeLoginController extends Controller
{
    public function send(Request $request, EmailCodeService $codes): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email:rfc', 'max:254']]);
        $email = User::normalizeEmail($data['email']);
        $user = User::where('email', $email)->first();

        // Same response whether or not the account exists.
        if ($user) {
            $code = $codes->issue($email, CodePurpose::Login, $request->ip());
            SafeMail::send($email, new CodeMail($code, CodePurpose::Login));
            Audit::log('auth.code_sent', $user);
        }

        return response()->json(['sent' => true, 'expires_in' => EmailCodeService::TTL_MINUTES * 60, 'resend_in' => EmailCodeService::COOLDOWN_SECONDS], 202);
    }

    public function verify(Request $request, EmailCodeService $codes, AuthSession $session): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
        ], ['code.regex' => 'Enter the 6-digit code.']);
        $email = User::normalizeEmail($data['email']);

        $codes->verify($email, CodePurpose::Login, $data['code']);
        $user = User::where('email', $email)->firstOrFail();
        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified(); // they just proved they own the inbox
        }

        return response()->json($session->start($request, $user, false, 'code'));
    }
}
