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

    /**
     * AUD-01 — how long a locked, unpaid subscription sits at `past_due` before
     * it is treated as churn and cancelled. Measured from the END of the grace
     * window, so the full silence is grace + this. Owner decision: 30 days.
     */
    private const GRACE_TO_CANCEL_DAYS = 30;

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

        $suppressed = 0;

        foreach ($trials as $sub) {
            // P4 / playbook §5.2 rule 5 — a manual action CANCELS conflicting
            // automation, explicitly.
            //
            // "Wire the suppression explicitly — don't rely on the jobs
            // coincidentally not firing." The lapse below happens not to fire
            // during an extension (because access isn't locked yet), but the
            // REMINDERS would have: a customer the operator just comped, or
            // suspended, or extended as a goodwill gesture, would still be
            // emailed "your trial ends in 3 days". Chasing someone you have
            // just made a decision about is the most visible way a panel can
            // look like it isn't in control.
            if ($sub->hasActiveOverride()) {
                $suppressed++;
                continue;
            }

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

        if ($suppressed > 0) {
            $this->line("Skipped {$suppressed} subscription(s) under an operator override.");
        }

        $lapsed = $this->lapsePaidSubscriptions();

        $this->info("Reconciled {$expired} expired trial(s); sent {$reminded} reminder(s); lapsed {$lapsed} paid subscription(s).");

        return self::SUCCESS;
    }

    /**
     * AUD-01 — move PAID subscriptions that nobody renewed to a terminal state.
     *
     * Previously this command only handled trials, so a paying customer who
     * simply stopped paying stayed `active` forever: access was correctly cut
     * by `accessState()`, but they counted toward MRR and the "Paying" figure
     * indefinitely. That made the headline revenue number overstate by every
     * churned-but-not-cancelled customer.
     *
     *   active   + past the grace window        → past_due
     *   past_due + GRACE_TO_CANCEL days beyond  → canceled
     *
     * ⚠ An operator override is NEVER touched. A comped, extended, suspended or
     * manually-renewed subscription is a human decision, and this job
     * reconciles — it does not overrule people (playbook §5.2 rule 2).
     */
    private function lapsePaidSubscriptions(): int
    {
        $changed = 0;

        $candidates = Subscription::withoutGlobalScopes()
            ->whereIn('status', ['active', 'past_due'])
            ->whereNotNull('current_period_end')
            ->get();

        foreach ($candidates as $sub) {
            if ($sub->hasActiveOverride()) {
                continue; // a human decided this; leave it alone
            }

            if ($sub->accessState() !== 'locked') {
                continue; // still inside its period or its grace window
            }

            if ($sub->status === 'active') {
                $sub->status = 'past_due';
                $sub->save();
                $changed++;

                continue;
            }

            // Already past_due and locked — cancel once it has been unpaid long
            // enough that this is churn rather than a late payment.
            $deadline = $sub->current_period_end
                ->copy()
                ->addDays($this->graceDays() + self::GRACE_TO_CANCEL_DAYS);

            if (now()->greaterThan($deadline->endOfDay())) {
                $sub->status = 'canceled';
                $sub->save();
                $changed++;
            }
        }

        return $changed;
    }

    private function graceDays(): int
    {
        return (int) config('billing.grace_days', 7);
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
