<?php

namespace App\Http\Controllers\Api\V1\Journeys;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Journey;
use App\Models\JourneyVersion;
use App\Models\Workspace;
use App\Services\Commerce\ProductAlerts;
use App\Services\Contacts\ContactService;
use App\Services\Journeys\JourneyEvents;
use App\Services\Journeys\JourneyGraph;
use App\Services\Journeys\JourneyTester;
use App\Support\Edition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

/**
 * Events from your app (POST /v1/events, full-access key) and from tools like Zapier or n8n
 * (POST /v1/hooks/j/{token}, the journey's own URL). Both create the contact if needed.
 */
class TrackController extends Controller
{
    public function __construct(private ContactService $contacts, private JourneyEvents $events) {}

    public const BATCH_MAX = 500;

    public function track(Request $request): JsonResponse
    {
        /** @var Workspace $ws */
        $ws = $request->attributes->get('workspace');
        $data = $this->check($request->all(), '');
        [$contactId, $duplicate] = $this->accept($ws, $data);

        return response()->json(['accepted' => true, 'contact_id' => $contactId] + ($duplicate ? ['duplicate' => true] : []), 202);
    }

    /** Many events in one call (e.g. "creator went live" for every subscriber). All are checked before any is recorded. */
    public function batch(Request $request): JsonResponse
    {
        /** @var Workspace $ws */
        $ws = $request->attributes->get('workspace');
        $items = json_decode($request->getContent(), true);
        if (! is_array($items) || ! array_is_list($items) || ! $items) {
            throw new ApiException('validation_failed', 'Send a JSON array of events.', 422);
        }
        if (count($items) > self::BATCH_MAX) {
            throw new ApiException('validation_failed', 'Send at most '.self::BATCH_MAX.' events per batch.', 422);
        }
        $clean = array_map(fn ($item, $i) => $this->check(is_array($item) ? $item : [], "$i."), $items, array_keys($items));
        $emails = array_unique(array_map(fn ($d) => ContactService::normaliseEmail((string) $d['email']), $clean));
        app(Edition::class)->assertRoom($ws, 'contacts', count($emails) - Contact::where('workspace_id', $ws->id)->whereIn('email', $emails)->count());
        $accepted = $duplicates = 0;
        foreach ($clean as $d) {
            [, $dup] = $this->accept($ws, $d);
            $dup ? $duplicates++ : $accepted++;
        }

        return response()->json(['accepted' => $accepted, 'duplicates' => $duplicates], 202);
    }

    /**
     * @param  array<mixed>  $input
     * @return array<string, mixed>
     */
    private function check(array $input, string $prefix): array
    {
        $v = Validator::make($input, [
            'email' => ['required', 'string', 'max:254'], 'event' => ['required', 'string', 'max:100'], 'properties' => ['nullable', 'array'],
            'occurred_at' => ['nullable', 'date'], 'contact' => ['nullable', 'array'], 'id' => ['nullable', 'string', 'max:80'],
        ]);
        if ($v->fails()) {
            $fields = [];
            foreach ($v->errors()->toArray() as $k => $m) {
                $fields[$prefix.$k] = $m[0];
            }
            throw new ApiException('validation_failed', reset($fields), 422, $fields);
        }
        $data = $v->validated();
        if (! JourneyGraph::validEvent($data['event'])) {
            throw new ApiException('validation_failed', 'Event names use letters, digits, _ . : - (up to 100), and can’t start with contact., email. or sendline.', 422, [$prefix.'event' => 'Invalid event name.']);
        }
        $data['properties'] = $this->props($data['properties'] ?? []);

        return $data;
    }

    /**
     * Records one checked event. An "id" makes retries safe: the same id within 24 hours is acknowledged but not
     * recorded or acted on again.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: string|null, 1: bool} [contact id, duplicate?]
     */
    private function accept(Workspace $ws, array $data): array
    {
        $key = isset($data['id']) ? 'evt:'.$ws->id.':'.hash('sha256', (string) $data['id']) : null;
        if ($key && ! Cache::add($key, 1, 86400)) {
            return [Contact::where('workspace_id', $ws->id)->where('email', ContactService::normaliseEmail((string) $data['email']))->value('id'), true];
        }
        try {
            $at = isset($data['occurred_at']) ? Carbon::parse($data['occurred_at'])->utc() : now();
            if ($at->isFuture()) {
                $at = now();
            }
            $contact = $this->contact($ws, (string) $data['email'], (array) ($data['contact'] ?? []), 'api');
            $this->events->fire($contact, (string) $data['event'], $data['properties'], 'api', $at);
        } catch (\Throwable $e) {
            if ($key) {
                Cache::forget($key); // not recorded: let a retry through
            }
            throw $e;
        }

        return [$contact->id, false];
    }

