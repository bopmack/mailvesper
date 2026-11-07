<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit;
use App\Services\AuthSession;
use App\Services\Platform\Brand;
use App\Services\Platform\Settings;
use App\Support\Edition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SessionController extends Controller
{
    /** For the sign-up and login pages: can a new account be created here, and is this installation still waiting for its admin? */
    public function setup(Edition $edition): JsonResponse
    {
        $open = $edition->signupOpen();

        return response()->json(['edition' => $edition->isPro() ? 'pro' : 'community', 'signup_open' => $open,
            'first_run' => ! DB::table('users')->exists(), 'links' => self::siteLinks(), 'brand' => Brand::public()]); // first_run: not installed yet
    }

    /**
     * The operator's privacy, terms and support links for the public pages. Only http(s) URLs and plain addresses
     * get through, so a setting can never become a javascript: link.
     *
     * @return array{privacy: ?string, terms: ?string, support_email: ?string}
     */
    public static function siteLinks(): array
    {
        $url = function (string $key): ?string {
            $v = trim((string) Settings::get($key));

            return preg_match('#^https?://[^\s"<>]+$#i', $v) && filter_var($v, FILTER_VALIDATE_URL) ? $v : null;
        };
        $email = trim((string) Settings::get('site.support_email'));

        return ['privacy' => $url('site.privacy_url'), 'terms' => $url('site.terms_url'),
            'support_email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null];
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(AuthSession::payload($user));
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        Audit::log('auth.logout', $user);
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['logged_out' => true]);
    }
}
