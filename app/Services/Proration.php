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

        // E2.5 / FB-13 — the whole span the branch rides, split into cycles, each
        // priced at its own real length (06 §5.1).
        //
        // Where the span starts — QA-04, kept: from the later of the day it joined
        // and the start of the account's PAID cover. Nobody pays for trial days, and
        // where the account prepaid several periods back to back the join date can
        // sit inside an earlier PAID period, whose days are rightly charged. The
        // ledger knows the earliest running cover; the stored period start knows
        // only the latest period; one cycle back is the last resort.
        $coverStart = $this->paidCoverStart($subscription, $joinedOn, $tz)
            ?? ($subscription->current_period_start
                ? Carbon::parse($subscription->current_period_start->toDateString(), $tz)->startOfDay()
                : null)
            ?? BillingCycle::add($periodEnd, $interval, -1);
        $chargedFrom = $joinedOn->greaterThan($coverStart) ? $joinedOn->copy() : $coverStart->copy();

        // Cycle boundaries FORWARD from the cover start, with the clock's own
        // arithmetic (BillingCycle), the last one clipped to the period end.
        $segments = $this->segments($coverStart, $periodEnd, $chargedFrom, $interval, $unit * $branches);

        if ($segments === []) {
            return $this->notApplicable('Their current period has already ended — the new branch will be billed in full at the next renewal.',
                $unit, $branches, $interval, $breakdown['tier_label']);
        }

        // Each line rounded once; the total is their sum, so the working adds up.
        $amount = round(array_sum(array_column($segments, 'amount')), 2);
        $first = $segments[0];
        $daysCharged = max(0, (int) $chargedFrom->diffInDays($periodEnd));

        return [
            'applicable' => true,
            'reason' => null,
            'amount' => $amount,
            'unit_price' => $unit,
            'branches' => $branches,
            // The cycle the branch joins in (its real length is the first denominator).
            'period_start' => $first['cycle_start'],
            'period_end' => $periodEnd,
            // AUDIT-02 — the stretch actually charged for.
            'charged_from' => $chargedFrom,
            'days_in_period' => $first['cycle_days'],
            'days_charged' => $daysCharged,
            'interval' => $interval,
            'tier_label' => $breakdown['tier_label'],
            'segments' => $segments,
            'lines' => $this->lines($joinedOn, $chargedFrom, $periodEnd, $segments, $unit, $branches, $interval, $breakdown['tier_label'], $amount),
            'formula' => $this->formula($segments, $unit, $branches, $amount),
        ];
    }

    /**
     * The span [chargedFrom, end) cut at cycle boundaries counted from $start.
     *
     * @return list<array{cycle_start: Carbon, cycle_end: Carbon, cycle_days: int, from: Carbon, to: Carbon,
     *                    days: int, whole: bool, amount: float}>
     */
    private function segments(Carbon $start, Carbon $end, Carbon $chargedFrom, string $interval, float $perCycle): array
    {
        $out = [];

        // Bounded: a few years of monthly cycles at most (a prepay is capped at 12).
        for ($k = 0; $k < 120; $k++) {
            $cycleStart = BillingCycle::add($start, $interval, $k);

            if ($cycleStart->gte($end)) {
                break;
            }

            $cycleEnd = BillingCycle::add($start, $interval, $k + 1);
            $to = $cycleEnd->min($end);
            $from = $cycleStart->max($chargedFrom);

            if ($from->gte($to)) {
                continue;   // before the branch joined: not its days
            }

            $cycleDays = max(1, (int) $cycleStart->diffInDays($cycleEnd));
            $days = (int) $from->diffInDays($to);
            $whole = $from->equalTo($cycleStart) && $to->equalTo($cycleEnd);

            $out[] = [
                'cycle_start' => $cycleStart->copy(),
                'cycle_end' => $cycleEnd->copy(),
                'cycle_days' => $cycleDays,
                'from' => $from->copy(),
                'to' => $to->copy(),
                'days' => $days,
                'whole' => $whole,
                'amount' => $whole ? round($perCycle, 2) : round($perCycle * $days / $cycleDays, 2),
            ];
        }

        return $out;
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
     * The working, in the order a person reads it. One part cycle (where the branch
     * joined), then any whole cycles, then any irregular tail — never "365 of 31".
     *
     * @return list<array{label: string, value: string}>
     */
    private function lines(
        Carbon $joinedOn, Carbon $chargedFrom, Carbon $periodEnd, array $segments,
        float $unit, int $branches, string $interval, ?string $tierLabel, float $amount,
    ): array {
        $per = $interval === 'yearly' ? 'year' : 'month';
        $first = $segments[0];

        $lines = [['label' => 'Branch starts', 'value' => $joinedOn->format('d M Y')]];

        // QA-04 — when the branch joins before the paid period starts, say where
        // the charge begins and why, or "Branch starts 12 Oct" beside "31 days"
        // reads as an error.
        if ($chargedFrom->greaterThan($joinedOn)) {
            $lines[] = ['label' => 'Charged from', 'value' => $chargedFrom->format('d M Y') . ' (their paid cover starts then)'];
        }

        $lines[] = [
            'label' => 'Current billing period',
            'value' => $first['cycle_start']->format('d M Y') . ' → ' . $first['cycle_end']->format('d M Y')
                . ' (' . $first['cycle_days'] . ' days)',
        ];
        $lines[] = ['label' => 'Days being charged', 'value' => $first['days'] . ' of ' . $first['cycle_days']];

        $rest = array_slice($segments, 1);
        $whole = array_values(array_filter($rest, fn ($s) => $s['whole']));
        $tail = array_values(array_filter($rest, fn ($s) => ! $s['whole']));

        if ($whole !== []) {
            $n = count($whole);
            $lines[] = [
                'label' => 'Whole cycles',
                'value' => $whole[0]['from']->format('d M Y') . ' → ' . end($whole)['to']->format('d M Y')
                    . " = {$n} × ₹ " . number_format($whole[0]['amount'], 2),
            ];
        }

        foreach ($tail as $t) {
            $lines[] = [
                'label' => 'Part cycle',
                'value' => $t['from']->format('d M Y') . ' → ' . $t['to']->format('d M Y')
                    . ' = ' . $t['days'] . ' of ' . $t['cycle_days'] . ' days',
            ];
        }

        $lines[] = [
            'label' => 'Rate',
            'value' => '₹ ' . number_format($unit, 2) . ' per branch per ' . $per . ($tierLabel ? " ({$tierLabel})" : ''),
        ];

        if ($branches > 1) {
            $lines[] = ['label' => 'Branches joining', 'value' => (string) $branches];
        }

        $lines[] = [
            'label' => count($segments) > 1 ? 'Due now' : 'Part period due now',
            'value' => '₹ ' . number_format($amount, 2),
        ];

        return $lines;
    }

    /** The arithmetic on one line, so it can be checked at a glance. */
    private function formula(array $segments, float $unit, int $branches, float $amount): string
    {
        $rate = '₹ ' . number_format($unit, 2) . ($branches > 1 ? ' × ' . $branches : '');
        $terms = [];
        $wholeCount = 0;

        foreach ($segments as $s) {
            if ($s['whole']) {
                $wholeCount++;

                continue;
            }

            $terms[] = "{$rate} × {$s['days']} ÷ {$s['cycle_days']}";
        }

        if ($wholeCount > 0) {
            $terms[] = ($wholeCount > 1 ? "{$wholeCount} × " : '') . $rate;
        }

        return implode(' + ', $terms) . ' = ₹ ' . number_format($amount, 2);
    }
}
