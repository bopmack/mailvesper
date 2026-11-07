<?php

namespace App\Jobs;

use App\Services\Journeys\JourneyEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Moves one journey run forward (see JourneyEngine). Safe to run twice: the engine claims the run first. */
class AdvanceJourneyRun implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public string $runId) {}

    public static function dispatchFor(string $runId): void
    {
        app()->runningInConsole() ? self::dispatch($runId) : self::dispatchAfterResponse($runId);
    }

    public function handle(JourneyEngine $engine): void
    {
        $engine->advance($this->runId);
    }
}
