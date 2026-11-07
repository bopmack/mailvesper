<?php

namespace App\Mail;

use App\Services\Platform\Brand;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ResetPasswordMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $url) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Reset your '.Brand::name().' password');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.reset', text: 'mail.reset-text');
    }
}
