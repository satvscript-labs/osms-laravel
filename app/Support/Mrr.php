<?php

namespace App\Support;

use App\Models\Subscription;
use App\Services\PriceResolver;

/**
 * ST-Admin (S11) — normalises a subscription's price to a monthly figure so
 * yearly and monthly plans can be summed into a single MRR number.
 *
 * P1 / BUG-P05 — the rule changed, deliberately. `manual` used to mean "not
 * paying" and contributed 0, which would have hidden 100% of manually-billed
 * revenue the moment the manual lane shipped: a store paying ₹3,500/yr in
 * cash is REAL revenue, recorded by hand, with manual=true.
 *
 * The rule now: **MRR counts what the store actually pays — the effective
 * price from the one PriceResolver. Only a genuine comp contributes 0.**
 * A comp is identifiable as such (override_kind='comp' in force), not
 * inferred from who last touched the record.
 */
class Mrr
{
    /** Monthly-recurring value of a subscription in INR (0 if not paying). */
    public static function monthlyValue(?Subscription $sub): float
    {
        if (! $sub || $sub->status !== 'active') {
            return 0.0;
        }

        // AUD-01 — a subscription past its period end is NOT revenue, whatever
        // its status says.
        //
        // Nothing used to move a paid subscription off `active` when its period
        // lapsed (the daily reconcile only handled trials), so a customer who
        // simply stopped paying counted toward MRR and "Paying" forever. This
        // check makes the figure right *immediately*, independently of whether
        // the reconcile job has run — belt and braces, because MRR is the first
        // number on the operator's home screen.
        //
        // Deliberately measured from the period END, not from the end of the
        // grace window: during grace they have not yet paid for the new period,
        // so counting them would be claiming money nobody has sent.
        if ($sub->current_period_end && $sub->current_period_end->endOfDay()->isPast()) {
            return 0.0;
        }

        // A comped store pays nothing while the grant is in force. It stays
        // visible — the superadmin dashboard counts comps separately — it just
        // isn't revenue.
        if ($sub->override_kind === 'comp' && $sub->hasActiveOverride()) {
            return 0.0;
        }

        // AUD-A05 — a customer with no store left open is not revenue.
        //
        // Closure lives on the STORE and money lives on the ACCOUNT, so a
        // customer whose every shop was closed kept contributing to MRR
        // indefinitely: the subscription knew nothing about it. Leaving the
        // clock running when ONE branch closes is deliberate (a three-branch
        // customer must not stop paying because one shut), but when the last
        // one closes there is nothing being sold, and counting it is fiction
        // of exactly the kind AUD-01 was raised about.
        if ($sub->account && ! $sub->account->hasOpenStore()) {
            return 0.0;
        }

        /*
         * REQ-15 — MRR is priced at the branches that will RECUR, not at the
         * quantity currently on the invoice. The two differ for exactly one
         * cycle and the distinction matters:
         *
         *   `quantity`          what they are billed for right now. A branch
         *                       closed mid-cycle is still in it, because
         *                       decision D4 says no refund — they keep what
         *                       they paid for until renewal.
         *   billable branches   what will still be there next cycle.
         *
         * MRR is a forward run-rate. Counting a branch that has already been
         * closed would overstate it for a month, which is the same shape as
         * AUD-01 (revenue that has stopped still being counted) — just shorter.
         * `effectivePrice` deliberately keeps using the stored quantity,
         * because charging is a different question from forecasting.
         */
        $recurring = $sub->account
            ? max(1, $sub->account->billableStoreCount())
            : (int) ($sub->quantity ?: 1);

        $effective = app(PriceResolver::class)->effectivePrice($sub, null, $recurring);

        return $sub->interval === 'yearly'
            ? round($effective / 12, 2)
            : round($effective, 2);
    }
}
