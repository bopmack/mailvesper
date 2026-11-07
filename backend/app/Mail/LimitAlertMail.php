<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** A warning to a workspace's owners and admins before a sending limit stops their email. */
class LimitAlertMail extends Mailable
{
    public string $url;

    /** @param list<array{title: string, detail: string, urgent: bool}> $alerts */
    public function __construct(public string $workspace, public array $alerts)
    {
        $this->url = rtrim((string) config('app.frontend_url'), '/').'/app/#/ses';
    }

    public function envelope(): Envelope
    {
        $urgent = (bool) array_filter($this->alerts, fn ($a) => $a['urgent']);

        return new Envelope(subject: ($urgent ? 'Action needed: ' : 'Heads up: ').$this->alerts[0]['title'].' ('.$this->workspace.')');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.limit-alert', text: 'mail.limit-alert-text');
    }
}
