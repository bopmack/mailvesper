<?php

namespace App\Http\Controllers\Api\V1\Journeys;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Jobs\AdvanceJourneyRun;
use App\Models\Contact;
use App\Models\Journey;
use App\Models\JourneyRun;
use App\Models\JourneyVersion;
use App\Services\Journeys\JourneyCore;
use App\Services\Journeys\JourneyData;
use App\Services\Journeys\JourneyEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A "Human approval" step's links. Opening the link only shows the request (mail scanners open links too);
 * pressing Approve or Reject answers it, once, while the run is still waiting.
 */
class ApprovalController extends Controller
{
    public function show(string $token): JsonResponse
    {
        [$run, $journey, $node] = $this->find($token);
        $contact = Contact::find($run->contact_id);
        $waiting = $this->waiting($run, $node['id']);

        return response()->json([
            'journey' => $journey->name, 'step' => $node['title'] ?: 'Human approval',
            'message' => $contact ? JourneyEngine::notifyText($journey, $contact, (string) $node['config']['message'], JourneyData::input($contact, $run->context, $run->data)) : null,
            'contact' => $contact ? ['email' => $contact->email, 'name' => $contact->name()] : null,
            'status' => $waiting ? 'waiting' : 'closed', 'until' => $waiting ? $run->wake_at?->toIso8601ZuluString() : null,
        ]);
    }

    public function answer(Request $request, string $token): JsonResponse
    {
        $d = $request->validate(['decision' => ['required', 'in:approve,reject']]);
        [$run, , $node] = $this->find($token);
        $signal = $d['decision'] === 'approve' ? 'yes' : 'no';
        $ok = JourneyRun::whereKey($run->id)->where('status', 'waiting')->where('waiting_for', JourneyCore::APPROVAL_KEY.$run->id.':'.$node['id'])
            ->update(['waiting_for' => null, 'wake_at' => now(), 'signal' => $signal]);
        if (! $ok) {
            throw new ApiException('closed', 'This request was already answered, or it expired.', 409);
        }
        AdvanceJourneyRun::dispatchFor($run->id);

        return response()->json(['decision' => $d['decision']]);
    }

    /** @return array{0: JourneyRun, 1: Journey, 2: array<string, mixed>} */
    private function find(string $token): array
    {
        $gone = new ApiException('not_found', 'This approval link isn’t valid any more.', 404);
        [$runId, $nodeId] = JourneyCore::readApproval($token) ?? throw $gone;
        $run = JourneyRun::find($runId) ?? throw $gone;
        $journey = Journey::find($run->journey_id) ?? throw $gone;
        $version = JourneyVersion::find($run->version_id) ?? throw $gone;
        $node = collect($version->graph['nodes'])->first(fn ($n) => $n['id'] === $nodeId && $n['type'] === 'approval') ?? throw $gone;

        return [$run, $journey, $node];
    }

    private function waiting(JourneyRun $run, string $nodeId): bool
    {
        return $run->status === 'waiting' && $run->waiting_for === JourneyCore::APPROVAL_KEY.$run->id.':'.$nodeId;
    }
}
