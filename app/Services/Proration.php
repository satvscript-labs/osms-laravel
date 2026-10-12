<?php

namespace App\Services;

use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use Illuminate\Support\Carbon;

/**
 * REQ-15 — the part-period charge when a branch joins mid-cycle, and its working.
 *
 * Owner requirement, and the reason this is a service rather than three lines
 * inside the provisioner: *"show the detailed description and the details of
 * the calculations while we are prorating — it must be visible and show how it
 * is calculated."*
 *
 * A pro-rata line is the single most-queried item on any bill. "₹338.03" with
 * no derivation is unanswerable across a counter. So this returns the whole
 * working — every input, the arithmetic, and the result — computed ONCE and
 * rendered identically in the operator's preview, on the ledger row, and on
 * the customer's receipt. Nothing downstream re-derives it, which is what stops
 * the three ever disagreeing.
 *
 * It is also why the working is STORED on the invoice: months later, after the
 * branch count and the tier have both moved, the only way a receipt can still
 * explain itself is if it kept the numbers it was written with.
 *
 * Day-count convention: **actual days in the actual period**, not 30/360. It is
 * the convention a shopkeeper can check on a wall calendar, which is the only
 * test that matters here.
 */
class Proration
{
    public function __construct(private readonly PriceResolver $prices) {}

    /**
     * What one extra branch costs for the remainder of the current period.
     *
     * @param  Subscription $subscription the account's clock
     * @param  Carbon|null  $joinedOn     when the branch starts (default today)
     * @param  int          $branches     how many branches are joining (default 1)
     * @return array{
     *   applicable: bool,
     *   reason: string|null,
     *   amount: float,
     *   unit_price: float,
     *   branches: int,
     *   period_start: Carbon,
 *   charged_from: Carbon,
     *   period_end: Carbon,
     *   days_in_period: int,
     *   days_charged: int,
     *   interval: string,
     *   tier_label: string|null,
     *   lines: list<array{label: string, value: string}>,
     *   formula: string,
     * }
     */
    public function forNewBranch(Subscription $subscription, ?Carbon $joinedOn = null, int $branches = 1): array
    {
        $tz = config('billing.timezone', 'Asia/Kolkata');
        $joinedOn = ($joinedOn ?: Carbon::today($tz))->copy()->startOfDay();
        $branches = max(1, $branches);
        $interval = $subscription->interval ?: 'monthly';

        $periodEnd = $subscription->current_period_end
            ? Carbon::parse($subscription->current_period_end->toDateString(), $tz)->startOfDay()
            : null;

        // The rate this branch is being added AT — i.e. at the quantity the
        // account will be on once it joins, so a volume tier that kicks in
        // because of THIS branch is the tier this branch is charged at.
        $newQuantity = (int) ($subscription->quantity ?: 1) + $branches;
        $breakdown = $this->prices->breakdown($subscription, $interval, $newQuantity);
        $unit = $breakdown['unit_price'];

        $blocked = $this->whyNotApplicable($subscription, $periodEnd, $joinedOn, $unit);

        if ($blocked !== null) {
            return $this->notApplicable($blocked, $unit, $branches, $interval, $breakdown['tier_label']);
        }

        // The period this branch is buying INTO (for the rate's denominator), and
        // the stretch it is charged for: from the later of the day it joined and
        // the day the account's PAID cover begins. Nobody pays for days before the
        // branch existed.
        //
        // QA-04 — nor for days nobody paid for. A customer who pays early during
        // their trial is covered from the trial's end; the gap until then is still
        // trial, free for every store, this one too. Counting from the join date
        // alone charged "45 of 31" days for exactly that gap. Where the account has
        // prepaid more than one period, the join date can sit inside an earlier
        // PAID period, and those days are rightly charged — which is why the start
        // comes from the ledger, not from stepping one interval back.
        $periodStart = $this->currentPeriodStart($periodEnd, $interval, $tz);
        $daysInPeriod = max(1, (int) $periodStart->diffInDays($periodEnd));
        $paidFrom = $this->paidCoverStart($subscription, $joinedOn, $tz) ?? $periodStart;
        $chargedFrom = $joinedOn->greaterThan($paidFrom) ? $joinedOn->copy() : $paidFrom->copy();
        $daysCharged = max(0, (int) $chargedFrom->diffInDays($periodEnd));

        $amount = round($unit * $branches * $daysCharged / $daysInPeriod, 2);

        return [
            'applicable' => true,
            'reason' => null,
            'amount' => $amount,
            'unit_price' => $unit,
            'branches' => $branches,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            // AUDIT-02 — the stretch actually charged for; the ledger row records
            // THIS as its cover, so its dates and its working cannot disagree.
            'charged_from' => $chargedFrom,
            'days_in_period' => $daysInPeriod,
            'days_charged' => $daysCharged,
            'interval' => $interval,
            'tier_label' => $breakdown['tier_label'],
            // The working, in the order a person reads it. Rendered verbatim
            // wherever this charge is shown.
            'lines' => $this->lines($joinedOn, $chargedFrom, $periodStart, $periodEnd, $daysInPeriod, $daysCharged,
                $unit, $branches, $interval, $breakdown['tier_label'], $amount),
            'formula' => $this->formula($unit, $branches, $daysCharged, $daysInPeriod, $amount),
        ];
    }

