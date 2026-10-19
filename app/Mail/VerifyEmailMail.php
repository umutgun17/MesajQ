<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VerifyEmailMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public \App\Models\User $user, public string $url) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: config('saas.brand.name') . ' — E-posta adresinizi doğrulayın');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.verify', with: ['user' => $this->user, 'url' => $this->url]);
    }
}
