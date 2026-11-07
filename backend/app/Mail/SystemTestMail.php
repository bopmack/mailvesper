<?php

namespace App\Mail;

use App\Services\Platform\Brand;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Platform → System email → Send test. Sent at once (not queued) so the admin sees the server's real answer. */
class SystemTestMail extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Test email from '.Brand::name());
    }

    public function content(): Content
    {
        return new Content(view: 'mail.test', text: 'mail.test-text');
    }
}
