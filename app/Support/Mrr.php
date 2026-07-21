<?php

namespace App\Support;

use App\Models\Subscription;

/**
 * ST-Admin (S11) — normalises a subscription's price to a monthly figure so
 * yearly and monthly plans can be summed into a single MRR number.
 */
class Mrr
{
    /** Monthly-recurring value of a subscription in INR (0 if not paying). */
    public static function monthlyValue(?Subscription $sub): float
    {
        if (! $sub || $sub->status !== 'active') {
            return 0.0;
        }

        // WEB-03 — a `manual` subscription was granted by a superadmin rather than
        // paid for (comped / internal), so counting it inflates MRR with revenue that
        // does not exist. Excluded here; the superadmin dashboard reports the comped
        // count separately so those stores stay visible rather than silently dropped.
        if ($sub->manual) {
            return 0.0;
        }

        $plan = config('billing.plans.' . ($sub->tier ?? 'basic'));
        if (! $plan) {
            return 0.0;
        }

        return $sub->interval === 'yearly'
            ? round(($plan['yearly_price'] ?? 0) / 12, 2)
            : (float) ($plan['monthly_price'] ?? 0);
    }
}
