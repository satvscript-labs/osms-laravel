<?php

namespace App\Services;

use App\Models\Subscription;
use Illuminate\Support\Carbon;

/**
 * E2 — the one definition of "N billing cycles after a date".
 *
 * The clock (renewals), the charge's quoted window and the proration's cycle
 * boundaries all use THIS, so they can never disagree about where a cycle ends.
 *
 * FB-32 — month ends are CLAMPED to an ANCHOR day, never overflowed:
 *
 *     anchor 31:  31 Jan → 28 Feb → 31 Mar → 30 Apr → 31 May
 *     anchor 29 (yearly, leap day):  29 Feb 2028 → 28 Feb 2029 → … → 29 Feb 2032
 *
 * Without the anchor a clamped date would drift (28 Feb → 28 Mar → 28 Apr …);
 * without the clamp it would overflow (31 Jan + 1 month = 3 Mar) and the renewal
 * day would jump. Boundaries are still measured from ONE start (`start + k`), and
 * with the anchor that equals chaining one cycle at a time.
 */
final class BillingCycle
{
    public static function add(Carbon $from, ?string $interval, int $cycles = 1, ?int $anchorDay = null): Carbon
    {
        $to = ($interval === 'yearly')
            ? $from->copy()->addYearsNoOverflow($cycles)
            : $from->copy()->addMonthsNoOverflow($cycles);

        return $to->day(min($anchorDay ?? $from->day, $to->daysInMonth));
    }

    /**
     * The day a cycle starting at $base renews on.
     *
     * The stored anchor counts only while $base sits on it (31 → 28 Feb or 30 Apr
     * still counts as 31). If the date was moved off it — an extension by days, a
     * restart from today after a lapse — the new day is the anchor. So no writer
     * that moves a date has to remember to reset it.
     */
    public static function anchorFor(?int $stored, Carbon $base): int
    {
        return ($stored !== null && $stored >= 1 && $stored <= 31 && $base->day === min($stored, $base->daysInMonth))
            ? $stored
            : $base->day;
    }

    /** The anchor for this subscription's next cycle from $base. */
    public static function anchorOf(Subscription $s, Carbon $base): int
    {
        return self::anchorFor($s->billing_anchor_day !== null ? (int) $s->billing_anchor_day : null, $base);
    }

    /** The most cycles one payment may cover (E2-A): a year ahead monthly, three years ahead yearly. */
    public static function maxCycles(?string $interval): int
    {
        return $interval === 'yearly' ? 3 : 12;
    }
}
