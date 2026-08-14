<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Subscription;

/**
 * P1 / REQ-4 — the ONE place a price is computed.
 *
 * Every quote, every modal preview, every charge, every receipt and the MRR
 * figure read from here, so a preview can never disagree with what is charged
 * (playbook §3.5 / §1.7 "quote before charge").
 *
 * Resolution order, per branch:
 *   negotiated_price          ← a hand-agreed rate, if set (⚑ bespoke)
 *   ∥ plan volume tier        ← the rate for this branch COUNT
 *   ∥ plan flat price         ← the database, when no tiers are configured
 *   ∥ config('billing.plans') ← seed-default safety net only (unseeded tests)
 *
 * …then multiplied by `quantity` — the number of branches being billed for.
 *
 * REQ-15 — three price concepts, deliberately kept apart:
 *
 *   **List rate**       the plan's per-branch price, possibly volume-tiered.
 *   **Negotiated rate** a customer's standing bespoke price. PER BRANCH
 *                       (owner decision 2026-08-14), so it multiplies too.
 *   **One-off discount** something bargained off a SINGLE charge. It does not
 *                       live here at all — it is applied at the moment of
 *                       charge and recorded on that ledger row, because it
 *                       must never leak into the recurring price.
 *
 * NO discount stacking, and no offer/coupon engine — the owner ruled that out
 * and it stays ruled out. A standing bespoke rate is the recurring model; a
 * counter-bargain is a one-off on one row. Nothing else exists.
 */
class PriceResolver
{
    /**
     * The full, itemised price breakdown for a subscription.
     *
     * Itemised on purpose: the operator, the customer's receipt and the "this
     * will…" preview all render THESE steps, so what somebody is told is
     * literally the arithmetic that ran.
     *
     * @return array{
     *   interval: string,
     *   quantity: int,
     *   unit_price: float,
     *   unit_source: string,
     *   tier: array|null,
     *   tier_label: string|null,
     *   list_price: float,
     *   list_source: string,
     *   negotiated_price: float|null,
     *   negotiated_interval: string|null,
     *   negotiated_mismatch: bool,
     *   effective: float,
     *   source: string,
     *   steps: list<array{label: string, amount: float, detail?: string}>,
     * }
     */
    public function breakdown(Subscription $subscription, ?string $interval = null, ?int $quantity = null): array
    {
        $interval = $interval ?: ($subscription->interval ?: 'monthly');
        $quantity = max(1, $quantity ?? (int) ($subscription->quantity ?: 1));

        [$list, $listSource, $tier] = $this->listRate($subscription, $interval, $quantity);
        $tierLabel = Plan::tierLabel($tier);

        $steps = [[
            'label' => "List price ({$interval})",
            'amount' => $list,
            'detail' => $tierLabel
                ? "per branch · {$tierLabel} rate"
                : 'per branch',
        ]];

        // AUD-04 — a bespoke price only applies at the interval it was agreed
        // FOR. ₹3,500 is a bargain per year and a 12x overcharge per month, and
        // before this the same number was used for both: switching a customer's
        // billing period silently re-priced them. When the intervals disagree we
        // fall back to the list price and SAY SO, rather than quietly charging
        // a number nobody agreed.
        $negotiated = null;
        $negotiatedMismatch = false;

        if ($subscription->hasNegotiatedPrice()) {
            if ($subscription->negotiatedPriceApplies($interval)) {
                $negotiated = (float) $subscription->negotiated_price;
                $steps[] = [
                    'label' => 'Negotiated price',
                    'amount' => $negotiated,
                    'detail' => 'per branch, agreed with you',
                ];
            } else {
                $negotiatedMismatch = true;
                $steps[] = [
                    'label' => 'Negotiated price ignored — agreed per '
                        . $subscription->negotiatedInterval(),
                    'amount' => (float) $subscription->negotiated_price,
                ];
            }
        }

        $unit = round(max(0.0, $negotiated ?? $list), 2);

        // REQ-15 — the branch multiplier, always shown as its own step even at
        // one branch. A line that appears only sometimes is a line nobody
        // learns to read.
        $steps[] = [
            'label' => $quantity === 1 ? '1 branch' : "{$quantity} branches",
            'amount' => round($unit * $quantity, 2),
            'detail' => '₹ ' . number_format($unit, 2) . ' × ' . $quantity,
        ];

        return [
            'interval' => $interval,
            'quantity' => $quantity,
            'unit_price' => $unit,
            'unit_source' => $negotiated !== null ? 'negotiated' : $listSource,
            'tier' => $tier,
            'tier_label' => $tierLabel,
            'list_price' => $list,
            'list_source' => $listSource,
            'negotiated_price' => $negotiated,
            'negotiated_interval' => $subscription->negotiatedInterval(),
            'negotiated_mismatch' => $negotiatedMismatch,
            'effective' => round($unit * $quantity, 2),   // rounded once, at the boundary
            'source' => $negotiated !== null ? 'negotiated' : $listSource,
            'steps' => $steps,
        ];
    }

    /** What this subscription costs per period, for all its branches. */
    public function effectivePrice(Subscription $subscription, ?string $interval = null, ?int $quantity = null): float
    {
        return $this->breakdown($subscription, $interval, $quantity)['effective'];
    }

    /**
     * The rate for ONE branch — what a proration multiplies by, and what the
     * per-branch line on a receipt shows.
     */
    public function unitPrice(Subscription $subscription, ?string $interval = null, ?int $quantity = null): float
    {
        return $this->breakdown($subscription, $interval, $quantity)['unit_price'];
    }

    /** @return array{0: float, 1: string, 2: array|null} [rate, 'plan'|'config', tier] */
    private function listRate(Subscription $subscription, string $interval, int $quantity): array
    {
        if ($subscription->plan) {
            [$rate, $tier] = $subscription->plan->rateFor($interval, $quantity);

            return [$rate, 'plan', $tier];
        }

        // Safety net for unseeded databases (tests, fresh installs mid-deploy).
        $plan = config('billing.plans.' . ($subscription->tier ?? 'basic'), []);
        $price = $interval === 'yearly'
            ? (float) ($plan['yearly_price'] ?? 0)
            : (float) ($plan['monthly_price'] ?? 0);

        return [$price, 'config', null];
    }
}
