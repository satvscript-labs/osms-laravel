<?php

namespace App\Support;

use App\Models\Subscription;
use App\Services\PriceResolver;

/**
 * P6 / 03 §8 — the two metrics the ledger made possible, and nothing else.
 *
 * The governing rule is playbook §9: *"if the underlying data cannot support a
 * metric honestly, do not display a fake one."* Applied here that means two
 * refusals as deliberate as the two additions:
 *
 *   ⛔ trial → paid conversion — deferred in §8; needs volume to mean anything.
 *   ⛔ cohort / retention      — would be fabricated at this scale.
 *
 * And one thing worth being blunt about in the numbers that ARE shown:
 * **OSMS raises no invoices in advance.** `subscription_invoices` is a record of
 * money RECEIVED, not of money billed. So there is no accounts-receivable
 * balance to report, and "outstanding" in the accountant's sense does not exist
 * in this system. What the panel shows instead is EXPECTED — derived from each
 * customer's current effective price — and it says so on the surface rather
 * than in a comment nobody reads.
 */
class Metrics
{
    /** How far ahead "due soon" looks. Matches a monthly billing rhythm. */
    private const DUE_HORIZON_DAYS = 30;

    /** The window over which churn is reported. */
    private const CHURN_WINDOW_DAYS = 90;

    /**
     * Below this many events, a percentage is noise dressed as insight.
     *
     * §8: *"At n=1 a single churn = 100%. Show counts, not percentages, until
     * n ≥ 10."* The threshold lives here so every surface obeys the same one.
     */
    public const PERCENTAGE_FLOOR = 10;

    /**
     * Collection health — what you are owed and what is coming.
     *
     * @return array{overdue_count:int, overdue_amount:float, due_soon_count:int,
     *               due_soon_amount:float, horizon_days:int}
     */
    public function collection(): array
    {
        $prices = app(PriceResolver::class);

        // past_due is bounded by customers who have failed to pay — small by
        // definition, and it must be hydrated because the effective price needs
        // the plan and any negotiated override to resolve.
        $overdue = Subscription::withoutGlobalScopes()
            ->with('plan')
            ->where('status', 'past_due')
            ->get();

        $dueSoon = Subscription::withoutGlobalScopes()
            ->with('plan')
            ->whereIn('status', ['active', 'trialing'])
            ->whereNotNull('current_period_end')
            ->whereBetween('current_period_end', [
                now()->toDateString(),
                now()->addDays(self::DUE_HORIZON_DAYS)->toDateString(),
            ])
            ->get();

        return [
            'overdue_count' => $overdue->count(),
            'overdue_amount' => round($overdue->sum(fn ($s) => $this->cycleValue($prices, $s)), 2),
            'due_soon_count' => $dueSoon->count(),
            'due_soon_amount' => round($dueSoon->sum(fn ($s) => $this->cycleValue($prices, $s)), 2),
            'horizon_days' => self::DUE_HORIZON_DAYS,
        ];
    }

    /**
     * Churn, reported as counts.
     *
     * `untracked` is the honest part. Every cancellation that predates the
     * churn-tracking migration has no date, so it cannot be placed in a window.
     * Rather than quietly excluding those rows — which would make churn look
     * better than it was — the surface states how many are unaccounted for.
     *
     * @return array{window_days:int, logo:int, revenue:float, trials_lapsed:int,
     *               untracked:int, show_percentage:bool}
     */
    public function churn(): array
    {
        $since = now()->subDays(self::CHURN_WINDOW_DAYS);

        $churned = Subscription::withoutGlobalScopes()
            ->whereNotNull('churned_at')
            ->where('churned_at', '>=', $since)
            ->get(['churned_mrr', 'churned_from']);

        // Only a PAYING customer walking away is churn. A trial that ended is a
        // failed conversion — a different question, and one §8 defers for want
        // of volume. Merging them would flatter or damn the number by month.
        $paying = $churned->where('churned_from', 'active');

        return [
            'window_days' => self::CHURN_WINDOW_DAYS,
            'logo' => $paying->count(),
            'revenue' => round((float) $paying->sum('churned_mrr'), 2),
            'trials_lapsed' => $churned->where('churned_from', 'trialing')->count(),
            'untracked' => Subscription::withoutGlobalScopes()
                ->where('status', 'canceled')
                ->whereNull('churned_at')
                ->count(),
            // The base for a churn RATE is how many paying customers there were
            // to lose. Below the floor there is no honest percentage to show.
            'show_percentage' => $this->payingBase() >= self::PERCENTAGE_FLOOR,
        ];
    }

    /** Paying customers, the denominator any rate would need. */
    public function payingBase(): int
    {
        return Subscription::withoutGlobalScopes()->where('status', 'active')->count();
    }

    /**
     * What this customer is expected to pay at their next event.
     *
     * The FULL cycle amount, not a monthly slice: an annual customer who is
     * overdue owes the year, and showing ₹292 where ₹3,500 is owed would
     * understate the collection problem twelvefold.
     *
     * ⚠ `PriceResolver` deliberately does NOT zero out a comp — it answers
     * "what is this priced at", which is a different question from "what will
     * they hand over". `Mrr` makes the same subtraction separately and for the
     * same reason. Expecting money from someone you have given the product to
     * would overstate collections by exactly the value of your own goodwill.
     */
    private function cycleValue(PriceResolver $prices, Subscription $subscription): float
    {
        if ($subscription->override_kind === 'comp' && $subscription->hasActiveOverride()) {
            return 0.0;
        }

        return $prices->effectivePrice($subscription);
    }
}
