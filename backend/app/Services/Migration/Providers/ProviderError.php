<?php

namespace App\Services\Migration\Providers;

use RuntimeException;

/** A provider API problem. retryable = rate limits and outages (try again later); otherwise the import stops. */
class ProviderError extends RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable = false, public readonly int $retryAfter = 60)
    {
        parent::__construct($message);
    }
}
