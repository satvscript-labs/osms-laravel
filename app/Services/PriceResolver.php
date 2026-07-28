<?php

namespace App\Services;

use App\Models\Subscription;

/**
 * P1 / REQ-4 — the ONE place a price is computed.
 *
 * Every quote, every modal preview, every charge, every receipt and the MRR
 * figure read from here, so a preview can never disagree with what is charged
 * (playbook §3.5 / §1.7 "quote before charge").
 *
 * Resolution order:
 *   negotiated_price          ← a hand-agreed deal, if set (⚑ bespoke)
 *   ∥ plan row's list price   ← the database, the operator-editable authority
 *   ∥ config('billing.plans') ← seed-default safety net only (unseeded tests)
 *
 * Returns an ITEMISED breakdown, not one number — deliberately, so the
 * co-termination work parked as PR-13 becomes a new step in the breakdown
 * rather than a rewrite (owner's Q-C scalability requirement).
 *
 * NO discount stacking: the owner has no discounts ("we don't have discounts
 * and everything, nothing"). A single negotiated override is the entire model.
 */
class PriceResolver
{
    /**
     * The full, itemised price breakdown for a subscription.
     *
     * @return array{
     *   interval: string,
     *   list_price: float,
     *   list_source: string,
     *   negotiated_price: float|null,
     *   negotiated_interval: string|null,
     *   negotiated_mismatch: bool,
     *   effective: float,
     *   source: string,
     *   steps: list<array{label: string, amount: float}>,
     * }
     */
    public function breakdown(Subscription $subscription, ?string $interval = null): array
    {
        $interval = $interval ?: ($subscription->interval ?: 'monthly');

        [$list, $listSource] = $this->listPrice($subscription, $interval);

        $steps = [['label' => "List price ({$interval})", 'amount' => $list]];

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
                $steps[] = ['label' => 'Negotiated price', 'amount' => $negotiated];
            } else {
                $negotiatedMismatch = true;
                $steps[] = [
                    'label' => 'Negotiated price ignored — agreed per '
                        . $subscription->negotiatedInterval(),
                    'amount' => (float) $subscription->negotiated_price,
                ];
            }
        }

        $effective = $negotiated ?? $list;

        return [
            'interval' => $interval,
            'list_price' => $list,
            'list_source' => $listSource,
            'negotiated_price' => $negotiated,
            'negotiated_interval' => $subscription->negotiatedInterval(),
            'negotiated_mismatch' => $negotiatedMismatch,
            'effective' => round(max(0.0, $effective), 2), // floor at zero, round once
            'source' => $negotiated !== null ? 'negotiated' : $listSource,
            'steps' => $steps,
        ];
    }

    /** The single number: what this subscription actually costs per period. */
    public function effectivePrice(Subscription $subscription, ?string $interval = null): float
    {
        return $this->breakdown($subscription, $interval)['effective'];
    }

    /** @return array{0: float, 1: string} [price, 'plan'|'config'] */
    private function listPrice(Subscription $subscription, string $interval): array
    {
        if ($subscription->plan) {
            return [$subscription->plan->priceFor($interval), 'plan'];
        }

        // Safety net for unseeded databases (tests, fresh installs mid-deploy).
        $plan = config('billing.plans.' . ($subscription->tier ?? 'basic'), []);
        $price = $interval === 'yearly'
            ? (float) ($plan['yearly_price'] ?? 0)
            : (float) ($plan['monthly_price'] ?? 0);

        return [$price, 'config'];
    }
}
