<?php

namespace App\Services\Journeys;

use RuntimeException;

/** Thrown when a journey can't go live; carries every problem so the editor can mark each step. */
class JourneyProblems extends RuntimeException
{
    /** @param list<array{node: string|null, message: string}> $problems */
    public function __construct(public array $problems)
    {
        parent::__construct($problems[0]['message'] ?? 'The journey isn’t ready.');
    }
}
