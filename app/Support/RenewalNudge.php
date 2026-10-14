<?php

namespace App\Support;

use App\Models\Account;
use Illuminate\Support\Carbon;

/**
 * E4-I — the operator's one-tap WhatsApp nudge about a renewal (D-6).
 *
 * Opens wa.me with the message already written; the operator reads it and sends
 * it from their own WhatsApp. Nothing is sent by the app. Null when there is no
 * usable phone on file (the row then offers to add one).
 */
final class RenewalNudge
{
    public static function whatsappUrl(Account $account, ?float $amount, ?Carbon $dueOn, bool $overdue): ?string
    {
        $digits = Phone::waDigits($account->billing_phone);

        if ($digits === null) {
            return null;
        }

        return 'https://wa.me/' . $digits . '?text=' . rawurlencode(self::message($account, $amount, $dueOn, $overdue));
    }

    public static function message(Account $account, ?float $amount, ?Carbon $dueOn, bool $overdue): string
    {
        $first = trim(strtok((string) ($account->owner?->name ?: $account->displayName()), ' ')) ?: 'there';
        $amountText = $amount !== null ? '₹' . number_format($amount, 2) : 'your renewal';
        $date = $dueOn?->format('j M Y');
        $url = route('tenant.billing.index');

        $body = match (true) {
            $overdue && $date !== null => "Your renewal of {$amountText} was due on {$date}. Please pay it to keep your store running",
            $date !== null => "Your renewal of {$amountText} is due on {$date}",
            default => "Your renewal of {$amountText} is due",
        };

        // Never point at online payment they cannot use (supervised, or no gateway keys).
        $how = $account->isSupervised() || ! app(\App\Services\BillingService::class)->isConfigured()
            ? 'Reply here and we will share how to pay.'
            : "You can pay online here: {$url}";

        return "Hello {$first}, this is OSMS. {$body}. {$how} Thank you!";
    }
}
