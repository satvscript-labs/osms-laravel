<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * OPS-01 — alert the platform owner that the database backup is missing, stale or
 * suspiciously small. Deliberately NOT ShouldQueue: sent synchronously so an alert
 * about broken infrastructure can't itself get stuck in the cron-drained queue.
 */
class BackupAlertMail extends Mailable
{
    /**
     * @param  string  $problem   Human-readable summary of what's wrong.
     * @param  array<string,string>  $details  Label => value rows for the email body.
     */
    public function __construct(
        public string $problem,
        public array $details = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '[OSMS] Database backup problem — ' . $this->problem);
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.backup-alert');
    }
}
