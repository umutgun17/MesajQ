<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InvoicePaidMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public \App\Models\Invoice $invoice) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: config('saas.brand.name') . ' — Ödemeniz alındı (' . $this->invoice->number . ')');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.invoice_paid', with: ['invoice' => $this->invoice]);
    }
}
