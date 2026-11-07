<?php

namespace App\Http\Controllers\Api\V1\Forms;

use App\Exceptions\ApiException;
use App\Http\Controllers\Concerns\Paginates;
use App\Http\Controllers\Concerns\WorkspaceAccess;
use App\Http\Controllers\Controller;
use App\Models\ContactList;
use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\Workspace;
use App\Services\Contacts\FormBuilder;
use App\Services\Contacts\Forms;
use App\Services\Journeys\JourneyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Signup forms in the app (Forms). A form has a live version (its own columns: what the page, the embed and sign-ups
 * use) and a draft: once a form is published, edits go to the draft until they're published or discarded. A form
 * that was never published is edited in place and shows nothing publicly. The name and open / closed apply at once.
 */
class FormController extends Controller
{
    use Paginates;
    use WorkspaceAccess;

    public function __construct(private Forms $forms) {}

    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        $search = trim((string) $request->input('q', ''));
        $query = Form::where('workspace_id', $workspace->id)->when($search !== '', fn ($q) => $q->where('name', 'like', '%'.addcslashes($search, '%_\\').'%'))->orderBy('name');
        [$rows, $meta] = $this->paged($request) ? $this->page($request, $query) : [$query->get(), null];

        return response()->json(['forms' => $rows->map(fn (Form $f) => self::present($f))] + ($meta ? ['meta' => $meta] : []));
    }

    public function show(Workspace $workspace, string $id): JsonResponse
    {
        $f = $this->find($workspace, $id);
        $recent = FormSubmission::where('form_id', $f->id)->orderByDesc('created_at')->orderByDesc('id')->limit(20)->get(['email', 'status', 'created_at', 'confirmed_at']);

        return response()->json(['form' => self::present($f), 'recent' => $recent->map(fn ($s) => ['email' => $s->email, 'status' => $s->status,
            'created_at' => $s->created_at->toIso8601ZuluString(), 'confirmed_at' => $s->confirmed_at?->toIso8601ZuluString()])]);
    }

    public function store(Request $request, Workspace $workspace): JsonResponse
    {
        $this->requireEditor($request, 'create forms');
        $data = $this->forms->settings($workspace, $request->all());
        $f = Form::create($data + ['workspace_id' => $workspace->id, 'public_key' => Forms::newKey(),
            'fields' => $data['fields'] ?? [['key' => 'first_name', 'label' => 'First name', 'required' => false]]]);
        if ($request->boolean('publish')) {
            $this->publishForm($workspace, $f);
        }

        return response()->json(['form' => self::present($f->refresh())], 201);
    }

    public function update(Request $request, Workspace $workspace, string $id): JsonResponse
    {
        $this->requireEditor($request, 'edit forms');
        $f = $this->find($workspace, $id);
        $changes = $this->forms->settings($workspace, $request->all(), $f->working());
        if ($f->published_at === null) {
            $f->fill($changes);
        } else {
            $f->fill(array_intersect_key($changes, array_flip(Form::IMMEDIATE)));
            $f->draft = self::draftOf($f, array_merge($f->draft ?? [], array_diff_key($changes, array_flip(Form::IMMEDIATE))));
        }
        $f->save();
        if ($request->boolean('publish')) {
            $this->publishForm($workspace, $f);
        }

        return response()->json(['form' => self::present($f)]);
    }

    /** Puts the draft live (and a form that was never published online). */
    public function publish(Request $request, Workspace $workspace, string $id): JsonResponse
    {
        $this->requireEditor($request, 'publish forms');
        $f = $this->find($workspace, $id);
        $this->publishForm($workspace, $f);

        return response()->json(['form' => self::present($f)]);
    }

    /** Drops the unpublished changes: the builder shows the live form again. */
    public function discard(Request $request, Workspace $workspace, string $id): JsonResponse
    {
        $this->requireEditor($request, 'edit forms');
        $f = $this->find($workspace, $id);
        if ($f->published_at === null) {
            throw new ApiException('not_published', 'This form isn’t live yet, so there’s nothing to go back to.', 422);
        }
        $f->forceFill(['draft' => null])->save();

        return response()->json(['form' => self::present($f)]);
    }

    private function publishForm(Workspace $workspace, Form $f): void
    {
        $draft = $f->draft ?? [];
        // A list deleted since the edit: the live form adds to no list rather than failing every sign-up.
        if (! empty($draft['list_id']) && ! ContactList::where('workspace_id', $workspace->id)->whereKey($draft['list_id'])->exists()) {
            $draft['list_id'] = null;
        }
        $f->forceFill($draft + ['draft' => null, 'published_at' => now()])->save();
    }

    /**
     * The draft without the values that are the same as live (so undoing an edit by hand leaves no "changes").
     *
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>|null
     */
    private static function draftOf(Form $f, array $draft): ?array
    {
        $live = fn (string $k) => match ($k) {
            'design' => FormBuilder::design($f->design),
            'display' => FormBuilder::display($f->display),
            default => $f->getAttribute($k),
        };
        $out = array_filter($draft, fn ($v, $k) => $v != $live($k), ARRAY_FILTER_USE_BOTH);

        return $out ?: null;
    }

    public function destroy(Request $request, Workspace $workspace, string $id): JsonResponse
    {
        $this->requireEditor($request, 'delete forms');
        $f = $this->find($workspace, $id);
        JourneyService::guardTriggerUse($workspace->id, 'form_id', $f->id, 'form');
        $f->delete();

        return response()->json(['deleted' => true]);
    }

    private function find(Workspace $workspace, string $id): Form
    {
        return Form::where('workspace_id', $workspace->id)->find($id) ?? throw new ApiException('not_found', 'Form not found.', 404);
    }

    /** @return array<string, mixed> */
    public static function present(Form $f): array
    {
        $site = rtrim((string) config('app.frontend_url'), '/');
        $live = $f;
        $f = $f->working(); // the builder edits the draft

        return $f->only(['id', 'name', 'list_id', 'heading', 'intro', 'button', 'success_message', 'redirect_url', 'double_opt_in', 'from_email', 'from_name', 'enabled', 'submissions', 'confirmed'])
            + ['tags' => $f->tags ?? [], 'fields' => $f->fields ?? [], 'design' => FormBuilder::design($f->design), 'display' => FormBuilder::display($f->display), 'key' => $f->public_key, 'page_url' => "$site/f/?k={$f->public_key}",
                'embed_url' => "$site/f/embed.js", 'submit_url' => rtrim((string) config('app.url'), '/')."/v1/f/{$f->public_key}",
                'state' => $live->state(), 'published_at' => $live->published_at?->toIso8601ZuluString(),
                'created_at' => $f->created_at?->toIso8601ZuluString()];
    }
}
