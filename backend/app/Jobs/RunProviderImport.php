<?php

namespace App\Jobs;

use App\Models\ProviderImport;
use App\Services\Migration\MigrationService;
use App\Services\Migration\Providers\ProviderError;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** Runs an import for about 40 seconds, saves its place, and queues the next chunk. */
class RunProviderImport implements ShouldQueue
{
    use Queueable;

    public const MAX_RETRIES = 12;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public string $importId) {}

    public function handle(MigrationService $service): void
    {
        $lock = Cache::lock('provider-import:'.$this->importId, 110);
        if (! $lock->get()) {
            return;
        }
        try {
            $imp = ProviderImport::find($this->importId);
            if (! $imp || $imp->status !== 'importing') {
                return;
            }
            try {
                $more = $service->step($imp);
            } catch (ProviderError $e) {
                $imp->refresh();
                if ($e->retryable && $imp->attempts < self::MAX_RETRIES) {
                    $imp->forceFill(['attempts' => $imp->attempts + 1, 'error' => mb_substr($e->getMessage(), 0, 500)])->save();
                    self::dispatch($imp->id)->delay(now()->addSeconds(min(900, $e->retryAfter * $imp->attempts)));

                    return;
                }
                $imp->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500), 'finished_at' => now(), 'credentials' => []])->save();

                return;
            } catch (Throwable $e) {
                report($e);
                $imp->refresh()->forceFill(['status' => 'failed', 'error' => 'The import stopped on an unexpected error. Our team has been notified.', 'finished_at' => now(), 'credentials' => []])->save();

                return;
            }
            if ($more) {
                self::dispatch($imp->id)->delay(now()->addSecond());
            }
        } finally {
            $lock->release();
        }
    }
}
