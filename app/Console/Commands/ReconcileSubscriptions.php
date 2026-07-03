<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use Illuminate\Console\Command;

/**
 * ST-Enforce (S1) — daily reconciliation.
 *
 * Access is derived live from Subscription::accessState(), so this command is
 * the belt-and-braces layer: it flips trials that have run out to a terminal
 * `canceled` state so reporting (and the superadmin panel) stay accurate even
 * when nobody logs in. Lifecycle emails (trial-ending / dunning) attach here
 * in ST-Email.
 */
class ReconcileSubscriptions extends Command
{
    protected $signature = 'subscriptions:reconcile';

    protected $description = 'Flip expired trials to a terminal state so subscription reporting stays accurate.';

    public function handle(): int
    {
        // Runs unauthenticated → bypass the tenant scope to see every store.
        $expiredTrials = Subscription::withoutGlobalScopes()
            ->where('status', 'trialing')
            ->whereNotNull('current_period_end')
            ->get()
            ->filter(fn (Subscription $sub) => $sub->accessState() === 'locked');

        foreach ($expiredTrials as $sub) {
            $sub->status = 'canceled';
            $sub->save();
        }

        $this->info("Reconciled {$expiredTrials->count()} expired trial(s).");

        return self::SUCCESS;
    }
}
