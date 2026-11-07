<?php

namespace App\Http\Controllers\Api\V1\Contacts;

use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\Activity\ActivityController;
use App\Http\Controllers\Concerns\WorkspaceAccess;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\ContactList;
use App\Models\Message;
use App\Models\Segment;
use App\Models\Suppression;
use App\Models\Topic;
use App\Models\Workspace;
use App\Services\Audit;
use App\Services\Contacts\ContactService;
use App\Services\Contacts\SegmentQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The Contacts page: list, filter, view, edit, export and erase. */
class ContactController extends Controller
{
    use WorkspaceAccess;

    public const PER_PAGE = 50;

    public function __construct(private ContactService $contacts) {}

    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        $q = $this->filtered($request, $workspace);
        $total = (clone $q)->count();
        $rows = $q->when($request->input('before'), fn ($x, $b) => $x->where('id', '<', $b))->orderByDesc('id')->limit(self::PER_PAGE + 1)->get();
        $more = $rows->count() > self::PER_PAGE;
        $rows = $rows->take(self::PER_PAGE);
        $tags = DB::table('contact_tags')->whereIn('contact_id', $rows->pluck('id'))->get()->groupBy('contact_id');

        return response()->json([
            'contacts' => $rows->map(fn (Contact $c) => ContactService::present($c) + ['tags' => ($tags[$c->id] ?? collect())->pluck('tag')->values()])->values(),
            'total' => $total, 'next_before' => $more ? $rows->last()?->id : null,
        ]);
    }

    public function summary(Workspace $workspace): JsonResponse
    {
        $base = fn () => Contact::where('workspace_id', $workspace->id);

        return response()->json([
            'total' => $base()->count(), 'subscribed' => $base()->where('status', 'subscribed')->count(),
            'engaged_30d' => $base()->where(fn ($w) => $w->where('last_opened_at', '>=', now()->subDays(30))->orWhere('last_clicked_at', '>=', now()->subDays(30)))->count(),
            'not_subscribed' => $base()->where('status', '!=', 'subscribed')->count(), 'new_7d' => $base()->where('created_at', '>=', now()->subDays(7))->count(),
            'dormant' => $base()->where('dormant', true)->where('status', 'subscribed')->count(),
            'tags' => DB::table('contact_tags')->where('workspace_id', $workspace->id)->selectRaw('tag, count(*) as n')->groupBy('tag')->orderByDesc('n')->limit(100)->pluck('n', 'tag'),
        ]);
    }

    public function show(Workspace $workspace, string $id): JsonResponse
    {
        $c = $this->find($workspace, $id);
        $messages = Message::where('workspace_id', $workspace->id)->where('to_email', $c->email)->orderByDesc('id')->limit(25)->get();
        $stats = DB::table('messages')->where('workspace_id', $workspace->id)->where('to_email', $c->email)
            ->selectRaw("sum(case when status not in ('queued','sending','suppressed','failed') then 1 else 0 end) as sent, sum(case when opened_at is not null then 1 else 0 end) as opened, sum(case when clicked_at is not null then 1 else 0 end) as clicked")->first();
        $mine = DB::table('contact_topic')->where('contact_id', $c->id)->pluck('subscribed', 'topic_id');

        return response()->json(['contact' => ContactService::present($c) + [
            'tags' => DB::table('contact_tags')->where('contact_id', $c->id)->orderBy('tag')->pluck('tag'),
            'lists' => $c->lists()->orderBy('name')->get(['lists.id', 'lists.name']),
            'topics' => Topic::where('workspace_id', $workspace->id)->orderBy('name')->get()->map(fn (Topic $t) => ['id' => $t->id, 'name' => $t->name, 'subscribed' => (bool) ($mine[$t->id] ?? $t->default_subscribed)]),
            'suppression' => DB::table('suppressions')->where('workspace_id', $workspace->id)->where('email', $c->email)->first(['reason', 'detail', 'created_at']),
            'stats' => ['sent' => (int) ($stats->sent ?? 0), 'opened' => (int) ($stats->opened ?? 0), 'clicked' => (int) ($stats->clicked ?? 0)],
            'messages' => $messages->map(fn (Message $m) => ActivityController::row($m))->values(),
        ]]);
    }

    public function store(Request $request, Workspace $workspace): JsonResponse
    {
        $this->requireEditor($request, 'add contacts');
        $data = $request->only(['email', 'first_name', 'last_name', 'fields', 'tags', 'lists']);
        if (Contact::where('workspace_id', $workspace->id)->where('email', ContactService::normaliseEmail((string) ($data['email'] ?? '')))->exists()) {
            throw new ApiException('contact_exists', 'This address is already a contact.', 422, ['email' => 'This address is already a contact.']);
        }
        $c = $this->contacts->upsert($workspace, $data, 'manual');

        return response()->json(['contact' => ContactService::present($c)], 201);
    }

    public function update(Request $request, Workspace $workspace, string $id): JsonResponse
    {
        $this->requireEditor($request, 'edit contacts');
        $c = $this->find($workspace, $id);
        $data = $request->only(['first_name', 'last_name', 'fields']) + ['email' => $c->email];
        $this->contacts->upsert($workspace, $data, 'manual');
        if ($add = $request->input('add_tags')) {
            $errs = $this->contacts->errors($workspace, ['email' => $c->email, 'tags' => $add]);
            $errs ? throw new ApiException('validation_failed', reset($errs), 422, ['add_tags' => reset($errs)]) : $this->contacts->tag($c, (array) $add);
        }
        if ($remove = $request->input('remove_tags')) {
            $this->contacts->untag($c, array_filter((array) $remove, 'is_string'));
        }
        if ($add = $request->input('add_lists')) {
            $errs = $this->contacts->errors($workspace, ['email' => $c->email, 'lists' => $add]);
            $errs ? throw new ApiException('validation_failed', reset($errs), 422, ['add_lists' => reset($errs)]) : $this->contacts->addToLists($c, (array) $add);
        }
        if ($remove = $request->input('remove_lists')) {
            DB::table('contact_list')->where('contact_id', $c->id)->whereIn('list_id', array_filter((array) $remove, 'is_string'))->delete();
        }
        if (is_array($topics = $request->input('topics'))) {
            $this->contacts->setTopics($c, array_map(fn ($on) => (bool) $on, array_filter($topics, 'is_scalar')));
        }
        if ($request->has('subscribed')) {
            $this->setSubscribed($request, $workspace, $c, $request->boolean('subscribed'));
        }

        return $this->show($workspace, $c->id);
    }

    public function destroy(Request $request, Workspace $workspace, string $id): JsonResponse
    {
        $this->requireManager($request, 'erase contacts');
        $c = $this->find($workspace, $id);
        $this->contacts->erase($c);
        Audit::log('contact.erased', $this->currentUser($request), $workspace->id, ['email' => $c->email]);

        return response()->json(['erased' => true]);
    }

    /** GDPR access request: everything we hold about one person. */
    public function exportOne(Request $request, Workspace $workspace, string $id): JsonResponse
    {
        $this->requireManager($request, 'export personal data');
        $c = $this->find($workspace, $id);
        Audit::log('contact.exported', $this->currentUser($request), $workspace->id, ['email' => $c->email]);

        return response()->json($this->contacts->export($c))->header('Content-Disposition', 'attachment; filename="contact-'.$c->id.'.json"');
    }

    public function export(Request $request, Workspace $workspace): StreamedResponse
    {
        $this->requireManager($request, 'export contacts');
        Audit::log('contacts.exported', $this->currentUser($request), $workspace->id, []);
        $q = $this->filtered($request, $workspace)->orderBy('id');
        $keys = DB::table('contact_fields')->where('workspace_id', $workspace->id)->orderBy('key')->pluck('key')->all();
        $lists = ContactList::where('workspace_id', $workspace->id)->pluck('name', 'id');

        return response()->streamDownload(function () use ($q, $keys, $lists) {
            $out = fopen('php://output', 'w');
            fputcsv($out, array_merge(['email', 'first_name', 'last_name', 'status', 'source', 'engagement', 'dormant', 'created_at', 'last_opened_at', 'tags', 'lists'], $keys));
            $q->chunkById(1000, function ($rows) use ($out, $keys, $lists) {
                $ids = $rows->pluck('id');
                $tags = DB::table('contact_tags')->whereIn('contact_id', $ids)->get()->groupBy('contact_id');
                $member = DB::table('contact_list')->whereIn('contact_id', $ids)->get()->groupBy('contact_id');
                foreach ($rows as $c) {
                    /** @var Contact $c */
                    $f = $c->fields ?? [];
                    fputcsv($out, array_merge([$c->email, $c->first_name, $c->last_name, $c->status, $c->source, $c->engagement, $c->dormant ? 'yes' : 'no',
                        $c->created_at?->toIso8601ZuluString(), $c->last_opened_at?->toIso8601ZuluString(),
                        ($tags[$c->id] ?? collect())->pluck('tag')->implode('; '), ($member[$c->id] ?? collect())->map(fn ($m) => $lists[$m->list_id] ?? '')->implode('; ')],
                        array_map(fn ($k) => is_bool($f[$k] ?? null) ? ($f[$k] ? 'true' : 'false') : ($f[$k] ?? ''), $keys)));
                }
            });
            fclose($out);
        }, 'mailvesper-contacts-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function setSubscribed(Request $request, Workspace $workspace, Contact $c, bool $on): void
    {
        if (! $on) {
            Suppression::add($workspace->id, $c->email, 'unsubscribe', 'Unsubscribed by '.$this->currentUser($request)->email);

            return;
        }
        $reason = DB::table('suppressions')->where('workspace_id', $workspace->id)->where('email', $c->email)->value('reason');
        if (in_array($reason, ['hard_bounce', 'complaint'], true)) {
            $this->requireManager($request, 'resubscribe a contact who bounced or reported spam');
        }
        Suppression::remove($workspace->id, $c->email);
        Audit::log('contact.resubscribed', $this->currentUser($request), $workspace->id, ['email' => $c->email, 'previous' => $reason]);
    }

    /** @return Builder<Contact> */
    private function filtered(Request $request, Workspace $workspace): Builder
    {
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:254'], 'status' => ['nullable', Rule::in(Contact::STATUSES)], 'list' => ['nullable', 'string', 'size:26'],
            'tag' => ['nullable', 'string', 'max:60'], 'segment' => ['nullable', 'string', 'size:26'], 'dormant' => ['nullable', 'boolean'],
        ]);
        $q = Contact::where('workspace_id', $workspace->id);
        if ($text = trim((string) ($f['q'] ?? ''))) {
            $like = '%'.addcslashes(mb_strtolower($text), '%_\\').'%';
            $q->where(fn ($w) => $w->where('email', 'like', $like)->orWhere('first_name', 'like', $like)->orWhere('last_name', 'like', $like));
        }
        foreach (['status' => 'status'] as $k => $col) {
            if ($v = $f[$k] ?? null) {
                $q->where($col, $v);
            }
        }
        if (isset($f['dormant'])) {
            $q->where('dormant', (bool) $f['dormant']);
        }
        if ($list = $f['list'] ?? null) {
            $q->whereExists(fn ($s) => $s->select(DB::raw(1))->from('contact_list')->whereColumn('contact_list.contact_id', 'contacts.id')->where('list_id', $list));
        }
        if ($tag = $f['tag'] ?? null) {
            $q->whereExists(fn ($s) => $s->select(DB::raw(1))->from('contact_tags')->whereColumn('contact_tags.contact_id', 'contacts.id')->where('tag', mb_strtolower($tag)));
        }
        if ($segId = $f['segment'] ?? null) {
            $seg = Segment::where('workspace_id', $workspace->id)->find($segId) ?? throw new ApiException('not_found', 'Segment not found.', 404);
            if ($seg->site_connection_id) {
                throw new ApiException('live_segment', 'That segment reads a connected site’s people live; open it from Contacts → the site’s People.', 422);
            }
            (new SegmentQuery(SegmentController::customTypes($workspace->id)))->apply($q, $seg->rules);
        }

        return $q;
    }

    private function find(Workspace $workspace, string $id): Contact
    {
        return Contact::where('workspace_id', $workspace->id)->where(fn ($q) => $q->where('id', $id)->orWhere('email', ContactService::normaliseEmail($id)))->first()
            ?? throw new ApiException('not_found', 'Contact not found.', 404);
    }
}
