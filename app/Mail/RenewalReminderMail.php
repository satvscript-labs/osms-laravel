<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * E4 — a renewal reminder to the account's payer (E4-C, E4-E), sent by the daily
 * run once per stage per cycle. Built from plain values taken at send time, so a
 * queued email says exactly what was true when it was decided.
 *
 * Stages: due_7 · due_1 · due_0 · grace · locked.
 */
class RenewalReminderMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $stage,
        public string $customerName,
        public float $amount,
        public string $dueOn,          // Y-m-d: the day the period ends
        public int $daysLeft,          // negative once it has ended
        public ?string $accessUntil,   // Y-m-d: the last day of grace
        public ?string $billNumber,
        public bool $billedByHand,     // supervised: no Pay button, "contact us"
        public bool $freePeriod = false,   // A-22: a free period you gave is ending
    ) {}

    public function envelope(): Envelope
    {
        $amount = '₹' . number_format($this->amount, 2);
        $due = Carbon::parse($this->dueOn)->format('j M');

        if ($this->freePeriod) {
            return new Envelope(subject: $this->stage === 'due_0'
                ? "Your free OSMS period ends today — {$amount} to carry on"
                : "Your free OSMS period ends tomorrow — {$amount} to carry on");
        }

        return new Envelope(subject: match ($this->stage) {
            'due_7' => "Your OSMS renewal of {$amount} is due on {$due}",
            'due_1' => "Your OSMS renewal is due tomorrow — {$amount}",
            'due_0' => "Your OSMS plan renews today — {$amount}",
            'grace' => "Payment overdue — your OSMS plan ended on {$due}",
            'locked' => "Your OSMS store is paused — pay {$amount} to restore it",
            default => 'Your OSMS renewal',
        });
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.renewal-reminder', with: [
            'heading' => $this->freePeriod
                ? ($this->stage === 'due_0' ? 'Your free period ends today' : 'Your free period ends tomorrow')
                : match ($this->stage) {
                'due_7' => 'Your renewal is coming up',
                'due_1' => 'Your renewal is due tomorrow',
                'due_0' => 'Your renewal is due today',
                'grace' => 'Your payment is overdue',
                default => 'Your store is paused',
            },
            'amountText' => '₹ ' . number_format($this->amount, 2),
            'dueText' => Carbon::parse($this->dueOn)->format('d M Y'),
            'untilText' => $this->accessUntil ? Carbon::parse($this->accessUntil)->format('d M Y') : null,
            'payUrl' => route('tenant.billing.index'),
            'support' => config('saas.support_email'),
        ]);
    }
}
