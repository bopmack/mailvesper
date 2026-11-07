<?php

namespace App\Http\Controllers\Api\V1\Campaigns;

use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\Activity\ActivityController;
use App\Http\Controllers\Api\V1\Sending\EmailController;
use App\Http\Controllers\Concerns\Paginates;
use App\Http\Controllers\Concerns\WorkspaceAccess;
use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\ContactList;
use App\Models\EmailTemplate;
use App\Models\Message;
use App\Models\Segment;
use App\Models\Topic;
use App\Models\Workspace;
use App\Services\Audit;
use App\Services\Campaigns\CampaignReport;
use App\Services\Campaigns\CampaignService;
use App\Services\Connect\SiteAudience;
use App\Services\Contacts\ContactService;
use App\Services\Sending\Compose;
use App\Services\Sending\Mailer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Campaigns: drafts, scheduling, pause/resume/cancel, reports. */
class CampaignController extends Controller
{
    use Paginates;
    use WorkspaceAccess;

    /** People picked one by one; more than this is a list's job. */
    public const MAX_PEOPLE = 500;

    public function __construct(private CampaignService $service, private ContactService $contacts) {}

    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        $status = $request->input('status');
        $search = trim((string) $request->input('q', ''));
        $query = Campaign::where('workspace_id', $workspace->id)->when($status, fn ($q, $s) => $q->whereIn('status', (array) explode(',', (string) $s)))
            ->when($search !== '', fn ($q) => $q->where('name', 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->orderByRaw("CASE status WHEN 'sending' THEN 0 WHEN 'paused' THEN 1 WHEN 'scheduled' THEN 2 WHEN 'draft' THEN 3 ELSE 4 END")->orderByDesc('updated_at')->orderByDesc('id');
        [$rows, $meta] = $this->paged($request) ? $this->page($request, $query) : [$query->limit(200)->get(), null];
        $stats = DB::table('messages')->whereIn('campaign_id', $rows->pluck('id'))->whereNotNull('ses_message_id')
            ->selectRaw('campaign_id, count(*) as sent, sum(case when delivered_at is not null then 1 else 0 end) as delivered, sum(case when opened_at is not null then 1 else 0 end) as opened, sum(case when clicked_at is not null then 1 else 0 end) as clicked')
            ->groupBy('campaign_id')->get()->keyBy('campaign_id');

        return response()->json(['campaigns' => $rows->map(fn (Campaign $c) => $this->summary($c) + ['stats' => isset($stats[$c->id]) ? [
            'sent' => (int) $stats[$c->id]->sent, 'delivered' => (int) $stats[$c->id]->delivered, 'opened' => (int) $stats[$c->id]->opened, 'clicked' => (int) $stats[$c->id]->clicked,
        ] : null])->values()] + ($meta ? ['meta' => $meta] : []));
    }

    public function show(Workspace $workspace, string $id): JsonResponse
    {
        $c = $this->find($workspace, $id);

        return response()->json(['campaign' => $this->summary($c) + [
            'doc' => $c->doc, 'html' => $c->html, 'size_kb' => $c->size_kb, 'checks' => $c->checks, 'estimate' => $this->estimateOrWhy($c), 'problems' => $this->service->problems($c),
        ]]);
    }

    public function store(Request $request, Workspace $workspace): JsonResponse
    {
        $this->requireEditor($request, 'create campaigns');
        $data = $request->validate(['name' => ['nullable', 'string', 'max:120'], 'template_id' => ['nullable', 'string', 'size:26']]);
        $c = new Campaign(['workspace_id' => $workspace->id, 'name' => trim($data['name'] ?? '') ?: 'Untitled campaign', 'created_by' => $this->currentUser($request)->id]);
        if (! empty($data['template_id'])) {
            $t = EmailTemplate::where('workspace_id', $workspace->id)->find($data['template_id']) ?? throw new ApiException('not_found', 'Template not found.', 404);
            $c->fill(['subject' => $t->subject, 'doc' => $t->doc, 'html' => $t->html, 'size_kb' => $t->size_kb, 'checks' => $t->checks, 'preheader' => $t->doc['settings']['preheader'] ?? null]);
        }
        $c->save();

        return response()->json(['campaign' => $this->summary($c)], 201);
    }

    public function update(Request $request, Workspace $workspace, string $id): JsonResponse
    {
        $this->requireEditor($request, 'edit campaigns');
        $c = $this->find($workspace, $id);
        if (! in_array($c->status, Campaign::EDITABLE, true)) {
            throw new ApiException('not_editable', 'This campaign has started, so it can’t be edited. Duplicate it to make changes.', 422);
        }
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'], 'audience_type' => ['sometimes', 'nullable', Rule::in(['all', 'list', 'segment', 'people'])], 'audience_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'people' => ['sometimes', 'array', 'max:'.self::MAX_PEOPLE], 'people.*' => ['string', 'max:254'],
            'exclude_dormant' => ['sometimes', 'boolean'], 'topic_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'from_email' => ['sometimes', 'nullable', 'email', 'max:254'], 'from_name' => ['sometimes', 'nullable', 'string', 'max:120'], 'reply_to' => ['sometimes', 'nullable', 'email', 'max:254'],
            'subject' => ['sometimes', 'nullable', 'string', 'max:255'], 'subject_b' => ['sometimes', 'nullable', 'string', 'max:255'], 'preheader' => ['sometimes', 'nullable', 'string', 'max:300'],
            'ab_enabled' => ['sometimes', 'boolean'], 'ab_test_pct' => ['sometimes', 'integer', 'min:10', 'max:50'], 'ab_metric' => ['sometimes', Rule::in(['opens', 'clicks'])], 'ab_wait_hours' => ['sometimes', 'integer', 'min:1', 'max:48'],
            'doc' => ['sometimes', 'nullable'],
        ]);
        if (array_key_exists('people', $data)) {
            $data['audience_contacts'] = $this->people($workspace, $data['people']);
            unset($data['people']);
            $data['audience_type'] ??= 'people';
        }
        if (array_key_exists('audience_type', $data)) {
            $type = $data['audience_type'];
            $aid = $data['audience_id'] ?? null;
            $ok = $type === null || $type === 'all' || $type === 'people' || ($type === 'list' && ContactList::where('workspace_id', $workspace->id)->where('id', $aid)->exists())
                || ($type === 'segment' && Segment::where('workspace_id', $workspace->id)->where('id', $aid)->exists());
            if (! $ok) {
                throw new ApiException('validation_failed', 'That list or segment doesn’t exist.', 422, ['audience_id' => 'Not found.']);
            }
            $data['audience_id'] = in_array($type, ['all', 'people'], true) ? null : $aid;
        }
        if (! empty($data['topic_id']) && ! Topic::where('workspace_id', $workspace->id)->where('id', $data['topic_id'])->exists()) {
            throw new ApiException('validation_failed', 'That topic doesn’t exist.', 422, ['topic_id' => 'Not found.']);
        }
        if (! empty($data['from_email']) && ! Compose::parseAddress($data['from_email'])) {
            throw new ApiException('validation_failed', 'Enter a valid sender address.', 422, ['from_email' => 'Invalid address.']);
        }
        $doc = array_key_exists('doc', $data) ? $data['doc'] : null;
        unset($data['doc']);
        // A design edited in the builder carries its own preview text; it wins unless this request sets one too.
        if (is_array($doc) && ! array_key_exists('preheader', $data) && is_string($doc['settings']['preheader'] ?? null) && trim($doc['settings']['preheader']) !== '') {
            $data['preheader'] = mb_substr(trim($doc['settings']['preheader']), 0, 300);
        }
        $c->fill($data);
        if ($doc !== null) {
            $this->service->renderDoc($c, $doc);
        } elseif ((isset($data['preheader']) || isset($data['subject'])) && $c->doc) {
            $this->service->renderDoc($c, $c->doc); // preview text lives in the design
        } elseif (array_key_exists('doc', $request->all()) && $request->input('doc') === null) {
            $c->fill(['doc' => null, 'html' => null, 'size_kb' => null, 'checks' => null]);
        }
        $c->save();

