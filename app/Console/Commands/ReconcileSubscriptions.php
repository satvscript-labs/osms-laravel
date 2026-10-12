<?php

namespace App\Console\Commands;

use App\Mail\TrialStatusMail;
use App\Models\Subscription;
use App\Services\SubscriptionLifecycle;
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

    public function handle(SubscriptionLifecycle $lifecycle): int
    {
        // Runs unauthenticated → bypass the tenant scope to see every store.
        $trials = Subscription::withoutGlobalScopes()
            ->where('status', 'trialing')
            ->whereNotNull('current_period_end')
            ->with(['tenant.users', 'account.owner'])
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
                // E1.3 (W6) — through the engine: re-checked under a row lock (an
                // operator may have acted since this list was read) and audited.
                if ($lifecycle->expireTrial($sub)) {
                    $this->notifyAdmins($sub, 0); // trial ended
                    $expired++;
                }

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

        $lapsed = $this->lapsePaidSubscriptions($lifecycle);

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
    private function lapsePaidSubscriptions(SubscriptionLifecycle $lifecycle): int
    {
        $changed = 0;

        $candidates = Subscription::withoutGlobalScopes()
            ->whereIn('status', ['active', 'past_due'])
            ->whereNotNull('current_period_end')
            ->get();

        foreach ($candidates as $sub) {
            // A cheap pre-filter only. The engine re-checks every condition on the
            // LOCKED row before changing anything, audits it as `system`, and tells
            // the gateway on churn (E1.3 / E1.10).
            if ($sub->hasActiveOverride() || $sub->accessState() !== 'locked') {
                continue; // a human decided this, or still inside period + grace
            }

            $changed += $sub->status === 'active'
                ? (int) $lifecycle->lapse($sub)
                // Already past_due and locked — churn once unpaid long enough
                // that this is no longer a late payment.
                : (int) $lifecycle->lapseToChurn($sub, $this->graceDays(), self::GRACE_TO_CANCEL_DAYS);
        }

        return $changed;
    }

    private function graceDays(): int
    {
        return (int) config('billing.grace_days', 7);
    }

    /**
     * Queue a trial-status email to the PAYER.
     *
     * E1.9 / FB-26 — the account's owner (and its billing address) hears about
     * the account's trial; the stores' admins are the fallback. It used to go via
     * `$sub->tenant` alone, so an account whose original store had been purged
     * (tenant_id NULL, by design since P.1) got no reminder at all.
     */
    private function notifyAdmins(Subscription $sub, int $daysLeft): void
    {
        $account = $sub->account;
        $tenant = $sub->tenant ?? $account?->openStores()->orderBy('created_at')->first();

        if (! $tenant) {
            return;
        }

        $emails = collect([$account?->owner?->email, $account?->billing_email])
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($emails === []) {
            $emails = $tenant->users
                ->where('role', 'store_admin')
                ->pluck('email')
                ->filter()
                ->all();
        }

        if ($emails === []) {
            return;
        }

        Mail::to($emails)->queue(new TrialStatusMail($tenant, $daysLeft));
    }
}
