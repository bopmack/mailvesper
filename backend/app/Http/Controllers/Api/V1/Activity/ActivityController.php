<?php

namespace App\Http\Controllers\Api\V1\Activity;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\Workspace;
use App\Services\Activity\Signals;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The Activity page: every email, where it got to, and its full timeline. */
class ActivityController extends Controller
{
    public const STATUSES = ['processing', 'sent', 'delivered', 'opened', 'clicked', 'deferred', 'bounced', 'complained', 'suppressed', 'failed'];

    public const PER_PAGE = 50;

    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        $f = $this->filters($request);
        $rows = $this->query($workspace, $f)->when($f['before'] ?? null, fn ($q, $b) => $q->where('id', '<', $b))
            ->orderByDesc('id')->limit(self::PER_PAGE + 1)->get();
        $more = $rows->count() > self::PER_PAGE;
        $rows = $rows->take(self::PER_PAGE);

        $counts = $this->query($workspace, ['status' => null] + $f)->toBase()
            ->selectRaw(self::statusCase().' as s, count(*) as n')->groupBy('s')->pluck('n', 's');

        return response()->json([
            'messages' => $rows->map(fn (Message $m) => self::row($m))->values(),
            'next_before' => $more ? $rows->last()?->id : null,
            'counts' => collect(self::STATUSES)->mapWithKeys(fn ($s) => [$s => (int) ($counts[$s] ?? 0)]),
        ]);
    }

    public function show(Workspace $workspace, string $id): JsonResponse
    {
        $m = $this->find($workspace, $id);
        $events = DB::table('message_events')->where('message_id', $m->id)->orderBy('occurred_at')->orderBy('id')
            ->get(['id', 'type', 'machine', 'data', 'occurred_at', 'ses_event_id'])
            ->map(fn ($e) => ['id' => $e->id, 'type' => $e->type, 'machine' => (bool) $e->machine, 'data' => $e->data ? json_decode((string) $e->data, true) : (object) [],
                'at' => self::iso($e->occurred_at), 'raw' => (bool) $e->ses_event_id]);

        return response()->json(self::row($m) + [
            'from' => $m->from_name ? "{$m->from_name} <{$m->from_email}>" : $m->from_email, 'reply_to' => $m->reply_to, 'tags' => $m->tags,
            'headers' => $m->headers, 'ses_message_id' => $m->ses_message_id, 'error' => $m->error, 'attempts' => $m->attempts,
            'html' => $m->html, 'text' => $m->text, 'events' => $events,
            'counts' => ['opens' => $m->open_count, 'machine_opens' => $m->machine_open_count, 'clicks' => $m->click_count],
        ]);
    }

    /** The untouched SES events for one message, for developers. */
    public function raw(Workspace $workspace, string $id): JsonResponse
    {
        $m = $this->find($workspace, $id);
        $raw = DB::table('message_events')->join('ses_events', 'ses_events.id', '=', 'message_events.ses_event_id')
            ->where('message_events.message_id', $m->id)->orderBy('message_events.occurred_at')->pluck('ses_events.payload')
            ->map(fn ($p) => json_decode((string) $p, true));

        return response()->json(['events' => $raw]);
    }

    public function export(Request $request, Workspace $workspace): StreamedResponse
    {
        $f = $this->filters($request);
        $query = $this->query($workspace, $f)->orderByDesc('id')->limit(50000);

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['tracking_code', 'status', 'to', 'provider', 'from', 'subject', 'type', 'source', 'created_at', 'sent_at', 'delivered_at', 'opened_at', 'clicked_at', 'opens', 'machine_opens', 'clicks', 'error']);
            $query->chunk(1000, function ($rows) use ($out) {
                foreach ($rows as $m) {
                    /** @var Message $m */
                    fputcsv($out, [$m->tracking_code, self::display($m), $m->to_email, $m->provider, $m->from_email, $m->subject, $m->type, $m->source,
                        $m->created_at?->toIso8601ZuluString(), $m->sent_at?->toIso8601ZuluString(), $m->delivered_at?->toIso8601ZuluString(),
                        $m->opened_at?->toIso8601ZuluString(), $m->clicked_at?->toIso8601ZuluString(), $m->open_count, $m->machine_open_count, $m->click_count, $m->error]);
                }
            });
            fclose($out);
        }, 'mailvesper-activity-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array<string, mixed> */
    public static function row(Message $m): array
    {
        return [
            'id' => $m->id, 'tracking_code' => $m->tracking_code, 'status' => self::display($m), 'raw_status' => $m->status, 'via' => self::via($m),
            'to' => $m->to_email, 'provider' => $m->provider ?? Signals::providerFromDomain($m->to_email), 'subject' => $m->subject,
            'type' => $m->type, 'source' => $m->source, 'machine_open' => $m->machine_open_count > 0 && ! $m->opened_at,
            'opens' => $m->open_count, 'clicks' => $m->click_count, 'created_at' => $m->created_at?->toIso8601ZuluString(),
        ];
    }

    /** One status per email, as Activity shows it: how far along it got. */
    /** How it went out: our own Message-ID (with an @) for SMTP, SES's message id otherwise. */
    public static function via(Message $m): ?string
    {
        $id = (string) $m->ses_message_id;

        return $id === '' ? null : (str_contains($id, '@') || ! preg_match('/^[0-9a-f]{16}/', $id) ? 'smtp' : 'ses');
    }

    public static function display(Message $m): string
    {
        return match ($m->status) {
            'queued', 'sending' => 'processing',
            // Handed over; SES then reports delivery, SMTP never does. Opens and clicks still count.
            'sent' => $m->clicked_at ? 'clicked' : ($m->opened_at ? 'opened' : 'sent'),
            'delayed' => 'deferred',
            'rejected', 'failed' => 'failed',
            'delivered' => $m->clicked_at ? 'clicked' : ($m->opened_at ? 'opened' : 'delivered'),
            default => $m->status,
        };
    }

    private static function statusCase(): string
    {
        return "CASE WHEN status IN ('queued','sending') THEN 'processing' WHEN status = 'delayed' THEN 'deferred' WHEN status IN ('rejected','failed') THEN 'failed'
            WHEN status IN ('delivered','sent') AND clicked_at IS NOT NULL THEN 'clicked' WHEN status IN ('delivered','sent') AND opened_at IS NOT NULL THEN 'opened' ELSE status END";
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        return $request->validate([
            'status' => ['nullable', Rule::in(self::STATUSES)], 'q' => ['nullable', 'string', 'max:254'], 'provider' => ['nullable', Rule::in(array_keys(Signals::PROVIDERS))],
            'type' => ['nullable', Rule::in(['transactional', 'marketing'])], 'source' => ['nullable', Rule::in(['api', 'test', 'campaign'])],
            'days' => ['nullable', 'integer', Rule::in([1, 7, 30, 90])], 'before' => ['nullable', 'string', 'size:26'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $f
     * @return Builder<Message>
     */
    private function query(Workspace $workspace, array $f): Builder
    {
        $q = Message::where('workspace_id', $workspace->id)->where('created_at', '>=', now()->subDays((int) ($f['days'] ?? 7)));
        if ($s = $f['status'] ?? null) {
            $q->whereRaw(self::statusCase().' = ?', [$s]);
        }
        if ($text = trim((string) ($f['q'] ?? ''))) {
            $like = '%'.addcslashes(mb_strtolower($text), '%_\\').'%';
            preg_match('/^SL-[A-Z0-9]{4}-[A-Z0-9]{4}$/i', $text)
                ? $q->where('tracking_code', strtoupper($text))
                : $q->where(fn ($w) => $w->where('to_email', 'like', $like)->orWhere('subject', 'like', $like)->orWhere('ses_message_id', $text));
        }
        foreach (['provider', 'type', 'source'] as $col) {
            if ($v = $f[$col] ?? null) {
                $q->where($col, $v);
            }
        }

        return $q;
    }

    private function find(Workspace $workspace, string $id): Message
    {
        return Message::where('workspace_id', $workspace->id)->where(fn ($q) => $q->where('id', $id)->orWhere('tracking_code', strtoupper($id)))->first()
            ?? throw new ApiException('not_found', 'No email with that id.', 404);
    }

    private static function iso(mixed $dt): string
    {
        return Carbon::parse((string) $dt, 'UTC')->toIso8601ZuluString();
    }
}
