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
    /**
     * A-10 — the outermost money transaction is retried this many times when the
     * database picks it as a deadlock victim. Safe: audit rows roll back with it,
     * after-commit gateway calls of a rolled-back attempt are discarded, and
     * nothing leaves the database mid-transaction. Nested transactions defer to
     * the outermost one (Laravel retries only at level 1).
     */
    public const DEADLOCK_ATTEMPTS = 3;

    /** A transaction that survives being a deadlock victim (see DEADLOCK_ATTEMPTS). */
    public static function transaction(Closure $fn): mixed
    {
        return \Illuminate\Support\Facades\DB::transaction($fn, self::DEADLOCK_ATTEMPTS);
    }

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
