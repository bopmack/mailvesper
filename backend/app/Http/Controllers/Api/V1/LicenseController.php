<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit;
use App\Services\Platform\Brand;
use App\Services\Platform\Settings;
use App\Support\Edition;
use App\Support\Installation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * The installation's MailVesper Pro license. Managed by the installation admin: the first account (Community's one
 * admin login; on Pro the operator, until the platform admin panel takes over).
 */
class LicenseController extends Controller
{
    public function show(Request $request, Edition $edition): JsonResponse
    {
        return response()->json($this->state($request, $edition));
    }

    public function update(Request $request, Edition $edition): JsonResponse
    {
        $this->requireAdmin($request);
        $data = $request->validate(['key' => ['nullable', 'string', 'max:4000']]);
        $key = trim((string) ($data['key'] ?? ''));
        $check = null;
        if ($key !== '') {
            $check = $edition->checkLicense($key);
            if ($check === null) {
                throw new ApiException('pro_not_installed', 'MailVesper Pro isn’t installed on this server yet. Download it from your '.Brand::name().' account, put it in the app’s pro/ folder, then add the key.', 422);
            }
            if (! $check['ok']) {
                throw new ApiException('license_invalid', (string) $check['problem'], 422, ['key' => (string) $check['problem']]);
            }
        }
        $previous = (string) Settings::get('license.key');
        Settings::set('license.key', $key === '' ? null : ($check['token'] ?? $key), $this->user($request)->id);
        if ($key === '' && $previous !== '') {
            try {
                $edition->licenseRemoved($previous); // frees this domain at the license server
            } catch (Throwable $e) {
                report($e); // removing the key here must work even when the license server is unreachable
            }
        }
        Audit::log($key === '' ? 'license.removed' : 'license.added', $this->user($request));
        // Pro's routes are registered at boot: rebuild the route cache so they appear (or go) right away.
        if (app()->routesAreCached()) {
            Artisan::call('route:cache');
        }

        return response()->json(['saved' => true, 'reload' => true]);
    }

    /** @return array<string, mixed> */
    private function state(Request $request, Edition $edition): array
    {
        return $edition->toArray() + ['pro_installed' => $edition->proInstalled(), 'can_manage' => $this->isAdmin($request)];
    }

    private function isAdmin(Request $request): bool
    {
        return Installation::isAdmin($this->user($request));
    }

    private function requireAdmin(Request $request): void
    {
        if (! $this->isAdmin($request)) {
            throw new ApiException('forbidden', 'Only the installation’s admin can change the license.', 403);
        }
    }

    private function user(Request $request): User
    {
        /** @var User $u */
        $u = $request->user();

        return $u;
    }
}
