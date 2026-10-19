<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TrialEndingMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public \App\Models\Subscription $subscription, public int $days) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: config('saas.brand.name') . ' — Deneme süreniz ' . $this->days . ' gün içinde bitiyor');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.trial_ending', with: ['subscription' => $this->subscription, 'days' => $this->days]);
    }
}
