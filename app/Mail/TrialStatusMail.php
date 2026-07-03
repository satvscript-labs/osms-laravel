<?php

namespace App\Mail;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * ST-Email (S5) — trial lifecycle notification, dispatched by
 * `subscriptions:reconcile`. $daysLeft === 0 means the trial has ended.
 */
class TrialStatusMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Tenant $tenant,
        public int $daysLeft,
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->daysLeft === 0
            ? 'Your OSMS free trial has ended'
            : "Your OSMS free trial ends in {$this->daysLeft} "
                .\Illuminate\Support\Str::plural('day', $this->daysLeft);

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.trial-status');
    }
}
