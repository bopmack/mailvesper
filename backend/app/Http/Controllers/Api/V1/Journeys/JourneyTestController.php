<?php

namespace App\Http\Controllers\Api\V1\Journeys;

use App\Exceptions\ApiException;
use App\Http\Controllers\Concerns\WorkspaceAccess;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Journey;
use App\Models\Workspace;
use App\Services\Contacts\ContactService;
use App\Services\Journeys\JourneyData;
use App\Services\Journeys\JourneyTester;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The editor's test data, as in n8n: fetch the trigger's test event (or listen for a test call to the journey URL),
 * pin it, then test one step at a time on it.
 */
class JourneyTestController extends Controller
{
    use WorkspaceAccess;

    public function __construct(private JourneyTester $tester) {}

    /** Test data for the trigger: the latest real event (for a contact, if an email is given), or an example. */
    public function trigger(Request $request, Workspace $workspace, string $id): JsonResponse
    {
        $this->requireEditor($request, 'test journeys');
        $j = $this->find($workspace, $id);
        $contact = null;
        if ($request->filled('email')) {
            $contact = Contact::where('workspace_id', $workspace->id)->where('email', ContactService::normaliseEmail((string) $request->input('email')))->first()
                ?? throw new ApiException('not_found', 'No contact with that email in this workspace.', 404, ['email' => 'Not found.']);
        }

        return response()->json(['sample' => $this->tester->sample($j, $contact)]);
    }

    /** "Listen for test event": the journey URL takes one test call (POST …?test=1) for two minutes. */
    public function listen(Request $request, Workspace $workspace, string $id): JsonResponse
    {
        $this->requireEditor($request, 'test journeys');
        $j = $this->find($workspace, $id);

        return response()->json(['listening' => JourneyTester::listen($j), 'url' => rtrim((string) config('app.url'), '/').'/v1/hooks/j/'.$j->hook_token.'?test=1']);
    }

    /** The pinned test data (or null when nothing is pinned yet). */
    public function show(Workspace $workspace, string $id): JsonResponse
    {
        $j = $this->find($workspace, $id);

        return response()->json(['test_data' => $j->test_data, 'listening' => JourneyTester::listening($j)]);
    }

    /** Pins test data, or unpins it with null. It must be about a contact of this workspace. */
    public function pin(Request $request, Workspace $workspace, string $id): JsonResponse
    {
        $this->requireEditor($request, 'test journeys');
        $j = $this->find($workspace, $id);
        $pin = $request->input('test_data');
        if ($pin !== null) {
            if (! is_array($pin) || ! is_array($pin['data'] ?? null) || strlen((string) json_encode($pin)) > JourneyData::MAX_RUN * 2) {
                throw new ApiException('validation_failed', 'The test data couldn’t be read, or is too large.', 422);
            }
            $contact = Contact::where('workspace_id', $workspace->id)->find((string) ($pin['data']['contact']['id'] ?? ''))
                ?? throw new ApiException('validation_failed', 'The test data must be about one of your contacts.', 422);
            $t = (array) ($pin['data']['trigger'] ?? []);
            $pin = ['source' => in_array($pin['source'] ?? null, ['event', 'example', 'webhook', 'run', 'edited'], true) ? $pin['source'] : 'edited',
                'at' => isset($pin['at']) && is_string($pin['at']) ? mb_substr($pin['at'], 0, 40) : null, 'note' => isset($pin['note']) && is_string($pin['note']) ? mb_substr($pin['note'], 0, 200) : null,
                'data' => JourneyData::input($contact, ['event' => isset($t['event']) && is_scalar($t['event']) ? (string) $t['event'] : null, 'properties' => (array) ($t['properties'] ?? [])], null)];
        }
        $j->forceFill(['test_data' => $pin])->save();

        return response()->json(['test_data' => $j->test_data]);
    }

    /** Runs one step on the test data it's given (the trigger's, plus what earlier steps output in this test). */
    public function step(Request $request, Workspace $workspace, string $id): JsonResponse
    {
        $this->requireEditor($request, 'test journeys');
        $j = $this->find($workspace, $id);
        $d = $request->validate(['node' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,24}$/'], 'data' => ['required', 'array'], 'choose' => ['nullable', 'in:yes,no,a,b']]);

        return response()->json(['result' => $this->tester->step($j, $d['node'], $d['data'], $d['choose'] ?? null)]);
    }

    private function find(Workspace $workspace, string $id): Journey
    {
        return Journey::where('workspace_id', $workspace->id)->find($id) ?? throw new ApiException('not_found', 'Journey not found.', 404);
    }
}
