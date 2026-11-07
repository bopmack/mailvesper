<?php

namespace App\Jobs;

use App\Services\Sending\Sender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Sends a group of queued messages in one go: SES calls go out in parallel within the account's send rate. */
class SendMessages implements ShouldQueue
{
    use Queueable;

    /** Messages per job: enough for a worker to fill the send rate, small enough that pausing a campaign bites fast. */
    public const SIZE = 50;

    public int $tries = 1;

    /** @param  list<string>  $messageIds */
    public function __construct(public array $messageIds) {}

    public function handle(Sender $sender): void
    {
        $sender->send($this->messageIds);
    }
}