        return $this->show($workspace, $c->id);
    }

    public function duplicate(Request $request, Workspace $workspace, string $id): JsonResponse
    {
        $this->requireEditor($request, 'create campaigns');
        $src = $this->find($workspace, $id);
        $c = $src->replicate(['status', 'status_note', 'recipients_count', 'share_token', 'started_at', 'finished_at', 'paused_at', 'scheduled_at', 'ab_winner', 'ab_decided_at', 'send_mode', 'local_date', 'local_time']);
        $c->fill(['name' => mb_substr($src->name.' (copy)', 0, 120), 'status' => 'draft', 'created_by' => $this->currentUser($request)->id]);
        $c->save();

        return response()->json(['campaign' => $this->summary($c)], 201);
    }

    public function destroy(Request $request, Workspace $workspace, string $id): JsonResponse
    {
        $this->requireEditor($request, 'delete campaigns');
        $c = $this->find($workspace, $id);
        if (in_array($c->status, ['sending', 'paused', 'scheduled'], true)) {
            throw new ApiException('in_progress', 'Cancel the campaign before deleting it.', 422);
        }
        $c->delete(); // sent emails stay in Activity
        Audit::log('campaign.deleted', $this->currentUser($request), $workspace->id, ['name' => $c->name]);

        return response()->json(['deleted' => true]);
    }

    public function send(Request $request, Workspace $workspace, string $id): JsonResponse
    {
        $this->requireEditor($request, 'send campaigns');
        $c = $this->service->schedule($this->find($workspace, $id), $request->only(['mode', 'at', 'date', 'time', 'timezone']) + ['mode' => '']);
        Audit::log($c->status === 'sending' ? 'campaign.sent' : 'campaign.scheduled', $this->currentUser($request), $workspace->id, ['name' => $c->name, 'at' => $c->scheduled_at?->toIso8601ZuluString()]);

        return $this->show($workspace, $c->id);
    }

    public function action(Request $request, Workspace $workspace, string $id, string $action): JsonResponse
    {
        $this->requireEditor($request, 'control campaigns');
        $c = $this->find($workspace, $id);
        match ($action) {
            'pause' => $this->service->pause($c),
            'resume' => $this->service->resume($c),
            'cancel' => $this->service->cancel($c),
            'unschedule' => $this->service->unschedule($c),
            default => throw new ApiException('not_found', 'Unknown action.', 404),
        };
        Audit::log('campaign.'.$action, $this->currentUser($request), $workspace->id, ['name' => $c->name]);

        return $this->show($workspace, $c->id);
    }

    /** Sends the current draft to one address (subject A, or B when asked). */
    public function test(Request $request, Workspace $workspace, string $id, Mailer $mailer): JsonResponse
    {
        $this->requireEditor($request, 'send test emails');
        $c = $this->find($workspace, $id);
        if (! $c->html) {
            throw new ApiException('not_ready', 'Design the email first.', 422);
        }
        $subject = $request->input('variant') === 'b' && $c->subject_b ? $c->subject_b : ($c->subject ?: $c->name);
        $from = $request->input('from') ?: ($c->from_email ? ($c->from_name ? "{$c->from_name} <{$c->from_email}>" : $c->from_email) : null);
        $p = $mailer->validate($workspace, ['from' => $from, 'to' => $request->input('to'), 'subject' => '[Test] '.$subject, 'html' => $c->html, 'type' => 'marketing']);
        if (count($p['to']) !== 1) {
            throw new ApiException('validation_failed', 'Send a test to one address at a time.', 422, ['to' => 'One address only.']);
        }

        return response()->json(EmailController::present($mailer->submit($workspace, [$p], 'test')[0]), 202);
    }

    public function report(Workspace $workspace, string $id, CampaignReport $report): JsonResponse
    {
        $c = $this->find($workspace, $id);

        return response()->json(['campaign' => $this->summary($c), 'report' => $report->build($c)]);
    }

    public function recipients(Request $request, Workspace $workspace, string $id): JsonResponse
    {
        $c = $this->find($workspace, $id);
        $f = $request->validate(['filter' => ['nullable', Rule::in(['opened', 'clicked', 'bounced', 'complained', 'unsubscribed', 'not_opened', 'skipped', 'pending'])], 'before' => ['nullable', 'integer'], 'q' => ['nullable', 'string', 'max:254']]);
        $q = DB::table('campaign_recipients as r')->leftJoin('messages as m', 'm.id', '=', 'r.message_id')->where('r.campaign_id', $c->id)
            ->when($f['q'] ?? null, fn ($x, $s) => $x->where('r.email', 'like', '%'.addcslashes(mb_strtolower($s), '%_\\').'%'))
            ->when($f['before'] ?? null, fn ($x, $b) => $x->where('r.id', '<', $b));
        match ($f['filter'] ?? null) {
            'opened' => $q->whereNotNull('m.opened_at'), 'clicked' => $q->whereNotNull('m.clicked_at'), 'bounced' => $q->where('m.status', 'bounced'),
            'complained' => $q->where('m.status', 'complained'), 'not_opened' => $q->whereNotNull('m.delivered_at')->whereNull('m.opened_at'),
            'skipped' => $q->where('r.status', 'skipped'), 'pending' => $q->where('r.status', 'pending'),
            'unsubscribed' => $q->whereExists(fn ($s) => $s->select(DB::raw(1))->from('message_events')->whereColumn('message_events.message_id', 'm.id')->where('message_events.type', 'unsubscribed')),
            default => null,
        };
        $rows = $q->orderByDesc('r.id')->limit(101)->get(['r.id', 'r.email', 'r.variant', 'r.status as r_status', 'r.skip_reason', 'r.message_id']);
        $msgs = Message::whereIn('id', $rows->pluck('message_id')->filter())->get()->keyBy('id');

        return response()->json(['recipients' => $rows->take(100)->map(fn ($r) => ['id' => $r->id, 'email' => $r->email, 'variant' => $r->variant, 'status' => $r->r_status, 'skip_reason' => $r->skip_reason,
            'message' => isset($msgs[$r->message_id]) ? ActivityController::row($msgs[$r->message_id]) : null])->values(), 'next_before' => $rows->count() > 100 ? $rows[99]->id : null]);
    }

    /** Turns the public read-only report link on or off. */
    public function share(Request $request, Workspace $workspace, string $id): JsonResponse
    {
        $this->requireEditor($request, 'share reports');
        $c = $this->find($workspace, $id);
        $c->forceFill(['share_token' => $request->boolean('enabled') ? ($c->share_token ?: Str::random(32)) : null])->save();

        return response()->json(['share_url' => $c->share_token ? rtrim((string) config('app.frontend_url'), '/').'/r/?t='.$c->share_token : null]);
    }

    /** Lists and segments with how many subscribed people each would reach. */
    public function audiences(Workspace $workspace): JsonResponse
    {
        $probe = new Campaign(['workspace_id' => $workspace->id, 'exclude_dormant' => false]);
        $count = function (string $type, ?string $id) use ($probe) {
            $probe->audience_type = $type;
            $probe->audience_id = $id;

            return $this->service->estimate($probe);
        };

        return response()->json([
            'all' => $count('all', null),
            'lists' => ContactList::where('workspace_id', $workspace->id)->orderBy('name')->get()->map(fn ($l) => ['id' => $l->id, 'name' => $l->name] + $count('list', $l->id)),
            'segments' => $this->segmentAudiences($workspace, $count),
            'topics' => Topic::where('workspace_id', $workspace->id)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** @return array<string, mixed> the estimate, or why there isn't one (a connected site that didn't answer) */
    private function estimateOrWhy(Campaign $c): array
    {
        try {
            return $this->service->estimate($c);
        } catch (ApiException $e) {
            return ['total' => null, 'dormant' => 0, 'error' => $e->getMessage()];
        }
    }

    /**
     * Segments with how many would get the campaign. A connected site's segments are counted live, one request per
     * site, counting only people who agreed to marketing; a site that doesn't answer shows no count and why.
     *
     * @param  callable(string, ?string): array{total: int, dormant: int}  $count
     * @return list<array<string, mixed>>
     */
    private function segmentAudiences(Workspace $workspace, callable $count): array
    {
        $segments = Segment::where('workspace_id', $workspace->id)->orderBy('name')->get();
        $live = [];
        foreach ($segments->whereNotNull('site_connection_id')->groupBy('site_connection_id') as $group) {
            try {
                $site = SiteAudience::forSegment($group->first());
                $live += array_map(fn ($n) => ['total' => $n, 'dormant' => 0], SiteAudience::counts($site, $group->mapWithKeys(fn (Segment $s) => [$s->id => $s->rules])->all(), true));
            } catch (ApiException $e) {
                foreach ($group as $s) {
                    $live[$s->id] = ['total' => null, 'dormant' => 0, 'error' => $e->getMessage()];
                }
            }
        }

        return $segments->map(fn (Segment $s) => ['id' => $s->id, 'name' => $s->name, 'group' => $s->group, 'live' => $s->site_connection_id !== null]
            + ($s->site_connection_id ? ($live[$s->id] ?? ['total' => 0, 'dormant' => 0]) : $count('segment', $s->id)))->values()->all();
    }

    /** @return array<string, mixed> */
    private function summary(Campaign $c): array
    {
        return [
            'id' => $c->id, 'name' => $c->name, 'status' => $c->status, 'status_note' => $c->status_note, 'audience_type' => $c->audience_type, 'audience_id' => $c->audience_id, 'audience_name' => $this->audienceName($c),
            'people' => $c->audience_type === 'people' ? $this->peopleOf($c) : [],
            'exclude_dormant' => $c->exclude_dormant, 'topic_id' => $c->topic_id, 'from_email' => $c->from_email, 'from_name' => $c->from_name, 'reply_to' => $c->reply_to,
            'subject' => $c->subject, 'subject_b' => $c->subject_b, 'preheader' => $c->preheader,
            'ab' => ['enabled' => $c->ab_enabled, 'test_pct' => $c->ab_test_pct, 'metric' => $c->ab_metric, 'wait_hours' => $c->ab_wait_hours, 'winner' => $c->ab_winner, 'decided_at' => $c->ab_decided_at?->toIso8601ZuluString()],
            'send_mode' => $c->send_mode, 'scheduled_at' => $c->scheduled_at?->toIso8601ZuluString(), 'local_date' => $c->local_date?->toDateString(), 'local_time' => $c->local_time, 'timezone' => $c->timezone,
            'recipients_count' => $c->recipients_count, 'has_design' => (bool) $c->html, 'shared' => (bool) $c->share_token,
            'share_url' => $c->share_token ? rtrim((string) config('app.frontend_url'), '/').'/r/?t='.$c->share_token : null,
            'started_at' => $c->started_at?->toIso8601ZuluString(), 'finished_at' => $c->finished_at?->toIso8601ZuluString(), 'updated_at' => $c->updated_at?->toIso8601ZuluString(),
        ];
    }

    private function audienceName(Campaign $c): ?string
    {
        return match ($c->audience_type) {
            'all' => 'All subscribed contacts',
            'list' => ContactList::where('id', $c->audience_id)->value('name'),
            'segment' => Segment::where('id', $c->audience_id)->value('name'),
            'people' => ($n = count($c->audience_contacts ?? [])) === 1 ? '1 person' : "$n people",
            default => null,
        };
    }

    /**
     * Contact ids for people picked one by one: an id of a contact in this workspace, or an email address (a new
     * address becomes a contact, so it gets the usual unsubscribe link and suppression checks).
     *
     * @param  array<int, string>  $picks
     * @return list<string>
     */
    private function people(Workspace $workspace, array $picks): array
    {
        $ids = [];
        foreach ($picks as $pick) {
            $pick = trim($pick);
            if (str_contains($pick, '@')) {
                $email = ContactService::normaliseEmail($pick);
                if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new ApiException('validation_failed', "“{$pick}” isn’t an email address.", 422, ['people' => 'Invalid address.']);
                }
                $ids[] = Contact::where('workspace_id', $workspace->id)->where('email', $email)->value('id')
                    ?? $this->contacts->upsert($workspace, ['email' => $email], 'campaign')->id;
            } else {
                $ids[] = Contact::where('workspace_id', $workspace->id)->where('id', $pick)->value('id')
                    ?? throw new ApiException('validation_failed', 'One of the people picked is no longer a contact.', 422, ['people' => 'Not found.']);
            }
        }

        return array_values(array_unique($ids));
    }

    /** @return list<array{id: string, email: string, name: string, status: string}> */
    private function peopleOf(Campaign $c): array
    {
        $ids = (array) ($c->audience_contacts ?? []);
        $rows = Contact::where('workspace_id', $c->workspace_id)->whereIn('id', $ids)->get(['id', 'email', 'first_name', 'last_name', 'status'])->keyBy('id');

        return array_values(array_filter(array_map(fn ($id) => ($r = $rows[$id] ?? null) ? ['id' => $r->id, 'email' => $r->email,
            'name' => trim($r->first_name.' '.$r->last_name), 'status' => $r->status] : null, $ids)));
    }

    private function find(Workspace $workspace, string $id): Campaign
    {
        return Campaign::where('workspace_id', $workspace->id)->find($id) ?? throw new ApiException('not_found', 'Campaign not found.', 404);
    }
}
