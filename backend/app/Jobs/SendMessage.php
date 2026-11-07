<?php

namespace App\Jobs;

use App\Services\Sending\Sender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Sends one queued message (API sends, retries). Bulk sends use SendMessages. */
class SendMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 1; // retries are new, delayed jobs so the message row tracks every attempt

    public function __construct(public string $messageId) {}

    public function handle(Sender $sender): void
    {
        $sender->send([$this->messageId]);
    }
}
