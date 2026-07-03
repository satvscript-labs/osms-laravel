<?php

namespace App\Console\Commands;

use App\Mail\TrialStatusMail;
use App\Models\Subscription;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

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

    /** Days-left thresholds that trigger a "trial ending soon" reminder. */
    private const REMINDER_DAYS = [3, 1];

    public function handle(): int
    {
        // Runs unauthenticated → bypass the tenant scope to see every store.
        $trials = Subscription::withoutGlobalScopes()
            ->where('status', 'trialing')
            ->whereNotNull('current_period_end')
            ->with('tenant.users')
            ->get();

        $expired = 0;
        $reminded = 0;

        foreach ($trials as $sub) {
            if ($sub->accessState() === 'locked') {
                $sub->status = 'canceled';
                $sub->save();
                $this->notifyAdmins($sub, 0); // trial ended
                $expired++;
                continue;
            }

            if (in_array($sub->trialDaysLeft(), self::REMINDER_DAYS, true)) {
                $this->notifyAdmins($sub, $sub->trialDaysLeft());
                $reminded++;
            }
        }

        $this->info("Reconciled {$expired} expired trial(s); sent {$reminded} reminder(s).");

        return self::SUCCESS;
    }

    /** Queue a trial-status email to the store's admin(s). */
    private function notifyAdmins(Subscription $sub, int $daysLeft): void
    {
        $tenant = $sub->tenant;
        if (! $tenant) {
            return;
        }

        $emails = $tenant->users
            ->where('role', 'store_admin')
            ->pluck('email')
            ->filter()
            ->all();

        if ($emails === []) {
            return;
        }

        Mail::to($emails)->queue(new TrialStatusMail($tenant, $daysLeft));
    }
}
