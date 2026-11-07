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

class EmailVerificationController extends Controller
{
    public function verify(Request $request, EmailCodeService $codes): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'regex:/^\d{6}$/']], ['code.regex' => 'Enter the 6-digit code.']);
        /** @var User $user */
        $user = $request->user();

        if (! $user->hasVerifiedEmail()) {
            $codes->verify($user->email, CodePurpose::VerifyEmail, $data['code']);
            $user->markEmailAsVerified();
            Audit::log('auth.email_verified', $user);
        }

        return response()->json(AuthSession::payload($user->fresh() ?? $user));
    }

    public function resend(Request $request, EmailCodeService $codes): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if ($user->hasVerifiedEmail()) {
            return response()->json(['sent' => false, 'already_verified' => true]);
        }

        $code = $codes->issue($user->email, CodePurpose::VerifyEmail, $request->ip());
        $delivered = SafeMail::send($user->email, new CodeMail($code, CodePurpose::VerifyEmail));

        return response()->json(['sent' => $delivered, 'resend_in' => EmailCodeService::COOLDOWN_SECONDS], 202);
    }
}
