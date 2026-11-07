<?php

namespace App\Exceptions;

use RuntimeException;

/** A deliberate, user-facing API error with a stable machine code. */
class ApiException extends RuntimeException
{
    /**
     * @param  array<string, string>  $fields  one message per input field
     * @param  array<string, mixed>  $details  structured context, e.g. every problem that blocks publishing
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 400,
        public readonly array $fields = [],
        public readonly ?int $retryAfter = null,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