    /**
     * Why a pro-rata charge does not apply. Returning the REASON rather than
     * silently returning ₹0 matters: "nothing to charge" and "we could not
     * work it out" look identical in a number and completely different to an
     * operator deciding whether to chase it.
     */
    private function whyNotApplicable(Subscription $s, ?Carbon $periodEnd, Carbon $joinedOn, float $unit): ?string
    {
        if (! $periodEnd) {
            return 'This customer has no billing period yet, so there is nothing to pro-rate against.';
        }

        if ($periodEnd->lte($joinedOn)) {
            return 'Their current period has already ended — the new branch will be billed in full at the next renewal.';
        }

        if ($unit <= 0.0) {
            return 'Their rate is ₹0, so there is nothing to charge for the part period.';
        }

        if ($s->override_kind === 'comp' && $s->hasActiveOverride()) {
            return 'They are on complimentary access, so this branch is free until the grant ends.';
        }

        if ($s->status === 'trialing') {
            return 'They are still on trial — the new branch joins the trial and is billed with everything else at the first payment.';
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function notApplicable(string $reason, float $unit, int $branches, string $interval, ?string $tierLabel): array
    {
        $today = Carbon::today(config('billing.timezone', 'Asia/Kolkata'));

        return [
            'applicable' => false,
            'reason' => $reason,
            'amount' => 0.0,
            'unit_price' => $unit,
            'branches' => $branches,
            'period_start' => $today,
            'period_end' => $today,
            'charged_from' => $today,
            'days_in_period' => 0,
            'days_charged' => 0,
            'interval' => $interval,
            'tier_label' => $tierLabel,
            'lines' => [['label' => 'No part-period charge', 'value' => $reason]],
            'formula' => '',
        ];
    }

    /**
     * QA-04 — where the account's paid cover that matters to this branch begins:
     * the earliest coverage start among the account's live (non-reversed) ledger
     * rows that are still running on the day the branch joins.
     *
     * Null when the ledger cannot say (rows from before coverage periods were
     * recorded, or an extension with no payment behind it); the caller then falls
     * back to the current period — the reading that can only ever charge less.
     */
    private function paidCoverStart(Subscription $s, Carbon $joinedOn, string $tz): ?Carbon
    {
        if (! $s->account_id) {
            return null;
        }

        $start = SubscriptionInvoice::withoutGlobalScopes()
            ->where('account_id', $s->account_id)
            ->whereNull('reversed_at')
            ->whereNotNull('period_start')
            ->whereNotNull('period_end')
            ->where('period_end', '>', $joinedOn->toDateString())
            ->min('period_start');

        return $start ? Carbon::parse($start, $tz)->startOfDay() : null;
    }

    /**
     * The start of the period now running, derived by stepping one interval
     * back from its end. Cheaper and more honest than storing it: the end date
     * is the field the whole system already maintains.
     */
    private function currentPeriodStart(Carbon $periodEnd, string $interval, string $tz): Carbon
    {
        return $interval === 'yearly'
            ? $periodEnd->copy()->subYear()
            : $periodEnd->copy()->subMonth();
    }

    /** @return list<array{label: string, value: string}> */
    private function lines(
        Carbon $joinedOn, Carbon $chargedFrom, Carbon $periodStart, Carbon $periodEnd,
        int $daysInPeriod, int $daysCharged,
        float $unit, int $branches, string $interval, ?string $tierLabel, float $amount,
    ): array {
        $per = $interval === 'yearly' ? 'year' : 'month';

        $lines = [
            ['label' => 'Branch starts', 'value' => $joinedOn->format('d M Y')],
            [
                'label' => 'Current billing period',
                'value' => $periodStart->format('d M Y') . ' → ' . $periodEnd->format('d M Y')
                    . ' (' . $daysInPeriod . ' days)',
            ],
        ];

        // QA-04 — when the branch joins before the paid period starts, say where
        // the charge begins and why, or "Branch starts 12 Oct" beside "31 days"
        // reads as an error.
        if ($chargedFrom->greaterThan($joinedOn)) {
            $lines[] = [
                'label' => 'Charged from',
                'value' => $chargedFrom->format('d M Y') . ' (their paid cover starts then)',
            ];
        }

        // Prepaid more than one period: the days run past one period's length,
        // which is right, and must not look like an arithmetic slip.
        if ($daysCharged > $daysInPeriod) {
            $lines[] = [
                'label' => 'Note',
                'value' => 'They have paid ahead, so this covers more than one billing period',
            ];
        }

        $lines = [...$lines,
            ['label' => 'Days being charged', 'value' => $daysCharged . ' of ' . $daysInPeriod],
            [
                'label' => 'Rate',
                'value' => '₹ ' . number_format($unit, 2) . ' per branch per ' . $per
                    . ($tierLabel ? " ({$tierLabel})" : ''),
            ],
        ];

        if ($branches > 1) {
            $lines[] = ['label' => 'Branches joining', 'value' => (string) $branches];
        }

        $lines[] = ['label' => 'Part period due now', 'value' => '₹ ' . number_format($amount, 2)];

        return $lines;
    }

    /** The arithmetic on one line, so it can be checked at a glance. */
    private function formula(float $unit, int $branches, int $daysCharged, int $daysInPeriod, float $amount): string
    {
        $left = '₹ ' . number_format($unit, 2);

        if ($branches > 1) {
            $left .= ' × ' . $branches;
        }

        return "{$left} × {$daysCharged} ÷ {$daysInPeriod} = ₹ " . number_format($amount, 2);
    }
}
