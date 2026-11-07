<?php

namespace App\Services;

use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Sends account emails without letting a delivery failure break the request.
 * The account action (sign-up, code issue) has already succeeded; the user can resend.
 */
class SafeMail
{
    public static function send(string $to, Mailable $mailable): bool
    {
        try {
            Mail::to($to)->send($mailable);

            return true;
        } catch (TransportExceptionInterface $e) {
            Log::warning('mail.delivery_failed', ['to_domain' => substr((string) strrchr($to, '@'), 1), 'mailable' => $mailable::class, 'reason' => mb_substr($e->getMessage(), 0, 300)]);

            return false;
        }
    }
}
