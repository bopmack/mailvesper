<?php

namespace App\Http\Controllers\Api\V1\Migration;

use App\Exceptions\ApiException;
use App\Http\Controllers\Concerns\WorkspaceAccess;
use App\Http\Controllers\Controller;
use App\Models\ProviderImport;
use App\Models\Workspace;
use App\Services\Audit;
use App\Services\Migration\MigrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Migrate & save: provider imports and the savings calculator. */
class MigrationController extends Controller
{
    use WorkspaceAccess;

    public function __construct(private MigrationService $service) {}

    public function index(Workspace $workspace): JsonResponse
    {
        return response()->json(['imports' => ProviderImport::where('workspace_id', $workspace->id)->orderByDesc('created_at')->limit(20)->get()->map(fn ($i) => $this->present($i))->values(),
            'savings' => ($workspace->settings ?? [])['savings'] ?? null]);
    }

    public function connect(Request $request, Workspace $workspace): JsonResponse
    {
        $this->requireManager($request, 'import from another provider');
        $data = $request->validate(['provider' => ['required', Rule::in(array_keys(MigrationService::PROVIDERS))], 'api_key' => ['required', 'string', 'min:8', 'max:400'],
            'domain' => ['nullable', 'string', 'max:253'], 'region' => ['nullable', Rule::in(['us', 'eu'])]]);
        $creds = array_filter(['api_key' => trim($data['api_key']), 'domain' => isset($data['domain']) ? mb_strtolower(trim($data['domain'])) : null, 'region' => $data['region'] ?? null]);
        $imp = $this->service->connect($workspace, $this->currentUser($request), $data['provider'], $creds);
        Audit::log('migration.connected', $this->currentUser($request), $workspace->id, ['provider' => $imp->provider, 'account' => $imp->account_name]);

        return response()->json(['import' => $this->present($imp)], 201);
    }

    public function start(Request $request, Workspace $workspace, string $id): JsonResponse
    {
        $this->requireManager($request, 'import from another provider');
        $data = $request->validate(['contacts' => ['boolean'], 'lists' => ['boolean'], 'templates' => ['boolean'], 'list_ids' => ['nullable', 'array', 'max:200'], 'list_ids.*' => ['string', 'max:100'], 'tag' => ['nullable', 'string', 'max:64']]);
        $imp = $this->service->start($this->find($workspace, $id), $data);
        Audit::log('migration.started', $this->currentUser($request), $workspace->id, ['provider' => $imp->provider]);

        return response()->json(['import' => $this->present($imp)]);
    }

    public function show(Workspace $workspace, string $id): JsonResponse
    {
        return response()->json(['import' => $this->present($this->find($workspace, $id))]);
    }

    public function cancel(Request $request, Workspace $workspace, string $id): JsonResponse
    {
        $this->requireManager($request, 'cancel imports');
        $imp = $this->find($workspace, $id);
        if (! in_array($imp->status, ['connected', 'importing'], true)) {
            throw new ApiException('not_running', 'This import isn’t running.', 422);
        }
        $imp->forceFill(['status' => 'cancelled', 'finished_at' => now(), 'credentials' => []])->save();

        return response()->json(['import' => $this->present($imp)]);
    }

    /** The savings calculator, kept with the workspace so it shows up next time (and in the admin panel). */
    public function savings(Request $request, Workspace $workspace): JsonResponse
    {
        $this->requireEditor($request, 'save the savings estimate');
        $d = $request->validate(['provider' => ['required', 'string', 'max:40'], 'monthly_bill' => ['required', 'numeric', 'min:0', 'max:1000000'], 'emails_per_month' => ['required', 'integer', 'min:0', 'max:100000000'],
            'contacts' => ['required', 'integer', 'min:0', 'max:50000000']]);
        $ses = round($d['emails_per_month'] / 1000 * 0.10, 2); // AWS SES public price: $0.10 per 1,000 emails
        [$plan, $price] = $d['contacts'] <= 25000 ? ['Starter', 29] : ($d['contacts'] <= 100000 ? ['Growth', 79] : ['Scale', 199]);
        $ours = round($ses + $price, 2);
        $out = ['inputs' => $d, 'ses_monthly' => $ses, 'plan' => $plan, 'plan_price' => $price, 'sendline_monthly' => $ours, 'monthly_saving' => round($d['monthly_bill'] - $ours, 2),
            'yearly_saving' => round(($d['monthly_bill'] - $ours) * 12, 2), 'saved_at' => now()->toIso8601ZuluString()];
        $workspace->settings = array_merge($workspace->settings ?? [], ['savings' => $out]);
        $workspace->save();

        return response()->json(['savings' => $out]);
    }

    /** @return array<string, mixed> */
    private function present(ProviderImport $i): array
    {
        return ['id' => $i->id, 'provider' => $i->provider, 'provider_name' => MigrationService::PROVIDERS[$i->provider] ?? $i->provider, 'status' => $i->status, 'account_name' => $i->account_name,
            'discovery' => $i->discovery, 'options' => $i->options, 'stage' => $i->stage, 'progress' => $i->progress, 'notes' => $i->notes ?? [], 'error' => $i->error,
            'created_at' => $i->created_at->toIso8601ZuluString(), 'started_at' => $i->started_at?->toIso8601ZuluString(), 'finished_at' => $i->finished_at?->toIso8601ZuluString()];
    }

    private function find(Workspace $workspace, string $id): ProviderImport
    {
        return ProviderImport::where('workspace_id', $workspace->id)->find($id) ?? throw new ApiException('not_found', 'Import not found.', 404);
    }
}
