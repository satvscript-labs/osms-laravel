<?php

namespace App\Mail;

use App\Models\StaffInvitation;
use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * ST-Staff (S3) — emailed invitation to join a store.
 */
class StaffInvitationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public StaffInvitation $invitation,
        public Tenant $tenant,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "You're invited to join {$this->tenant->store_name} on OSMS");
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.staff-invitation');
    }
}
