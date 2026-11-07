<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** An operations alert for the platform admins. */
class OpsAlertMail extends Mailable
{
    public string $url;

    /** @param list<string> $lines */
    public function __construct(public string $title, public array $lines)
    {
        $this->url = rtrim((string) config('app.frontend_url'), '/').'/app/#/platform?tab=system';
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->title);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.ops-alert', text: 'mail.ops-alert-text');
    }
}
