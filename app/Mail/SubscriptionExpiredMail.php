<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SubscriptionExpiredMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public \App\Models\Subscription $subscription, public string $reason) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: config('saas.brand.name') . ' — Aboneliğiniz sona erdi');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.expired', with: ['subscription' => $this->subscription, 'reason' => $this->reason]);
    }
}
