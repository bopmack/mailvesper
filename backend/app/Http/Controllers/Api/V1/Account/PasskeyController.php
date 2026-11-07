<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Passkey;
use App\Models\User;
use App\Services\Audit;
use App\Services\Passkeys;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/** Settings → Security → Passkeys. */
class PasskeyController extends Controller
{
    public function __construct(private Passkeys $passkeys) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['passkeys' => Passkey::where('user_id', $this->user($request)->id)->orderBy('created_at')->get()
            ->map(fn (Passkey $p) => ['id' => $p->id, 'name' => $p->name, 'created_at' => $p->created_at?->toIso8601String(), 'last_used_at' => $p->last_used_at?->toIso8601String()])]);
    }

    public function options(Request $request): JsonResponse
    {
        $user = $this->user($request);
        if ($user->password !== null && ! Hash::check((string) $request->input('password', ''), $user->password)) {
            throw new ApiException('password_incorrect', 'That password isn’t right.', 422, ['password' => 'That password isn’t right.']);
        }
        if (Passkey::where('user_id', $user->id)->count() >= 10) {
            throw new ApiException('passkey_limit', 'You can have up to 10 passkeys. Remove one first.', 422);
        }

        return response()->json($this->passkeys->registrationOptions($request, $user));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:80'],
            'client_data_json' => ['required', 'string', 'max:8192'],
            'attestation_object' => ['required', 'string', 'max:32768'],
            'transports' => ['nullable', 'array', 'max:8'],
        ]);
        $user = $this->user($request);
        $passkey = $this->passkeys->register($request, $user, $data);
        Audit::log('security.passkey_added', $user, meta: ['name' => $passkey->name]);

        return response()->json(['id' => $passkey->id, 'name' => $passkey->name], 201);
    }

    public function update(Request $request, Passkey $passkey): JsonResponse
    {
        $this->own($request, $passkey);
        $data = $request->validate(['name' => ['required', 'string', 'min:1', 'max:80']]);
        $passkey->forceFill(['name' => trim($data['name'])])->save();

        return response()->json(['id' => $passkey->id, 'name' => $passkey->name]);
    }

    public function destroy(Request $request, Passkey $passkey): JsonResponse
    {
        $this->own($request, $passkey);
        $passkey->delete();
        Audit::log('security.passkey_removed', $this->user($request), meta: ['name' => $passkey->name]);

        return response()->json(['removed' => true]);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    private function own(Request $request, Passkey $passkey): void
    {
        if ($passkey->user_id !== $this->user($request)->id) {
            throw new ApiException('not_found', 'Not found.', 404);
        }
    }
}
