<?php

namespace App\Services;

use Illuminate\Support\Carbon;

/**
 * E2 — the one definition of "N billing cycles after a date".
 *
 * The clock (renewals), the charge's quoted window and the proration's cycle
 * boundaries all use THIS, so they can never disagree about where a cycle ends.
 *
 * E2-I / FB-32: it keeps Carbon's month OVERFLOW (31 Jan + 1 month = 3 Mar), which
 * is what the clock has always done. Changing it would move live renewal dates —
 * an owner decision, not something to slip into E2. Boundaries are always measured
 * from ONE anchor (`start + k cycles`), never chained, so k cycles from the start
 * land exactly where a k-cycle renewal ends.
 */
final class BillingCycle
{
    public static function add(Carbon $from, ?string $interval, int $cycles = 1): Carbon
    {
        return ($interval === 'yearly')
            ? $from->copy()->addYears($cycles)
            : $from->copy()->addMonths($cycles);
    }

    /** The most cycles one payment may cover (E2-A): a year ahead monthly, three years ahead yearly. */
    public static function maxCycles(?string $interval): int
    {
        return $interval === 'yearly' ? 3 : 12;
    }
}
