<?php

namespace App\Services\Sending;

use App\Models\SesConnection;
use App\Models\SmtpConnection;
use Illuminate\Support\Facades\Cache;

/** Keeps sends under the account's SES maximum send rate, or the SMTP rate the customer set (emails per second), across all workers. */
class Pacer
{
    /** Waits for a slot; false if none came free within $maxWaitMs. */
    public function wait(SesConnection $conn, int $maxWaitMs = 5000): bool
    {
        return $this->take($conn, 1, $maxWaitMs) === 1;
    }

    /**
     * Up to $want slots in the current second under the SES send rate (at least one, waiting for the next second if
     * this one is full); 0 if none came free within $maxWaitMs.
     */
    public function take(SesConnection $conn, int $want, int $maxWaitMs = 5000): int
    {
        return $this->reserve($conn, $want, $maxWaitMs)[0];
    }

    /**
     * Like take(), and also says which second the slots belong to: they must be used within that second.
     *
     * @return array{0: int, 1: int} [slots, unix second]
     */
    public function reserve(SesConnection $conn, int $want, int $maxWaitMs = 5000): array
    {
        return $this->slots("ses-rate:{$conn->id}", max(1, (int) floor($conn->max_send_rate ?: 1)), max(1, $want), $maxWaitMs);
    }

    /** The same for an SMTP server, at the rate the customer set for it. */
    public function waitSmtp(SmtpConnection $conn, int $maxWaitMs = 5000): bool
    {
        return $this->slot("smtp-rate:{$conn->id}", max(1, $conn->max_per_second), $maxWaitMs);
    }

    private function slot(string $prefix, int $limit, int $maxWaitMs): bool
    {
        return $this->slots($prefix, $limit, 1, $maxWaitMs)[0] === 1;
    }

    /** @return array{0: int, 1: int} [slots, the unix second they belong to] */
    private function slots(string $prefix, int $limit, int $want, int $maxWaitMs): array
    {
        $deadline = microtime(true) + $maxWaitMs / 1000;
        do {
            $second = time();
            Cache::add("$prefix:$second", 0, 5);
            $before = (int) Cache::increment("$prefix:$second", $want) - $want; // one atomic step, shared by every worker
            $got = min($want, $limit - $before);
            if ($got > 0) {
                return [$got, $second];
            }
            usleep((int) ((1 - fmod(microtime(true), 1)) * 1_000_000) + 1000); // to the next second
        } while (microtime(true) < $deadline);

        return [0, time()];
    }
}
