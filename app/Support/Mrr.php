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

        $effective = app(PriceResolver::class)->effectivePrice($sub);

        return $sub->interval === 'yearly'
            ? round($effective / 12, 2)
            : round($effective, 2);
    }
}
