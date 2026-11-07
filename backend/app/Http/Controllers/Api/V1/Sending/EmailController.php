<?php

namespace App\Http\Controllers\Api\V1\Sending;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\Message;
use App\Models\Workspace;
use App\Services\Sending\Mailer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/** Public send API (API key auth). POST /v1/emails, POST /v1/emails/batch, GET /v1/emails/{id}. */
class EmailController extends Controller
{
    public const BATCH_MAX = 100;

    public function __construct(private Mailer $mailer) {}

    public function store(Request $request): JsonResponse
    {
        return $this->idempotent($request, function () use ($request) {
            $ws = $this->workspace($request);
            $payload = $this->mailer->validate($ws, $request->all());
            $messages = $this->mailer->submit($ws, [$payload], 'api', $this->key($request));

            return [202, self::summary($messages)];
        });
    }

    public function batch(Request $request): JsonResponse
    {
        return $this->idempotent($request, function () use ($request) {
            $items = json_decode($request->getContent(), true);
            if (! is_array($items) || ! array_is_list($items) || ! $items) {
                throw new ApiException('validation_failed', 'Send a JSON array of emails.', 422);
            }
            if (count($items) > self::BATCH_MAX) {
                throw new ApiException('validation_failed', 'Send at most '.self::BATCH_MAX.' emails per batch.', 422);
            }
            $ws = $this->workspace($request);
            $payloads = [];
            foreach ($items as $i => $item) {
                $payloads[] = $this->mailer->validate($ws, is_array($item) ? $item : [], "$i.");
            }
            $total = array_sum(array_map(fn ($p) => count($p['to']), $payloads));
            if ($total > 500) {
                throw new ApiException('validation_failed', 'A batch can reach at most 500 recipients.', 422);
            }

            return [202, self::summary($this->mailer->submit($ws, $payloads, 'api', $this->key($request)))];
        });
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $m = Message::where('workspace_id', $this->workspace($request)->id)->where(fn ($q) => $q->where('id', $id)->orWhere('tracking_code', $id))->first()
            ?? throw new ApiException('not_found', 'No email with that id.', 404);

        return response()->json(self::present($m));
    }

    /**
     * @param  list<Message>  $messages
     * @return array<string, mixed>
     */
    public static function summary(array $messages): array
    {
        return ['batch_id' => $messages[0]->batch_id ?? null, 'data' => array_map(fn (Message $m) => [
            'id' => $m->id, 'tracking_code' => $m->tracking_code, 'to' => $m->to_email, 'status' => $m->status, 'error' => $m->error,
        ], $messages)];
    }

    /** @return array<string, mixed> */
    public static function present(Message $m): array
    {
        return [
            'id' => $m->id, 'tracking_code' => $m->tracking_code, 'batch_id' => $m->batch_id, 'source' => $m->source, 'type' => $m->type,
            'from' => $m->from_name ? "{$m->from_name} <{$m->from_email}>" : $m->from_email, 'to' => $m->to_email, 'subject' => $m->subject,
            'status' => $m->status, 'error' => $m->error, 'tags' => $m->tags, 'ses_message_id' => $m->ses_message_id,
            'created_at' => $m->created_at?->toIso8601String(), 'sent_at' => $m->sent_at?->toIso8601String(), 'delivered_at' => $m->delivered_at?->toIso8601String(),
        ];
    }

    /**
     * Same Idempotency-Key + same body within 24 hours returns the first response instead of sending again.
     *
     * @param  callable(): array{0: int, 1: array<string, mixed>}  $run
     */
    private function idempotent(Request $request, callable $run): JsonResponse
    {
        $key = $request->header('Idempotency-Key');
        if ($key === null) {
            [$status, $body] = $run();

            return response()->json($body, $status);
        }
        if (strlen($key) < 8 || strlen($key) > 255) {
            throw new ApiException('validation_failed', 'Idempotency-Key must be 8 to 255 characters.', 422);
        }
        $ws = $this->workspace($request)->id;
        $cacheKey = 'idem:'.$ws.':'.hash('sha256', $key);
        $fingerprint = hash('sha256', $request->getContent());
        $lock = Cache::lock($cacheKey.':lock', 60);
        if (! $lock->get()) {
            throw new ApiException('request_in_progress', 'A request with this Idempotency-Key is still being processed.', 409);
        }
        try {
            if ($seen = Cache::get($cacheKey)) {
                if ($seen['fingerprint'] !== $fingerprint) {
                    throw new ApiException('idempotency_conflict', 'This Idempotency-Key was already used with a different request body.', 409);
                }

                return response()->json($seen['body'], $seen['status'])->header('Idempotent-Replayed', 'true');
            }
            [$status, $body] = $run();
            Cache::put($cacheKey, ['fingerprint' => $fingerprint, 'status' => $status, 'body' => $body], 86400);

            return response()->json($body, $status);
        } finally {
            $lock->release();
        }
    }

    private function workspace(Request $request): Workspace
    {
        /** @var Workspace $w */
        $w = $request->attributes->get('workspace');

        return $w;
    }

    private function key(Request $request): ApiKey
    {
        /** @var ApiKey $k */
        $k = $request->attributes->get('api_key');

        return $k;
    }
}