    /**
     * A product's price or stock from your store (POST /v1/products). A lower price or a restock sends
     * sendline.price_drop / sendline.back_in_stock to the contacts who showed interest (see ProductAlerts).
     */
    public function product(Request $request, ProductAlerts $alerts): JsonResponse
    {
        /** @var Workspace $ws */
        $ws = $request->attributes->get('workspace');
        $d = $request->validate(['product_id' => ['required', 'max:100'], 'price' => ['nullable', 'numeric', 'min:0', 'max:999999999999'], 'in_stock' => ['nullable', 'boolean'],
            'name' => ['nullable', 'string', 'max:255'], 'url' => ['nullable', 'url', 'max:500'], 'image' => ['nullable', 'url', 'max:500']]);
        if (! is_scalar($d['product_id']) || ! preg_match('/^[A-Za-z0-9_.:\/-]{1,100}$/', (string) $d['product_id'])) {
            throw new ApiException('validation_failed', 'product_id uses letters, digits and _ . : / - (up to 100).', 422, ['product_id' => 'Invalid product id.']);
        }
        $started = $alerts->update($ws->id, (string) $d['product_id'], isset($d['price']) ? (float) $d['price'] : null, isset($d['in_stock']) ? (bool) $d['in_stock'] : null,
            array_intersect_key($d, array_flip(['name', 'url', 'image'])));

        return response()->json(['accepted' => true, 'alerts' => $started], 202);
    }

    public function hook(Request $request, string $token): JsonResponse
    {
        $j = Journey::where('hook_token', $token)->first() ?? throw new ApiException('not_found', 'This journey URL isn’t valid.', 404);
        if ($request->boolean('test')) {
            return $this->testCall($request, $j);
        }
        $v = $j->status === 'active' && $j->live_version_id ? JourneyVersion::find($j->live_version_id) : null;
        if (! $v || ! collect($v->triggers)->contains(fn ($t) => $t['trigger'] === 'webhook')) {
            throw new ApiException('journey_inactive', 'This journey isn’t on, or doesn’t start from a webhook.', 409);
        }
        $data = $request->validate(['email' => ['required', 'string', 'max:254'], 'first_name' => ['nullable', 'string', 'max:100'], 'last_name' => ['nullable', 'string', 'max:100'],
            'fields' => ['nullable', 'array'], 'properties' => ['nullable', 'array'], 'id' => ['nullable', 'string', 'max:80']]);
        $ws = Workspace::findOrFail($j->workspace_id);
        $contact = $this->contact($ws, $data['email'], array_intersect_key($data, array_flip(['first_name', 'last_name', 'fields'])), 'webhook');
        // An "id" from the sender makes retries safe: the same id never starts a second run.
        $this->events->fire($contact, 'webhook:'.$j->id, $this->props($data['properties'] ?? []), 'webhook', null, $j->id, isset($data['id']) ? 'hook:'.$data['id'] : null);

        return response()->json(['accepted' => true], 202);
    }

    /** "Listen for test event" in the editor: one call becomes the pinned test data; no run starts. */
    private function testCall(Request $request, Journey $j): JsonResponse
    {
        if (! JourneyTester::listening($j)) {
            throw new ApiException('not_listening', 'Test calls work for two minutes after “Listen for test event” in the journey editor.', 409);
        }
        $data = $request->validate(['email' => ['required', 'string', 'max:254'], 'first_name' => ['nullable', 'string', 'max:100'], 'last_name' => ['nullable', 'string', 'max:100'],
            'fields' => ['nullable', 'array'], 'properties' => ['nullable', 'array'], 'id' => ['nullable', 'string', 'max:80']]);
        $ws = Workspace::findOrFail($j->workspace_id);
        $contact = $this->contact($ws, $data['email'], array_intersect_key($data, array_flip(['first_name', 'last_name', 'fields'])), 'webhook');
        JourneyTester::caught($j, $contact, $this->props($data['properties'] ?? []));

        return response()->json(['accepted' => true, 'test' => true], 202);
    }

    /** @param array<string, mixed> $extra */
    private function contact(Workspace $ws, string $email, array $extra, string $source): Contact
    {
        $email = ContactService::normaliseEmail($email);
        $existing = Contact::where('workspace_id', $ws->id)->where('email', $email)->first();
        if ($existing && ! $extra) {
            return $existing;
        }

        return $this->contacts->upsert($ws, ['email' => $email] + array_intersect_key($extra, array_flip(['first_name', 'last_name', 'fields'])), $source);
    }

    /**
     * Properties: flat-ish JSON, capped so one event can't bloat the timeline.
     *
     * @param  array<mixed>  $p
     * @return array<string, mixed>
     */
    private function props(array $p): array
    {
        if (strlen((string) json_encode($p)) > 8000) {
            throw new ApiException('validation_failed', 'Event properties can be up to 8 KB.', 422, ['properties' => 'Too large.']);
        }

        return $p;
    }
}
