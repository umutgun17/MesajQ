<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PaymentFailedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public \App\Models\Subscription $subscription, public \App\Models\Invoice $invoice) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: config('saas.brand.name') . ' — Ödeme alınamadı');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.payment_failed', with: ['subscription' => $this->subscription, 'invoice' => $this->invoice]);
    }
}
