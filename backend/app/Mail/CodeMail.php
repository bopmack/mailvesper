<?php

namespace App\Mail;

use App\Enums\CodePurpose;
use App\Services\Platform\Brand;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $code, public CodePurpose $purpose) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: match ($this->purpose) {
            CodePurpose::VerifyEmail => "{$this->code} is your ".Brand::name().' verification code',
            CodePurpose::Login => "{$this->code} is your ".Brand::name().' sign-in code',
            CodePurpose::Confirm => "{$this->code} is your ".Brand::name().' confirmation code',
        });
    }

    public function content(): Content
    {
        return new Content(view: 'mail.code', text: 'mail.code-text', with: [
            'heading' => match ($this->purpose) {
                CodePurpose::VerifyEmail => 'Verify your email',
                CodePurpose::Login => 'Your sign-in code',
                CodePurpose::Confirm => 'Confirm this change',
            },
            'intro' => match ($this->purpose) {
                CodePurpose::VerifyEmail => 'Enter this code to finish creating your '.Brand::name().' account.',
                CodePurpose::Login => 'Enter this code to sign in to '.Brand::name().'.',
                CodePurpose::Confirm => 'Someone signed in to your '.Brand::name().' account asked to delete a workspace. Enter this code to confirm. If it wasn’t you, ignore this email and change your password.',
            },
        ]);
    }
}
