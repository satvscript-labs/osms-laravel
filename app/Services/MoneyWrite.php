<?php

namespace App\Services;

use Closure;

/**
 * E2.1 — the write scope for money rows: the ledger (`subscription_invoices`) and
 * charges (`billing_charges`). Billing rules 2 and 6.
 *
 * The same pattern as `SubscriptionLifecycle::writing()` (E1): the models refuse
 * every create/update outside this scope, and only the engine's money services
 * open it — `PaymentRecorder` (the ledger), `BillingEngine` and `Settlement`
 * (charges). An architecture test (Phase85E2GuardTest) holds that list.
 */
final class MoneyWrite
{
    /** How deep we are inside during(). Re-entrant; always unwound by `finally`. */
    private static int $depth = 0;

    public static function isOpen(): bool
    {
        return self::$depth > 0;
    }

    public static function during(Closure $fn): mixed
    {
        self::$depth++;

        try {
            return $fn();
        } finally {
            self::$depth--;
        }
    }
}
