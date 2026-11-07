<?php

namespace App\Http\Controllers\Api\V1\Contacts;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\ContactList;
use App\Models\Workspace;
use App\Services\Contacts\ContactService;
use App\Support\Edition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Public API (full-access keys): create or update contacts from your app. */
class ContactApiController extends Controller
{
    public function __construct(private ContactService $contacts) {}

    public function upsert(Request $request): JsonResponse
    {
        $ws = $this->workspace($request);
        $exists = Contact::where('workspace_id', $ws->id)->where('email', ContactService::normaliseEmail((string) $request->input('email', '')))->exists();
        $c = $this->contacts->upsert($ws, $request->only(self::FIELDS), 'api');

        return response()->json(['contact' => $this->full($c)], $exists ? 200 : 201);
    }

    public const BATCH_MAX = 500;

    public const FIELDS = ['email', 'first_name', 'last_name', 'fields', 'tags', 'lists', 'remove_lists', 'unsubscribed', 'resubscribe'];

    /** Lists, for integrations that keep their own (e.g. “Newsletter”). */
    public function lists(Request $request): JsonResponse
    {
        return response()->json(['data' => ContactList::where('workspace_id', $this->workspace($request)->id)->orderBy('name')->get(['id', 'name', 'description'])]);
    }

    /** Creates a list, or returns the one that already has this name (so integrations can call it on every connect). */
    public function storeList(Request $request): JsonResponse
    {
        $ws = $this->workspace($request);
        $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'description' => ['nullable', 'string', 'max:255']]);
        $existing = ContactList::where('workspace_id', $ws->id)->where('name', trim($data['name']))->first(['id', 'name', 'description']);
        if ($existing) {
            return response()->json(['list' => $existing]);
        }
        $l = ContactList::create(['workspace_id' => $ws->id, 'name' => trim($data['name']), 'description' => $data['description'] ?? null]);

        return response()->json(['list' => ['id' => $l->id, 'name' => $l->name, 'description' => $l->description]], 201);
    }

    /**
     * Many contacts in one call (syncing an existing user base): a JSON array of the same objects as upsert. Every
     * item is checked before anything is saved; new contacts must fit the plan as a whole.
     */
    public function batch(Request $request): JsonResponse
    {
        $ws = $this->workspace($request);
        $items = json_decode($request->getContent(), true);
        if (! is_array($items) || ! array_is_list($items) || ! $items) {
            throw new ApiException('validation_failed', 'Send a JSON array of contacts.', 422);
        }
        if (count($items) > self::BATCH_MAX) {
            throw new ApiException('validation_failed', 'Send at most '.self::BATCH_MAX.' contacts per batch.', 422);
        }
        $clean = [];
        foreach ($items as $i => $item) {
            $item = is_array($item) ? array_intersect_key($item, array_flip(self::FIELDS)) : [];
            $errors = $this->contacts->errors($ws, $item);
            if ($errors) {
                $fields = [];
                foreach ($errors as $k => $m) {
                    $fields["$i.$k"] = $m;
                }
                throw new ApiException('validation_failed', 'Contact '.($i + 1).': '.reset($errors), 422, $fields);
            }
            $clean[ContactService::normaliseEmail((string) $item['email'])] = $item; // the last one wins for a repeated email
        }
        $existing = Contact::where('workspace_id', $ws->id)->whereIn('email', array_keys($clean))->pluck('email')->all();
        app(Edition::class)->assertRoom($ws, 'contacts', count($clean) - count($existing));

        $out = [];
        foreach ($clean as $email => $item) {
            $c = $this->contacts->upsert($ws, $item, 'api');
            $out[] = ['email' => $email, 'id' => $c->id, 'status' => in_array($email, $existing, true) ? 'updated' : 'created'];
        }

        return response()->json(['data' => $out, 'created' => count($clean) - count($existing), 'updated' => count($existing)]);
    }

    public function show(Request $request, string $email): JsonResponse
    {
        return response()->json(['contact' => $this->full($this->find($request, $email))]);
    }

    public function destroy(Request $request, string $email): JsonResponse
    {
        $this->contacts->erase($this->find($request, $email));

        return response()->json(['erased' => true]);
    }

    /** @return array<string, mixed> */
    private function full(Contact $c): array
    {
        return ContactService::present($c) + ['tags' => DB::table('contact_tags')->where('contact_id', $c->id)->orderBy('tag')->pluck('tag'), 'lists' => $c->lists()->pluck('lists.id')];
    }

    private function find(Request $request, string $email): Contact
    {
        return Contact::where('workspace_id', $this->workspace($request)->id)->where('email', ContactService::normaliseEmail(urldecode($email)))->first()
            ?? throw new ApiException('not_found', 'No contact with that email.', 404);
    }

    private function workspace(Request $request): Workspace
    {
        /** @var Workspace $w */
        $w = $request->attributes->get('workspace');

        return $w;
    }
}
