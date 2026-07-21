<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * OPS-02 — alert the platform owner that queued jobs have failed. Deliberately
 * NOT ShouldQueue: it is sent synchronously so an alert about a broken queue can't
 * itself get stuck in that queue.
 */
class FailedJobsAlertMail extends Mailable
{
    /**
     * @param  array<int,array{queue:string,failed_at:string,summary:string}>  $recent
     */
    public function __construct(
        public int $count,
        public array $recent,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "[OSMS] {$this->count} failed background job(s)");
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.failed-jobs-alert');
    }
}
