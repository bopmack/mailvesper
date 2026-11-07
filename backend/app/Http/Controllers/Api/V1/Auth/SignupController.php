<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\CodePurpose;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Mail\CodeMail;
use App\Models\User;
use App\Services\Audit;
use App\Services\AuthSession;
use App\Services\EmailCodeService;
use App\Services\Platform\Brand;
use App\Services\SafeMail;
use App\Support\Edition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class SignupController extends Controller
{
    public function __invoke(Request $request, EmailCodeService $codes, AuthSession $session): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            // NIST 800-63B rev 4: length over composition rules, screen against breached passwords.
            'password' => ['required', 'string', Password::min(12)->max(128)->uncompromised()],
            'timezone' => ['sometimes', 'nullable', 'timezone:all'],
        ], [
            'password.min' => 'Use at least 12 characters.',
            'password.max' => 'Use 128 characters or fewer.',
            'password.uncompromised' => 'This password appears in known data breaches. Choose a different one.',
        ]);
        $email = User::normalizeEmail($data['email']);
        $edition = app(Edition::class);

        if (User::where('email', $email)->exists()) {
            throw new ApiException('email_taken', 'There’s already an account with this email. Log in instead.', 422, ['email' => 'There’s already an account with this email. Log in instead.']);
        }
        if (! $edition->signupOpen($email)) {
            throw new ApiException('signup_closed', 'Sign-up is closed on this '.Brand::name().'. Ask the admin to give you access.', 403);
        }
        $firstAdmin = $edition->trustsFirstAccount(); // signupOpen() above means this is the very first account

        $user = User::create([
            'name' => trim($data['name']),
            'email' => $email,
            'password' => $data['password'],
            'timezone' => $data['timezone'] ?? 'UTC',
        ]);
        Audit::log('auth.signup', $user, meta: $firstAdmin ? ['first_admin' => true] : []);

        // Community's first account is the installation's own admin: no email code (mail may not be set up yet).
        if ($firstAdmin) {
            $user->markEmailAsVerified();

            return response()->json($session->start($request, $user, false, 'signup') + ['code_delivered' => null], 201);
        }

        $code = $codes->issue($email, CodePurpose::VerifyEmail, $request->ip());
        $delivered = SafeMail::send($email, new CodeMail($code, CodePurpose::VerifyEmail));

        return response()->json($session->start($request, $user, false, 'signup') + ['code_delivered' => $delivered], 201);
    }
}
