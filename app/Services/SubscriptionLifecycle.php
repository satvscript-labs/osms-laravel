<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AdminAuditLog;
use App\Models\BillingCharge;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * The single place entitlement state is mutated (P0 / BUG-P01, grown in P3).
 *
 * Both lanes — the automated Razorpay webhook and the operator's manual actions —
 * route through here, so "a human decision outlives the robot" holds uniformly
 * instead of being re-implemented (and forgotten) per caller.
 *
 * Every public action below follows the same contract, which is what makes the
 * panel trustworthy (03 §4.1 / playbook §5.2):
 *
 *   1. PREVIEW and COMMIT share this code path — `preview()` runs the action
 *      inside a rolled-back transaction, so what the operator was shown is
 *      exactly what happens. They cannot drift.
 *   2. Discretionary actions REQUIRE a reason. Not a nicety: six months on, the
 *      audit trail must say *why* a store is free until 2031.
 *   3. Manual decisions are STICKY — they set an override the webhook obeys.
 *   4. Every mutation is AUDITED with before → after.
 *   5. Nothing here silently reduces coverage; callers state before → after.
 */
class SubscriptionLifecycle
{
    /** Actions that cannot commit without an operator's stated reason. */
    private const REASON_REQUIRED = [
        'extend', 'comp', 'suspend', 'reactivate', 'cancel',
        'force_expire', 'set_price', 'clear_price',
        // mark_paid / waive: enforced by Settlement, which now runs them (E2.3).
    ];

    public function __construct(
        private readonly PriceResolver $prices,
        private readonly PaymentRecorder $payments,
    ) {}

    // ---------------------------------------------------------------
    // E1.2 — the only door, and the lock behind it
    // ---------------------------------------------------------------

    /** How deep we are inside writing(). Re-entrant; always unwound by `finally`. */
    private static int $writing = 0;

    /**
     * Is a subscription write happening on the engine's behalf right now?
     * `Subscription`'s write guard (E1.5) refuses every write when this is false.
     */
    public static function isWriting(): bool
    {
        return self::$writing > 0;
    }

    /**
     * Run $fn with subscription writes allowed. Only the engine — and the one-off
     * BackfillAccounts bootstrap — may call this; an architecture test enforces
     * the list (Phase84E1GuardTest). The counter is unwound in `finally`, so an
     * exception can never leave the door open in a long-running queue worker.
     */
    public static function writing(Closure $fn): mixed
    {
        self::$writing++;

        try {
            return $fn();
        } finally {
            self::$writing--;
        }
    }

    /**
     * E1.2 / FB-15 — act on the subscription's row LOCKED for this transaction.
     *
     * The caller's instance may be stale (read at page load, or by a webhook a
     * moment ago). The row is re-read FOR UPDATE, so an operator action and a
     * webhook for the same customer are serialised and each sees the other's
     * result. The caller's instance is refreshed only AFTER the commit, so it can
     * never hold values that were rolled back.
     *
     * @template T
     * @param  Closure(Subscription): T  $fn
     * @return T
     */
    private function locked(Subscription $subscription, Closure $fn): mixed
    {
        [$row, $result] = MoneyWrite::transaction(fn () => self::writing(function () use ($subscription, $fn) {
            $row = $this->lockRow($subscription);

            return [$row, $fn($row)];
        }));

        $subscription->setRawAttributes($row->getAttributes(), true);

        return $result;
    }

    private function lockRow(Subscription $subscription): Subscription
    {
        return Subscription::withoutGlobalScopes()
            ->whereKey($subscription->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }
    // ---------------------------------------------------------------
    // Preview — the "quote before charge" contract
    // ---------------------------------------------------------------

    /**
     * Run an action WITHOUT committing, and report exactly what it would do.
     *
     * The action really executes, inside a transaction that is always rolled
     * back — so the preview cannot disagree with the commit, because it *is*
     * the commit, undone. A hand-written "what would happen" summary is the
     * thing that silently rots; this cannot.
     */
    public function preview(Subscription $subscription, string $action, array $input = []): array
    {
        $before = $this->snapshot($subscription);
        $warnings = $this->warningsFor($subscription, $action);
        $ledgerBefore = SubscriptionInvoice::withoutGlobalScopes()
            ->where('account_id', $subscription->account_id)->count();

        $after = $before;
        $ledgerAfter = $ledgerBefore;
        $error = null;

        try {
            DB::transaction(function () use ($subscription, $action, $input, &$before, &$warnings, &$after, &$ledgerAfter) {
                // The same locked row and write scope as a commit (E1.2), so the
                // dry run exercises exactly the path the commit will take — and
                // "before" and the warnings are read from that SAME fresh row, not
                // from the caller's copy, which a webhook may have overtaken.
                $row = self::writing(function () use ($subscription, $action, $input, &$before, &$warnings, &$after) {
                    $row = $this->lockRow($subscription);
                    $before = $after = $this->snapshot($row);
                    $warnings = [
                        ...$this->warningsFor($row, $this->warnsLike($action, $input)),
                        ...$this->runForPreview($row, $action, $input),
                    ];

                    return $row;
                });

                $after = $this->snapshot($row->refresh());
                $ledgerAfter = SubscriptionInvoice::withoutGlobalScopes()
                    ->where('account_id', $subscription->account_id)->count();

                // Always undo — this is a dry run by construction.
                throw new PreviewRollback();
            });
        } catch (PreviewRollback) {
            // expected - this is how the dry run unwinds
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
        } catch (\Throwable $e) {
            // AUD-09 - previews run on every keystroke; one must never 500 the
            // endpoint or vanish without trace. Give the operator something
            // actionable and log the detail for us.
            report($e);
            $error = 'Could not work out what this would do. Nothing has changed.';
        }

        // The in-memory model was mutated during the rolled-back attempt;
        // reload so the caller is never handed a dirty object.
        $subscription->refresh();

        return [
            'action' => $action,
            'error' => $error,
            'warnings' => $warnings,
            'before' => $before,
            'after' => $after,
            'changes' => $this->diff($before, $after),
            'ledger_rows_added' => $ledgerAfter - $ledgerBefore,
        ];
    }

    /**
     * E2 — what a dry run executes: the money actions through Settlement / the
     * engine (exactly the path their commit takes), everything else through
     * apply(). Returns any extra warnings the dry run found.
     *
     * @return list<string>
     */
    private function runForPreview(Subscription $row, string $action, array $input): array
    {
        if (in_array($action, Settlement::TERM_ACTIONS, true)) {
            app(Settlement::class)->payTerm($row, $action, $input);

            return [];
        }

        if ($action === 'record_payment') {
            // D-4 — over the bill: show what WOULD happen, and say so in amber.
            // The commit itself still refuses until the operator confirms.
            $warnings = [];
            $bill = ($input['for'] ?? '') === 'renewal'
                ? app(BillingEngine::class)->quoteTerm($row, ($input['interval'] ?? null) ?: null, (int) (($input['cycles'] ?? null) ?: 1))['amount']
                : (float) $this->accountCharge($row, (string) ($input['for'] ?? ''))->amount;
            $given = $input['amount'] ?? null;

            if ($given !== null && $given !== '' && (float) $given > $bill + 0.005) {
                $warnings[] = Settlement::overMessage((float) $given, $bill);
                $input['confirm_over'] = 1;
            }

            ($input['for'] ?? '') === 'renewal'
                ? app(Settlement::class)->payTerm($row, 'renew', $input)
                : app(Settlement::class)->settle($this->accountCharge($row, (string) $input['for']), $input);

            return $warnings;
        }

        if ($action === 'void_charge') {
            app(BillingEngine::class)->void($this->accountCharge($row, (string) ($input['charge'] ?? '')), (string) ($input['reason'] ?? ''));

            return [];
        }

        if ($action === 'apply_unapplied') {
            $u = $this->accountUnapplied($row, (string) ($input['unapplied'] ?? ''));
            ($input['charge'] ?? '') === 'renewal'
                ? app(Settlement::class)->applyUnappliedToRenewal($u, $row, (string) ($input['note'] ?? ''))
                : app(Settlement::class)->applyUnapplied($u, $this->accountCharge($row, (string) ($input['charge'] ?? '')), (string) ($input['note'] ?? ''));

            return [];
        }

        if ($action === 'waive_charge') {
            app(Settlement::class)->waive($this->accountCharge($row, (string) ($input['charge'] ?? '')), (string) ($input['reason'] ?? ''));

            return [];
        }

        $this->apply($row, $action, $input, audit: false);

        return [];
    }

    /** Which existing warnings apply: paying the next term warns like a renewal. */
    private function warnsLike(string $action, array $input): string
    {
        if ($action !== 'record_payment') {
            return $action;
        }

        $for = (string) ($input['for'] ?? '');

        // A-9 — the next renewal, or an open TERM bill: both buy time, both warn alike.
        $isTerm = $for === 'renewal' || BillingCharge::query()->whereKey($for)
            ->whereIn('kind', ['first_term', 'renewal'])->exists();

        return $isTerm ? 'renew' : $action;
    }

    /** Unapplied money already matched to this subscription's account — never another's. */
    private function accountUnapplied(Subscription $s, string $id): \App\Models\UnappliedPayment
    {
        $u = $id === '' ? null : \App\Models\UnappliedPayment::query()->whereKey($id)->where('account_id', $s->account_id)->first();

        if (! $u) {
            throw new InvalidArgumentException('That payment is not on this customer\'s account.');
        }

        return $u;
    }

    /** A charge of this subscription's account — never another's (isolation). */
    private function accountCharge(Subscription $s, string $id): BillingCharge
    {
        if ($id === '') {
            throw new InvalidArgumentException('Choose the bill this is for.');
        }

        $charge = BillingCharge::query()->whereKey($id)->where('account_id', $s->account_id)->first();

        if (! $charge) {
            throw new InvalidArgumentException('That bill is not on this customer\'s account.');
        }

        return $charge;
    }

    /** Execute for real, audited. */
    public function commit(Subscription $subscription, string $action, array $input = []): Subscription
    {
        // E2.3 — renew / mark paid / waive are money: a charge is raised for the next
        // term and settled, so the payment says what it paid for (rule 6). The
        // coverage still comes back here, through grantTerm().
        if (in_array($action, Settlement::TERM_ACTIONS, true)) {
            app(Settlement::class)->payTerm($subscription, $action, $input);
            $subscription->refresh();

            return $subscription;
        }

        $this->locked($subscription, fn (Subscription $row) => $this->apply($row, $action, $input, audit: true));

        // E1.10 / rule 5 — an ending reaches the gateway, AFTER the commit: the
        // operator's decision is never blocked or undone by a gateway hiccup.
        if ($action === 'cancel') {
            $this->stopGatewayCharging($subscription, immediately: ! (bool) ($input['at_period_end'] ?? false), why: 'cancelled by the operator');
        } elseif ($action === 'force_expire') {
            $this->stopGatewayCharging($subscription, immediately: true, why: 'access ended by the operator');
        }

        return $subscription;
    }

    // ---------------------------------------------------------------
    // The action table — one entry per dual-lane matrix row
    // ---------------------------------------------------------------

    private function apply(Subscription $s, string $action, array $input, bool $audit): Subscription
    {
        $reason = trim((string) ($input['reason'] ?? ''));

        if (in_array($action, self::REASON_REQUIRED, true) && $reason === '') {
            throw new InvalidArgumentException("A reason is required to {$action} — it is a discretionary decision and the audit trail must record why.");
        }

        $before = $this->snapshot($s);

        match ($action) {
            'extend' => $this->doExtend($s, (int) ($input['days'] ?? 0), $reason),
            'comp' => $this->doComp($s, (int) ($input['months'] ?? 0), $reason, $input['interval'] ?? null),
            'set_price' => $this->doSetPrice($s, $input['price'] ?? null, $reason, $input['interval'] ?? null),
            'clear_price' => $this->doClearPrice($s, $reason),
            'suspend' => $this->doSuspend($s, $reason),
            'reactivate' => $this->doReactivate($s, $reason),
            'cancel' => $this->doCancel($s, $reason, (bool) ($input['at_period_end'] ?? false)),
            'force_expire' => $this->doForceExpire($s, $reason),
            'switch_interval' => $this->doSwitchInterval($s, (string) ($input['interval'] ?? '')),
            default => throw new InvalidArgumentException("Unknown lifecycle action [{$action}]."),
        };

        // Legacy flag, superseded by override_* but still read by the legacy
        // store screens. Kept in step so those views do not silently change.
        $s->manual = true;

        $s->save();

        if ($audit) {
            AdminAuditLog::record(
                "subscription.{$action}",
                $this->describe($action, $s, $input),
                $s->tenant_id,
                [
                    'account_id' => $s->account_id,
                    'reason' => $reason ?: null,
                    'input' => collect($input)->except(['password', 'password_confirmation', '_token'])->all(),
                    'before' => $before,
                    'after' => $this->snapshot($s),
                ],
            );
        }

        return $s;
    }

    // ---- Row 3 · Trial extended -------------------------------------

    private function doExtend(Subscription $s, int $days, string $reason): void
    {
        if ($days < 1 || $days > 365) {
            throw new InvalidArgumentException('Extend by between 1 and 365 days.');
        }

        // Extend from the LATER of today or the existing end, so an in-window
        // trial is lengthened rather than silently shortened.
        $base = $this->clockBase($s);
        $s->current_period_end = $base->copy()->addDays($days);
        $s->cancel_at_period_end = false;

        if ($s->status === 'canceled') {
            $s->status = 'trialing';
        }

        $s->applyOverride('extension', $s->current_period_end, $reason);
    }

    // ---- Row 6 · Comp / favour --------------------------------------

    private function doComp(Subscription $s, int $months, string $reason, ?string $interval = null): void
    {
        if ($months < 1 || $months > 60) {
            throw new InvalidArgumentException('Comp for between 1 and 60 months.');
        }

        $base = $this->clockBase($s);
        $s->status = 'active';

        // An operator may state the cadence the comp represents; otherwise the
        // existing one stands.
        if (in_array($interval, ['monthly', 'yearly'], true)) {
            $s->interval = $interval;
            $s->pending_interval = null;
        }

        $s->current_period_start = $base->toDateString();
        $s->current_period_end = $base->copy()->addMonths($months);
        $s->cancel_at_period_end = false;
        $s->applyOverride('comp', $s->current_period_end, $reason);

        // A grant is a ₹0 LEDGER ROW, never an absent one — otherwise lifetime
        // value is wrong for every comped customer (playbook §3.2 / BUG-P04).
        $this->payments->recordComp($s, $reason, $base, $s->current_period_end);

        // E2-F — the free months cover the term that was being asked for. A
        // branch's part period is NOT covered (it ran before the grant starts).
        // A-11 — but never a reopened bill: that is debt for time they already had.
        app(BillingEngine::class)->voidOpen($s, "Covered by free access: {$reason}", ['first_term', 'renewal'], keepReopened: true);
    }

    // ---- Row 7 · Price change ---------------------------------------

    private function doSetPrice(Subscription $s, mixed $price, string $reason, ?string $interval = null): void
    {
        if (! is_numeric($price) || (float) $price < 0) {
            throw new InvalidArgumentException('A negotiated price must be zero or more.');
        }

        $s->negotiated_price = round((float) $price, 2);
        // AUD-04 - pin the interval it was agreed FOR, so switching billing
        // period cannot silently re-price them by 12x in either direction.
        $s->negotiated_interval = $interval ?: ($s->interval ?: 'monthly');
        $s->negotiated_reason = $reason;
        $s->negotiated_by = auth()->id();
        $s->negotiated_at = now();
    }

    private function doClearPrice(Subscription $s, string $reason): void
    {
        $s->negotiated_price = null;
        $s->negotiated_interval = null;
        $s->negotiated_reason = null;
        $s->negotiated_by = auth()->id();
        $s->negotiated_at = now();
    }

    // ---- Row 10 · Suspension ----------------------------------------

    private function doSuspend(Subscription $s, string $reason): void
    {
        // ⚠ The clock is PRESERVED. Suspension cuts access without destroying
        // what the customer paid for — the gap BUG-P06 identified in the old
        // panel, where the only lever was cancel.
        $s->status = 'past_due';
        $s->applyOverride('suspension', null, $reason);
    }

    private function doReactivate(Subscription $s, string $reason): void
    {
        $s->clearOverride();

        // AUD-03 - reactivate must undo a SCHEDULED cancellation too. It used
        // to clear the override and restore status but leave
        // `cancel_at_period_end` set, so the operator believed they had undone
        // the cancellation while the store was still queued to lapse - and the
        // Razorpay webhook would act on that flag.
        $s->cancel_at_period_end = false;

        // Their paid period may still be running; if so they are simply active
        // again, and nothing was lost by the suspension.
        $s->status = $s->current_period_end && $s->current_period_end->gte(now()->startOfDay())
            ? 'active'
            : 'past_due';

        // AUD-08 - no separate audit row here. apply() already writes one for
        // every action with before -> after and the reason; a second row made
        // the trail's row count meaningless as a measure of operator activity.
    }

    // ---- Row 12 · Cancellation --------------------------------------

    private function doCancel(Subscription $s, string $reason, bool $atPeriodEnd): void
    {
        if ($atPeriodEnd) {
            // Decision C3 — no refunds, so access continues to the end of the
            // period they already paid for. This is the default, humane path.
            $s->cancel_at_period_end = true;
            $s->applyOverride('cancellation', $s->current_period_end, $reason);

            return;
        }

        $s->status = 'canceled';
        $s->cancel_at_period_end = false;
        $s->applyOverride('cancellation', null, $reason);

        // E2-E — the relationship ends now: stop chasing what is open, visibly.
        app(BillingEngine::class)->voidOpen($s, "Cancelled: {$reason}");
    }

    // ---- Row 9 · Expiry ----------------------------------------------

    private function doForceExpire(Subscription $s, string $reason): void
    {
        $s->status = 'canceled';
        // AUD-A09 — yesterday in the BILLING timezone, like every other clock
        // operation here. `now()` is UTC, which put the date up to two calendar
        // days back in IST terms and made "ends today" behave differently
        // depending on the hour the operator clicked.
        $s->current_period_end = Carbon::today(config('billing.timezone', 'Asia/Kolkata'))
            ->subDay()->toDateString();
        $s->cancel_at_period_end = false;
        $s->applyOverride('cancellation', null, $reason);

        // E2-E — access ended now: open charges are voided, with the reason.
        app(BillingEngine::class)->voidOpen($s, "Access ended: {$reason}");
    }

    // ---- Row 13 · Interval switch ------------------------------------

    private function doSwitchInterval(Subscription $s, string $interval): void
    {
        if (! in_array($interval, ['monthly', 'yearly'], true)) {
            throw new InvalidArgumentException('Interval must be monthly or yearly.');
        }

        // E1.4 / FB-19 — from the NEXT period, from both doors. `interval` keeps
        // meaning the period now running; switching it mid-cycle re-priced a
        // period the customer had already paid for (and made MRR jump). Choosing
        // the period they are already on simply cancels a pending switch.
        $s->pending_interval = $interval === ($s->interval ?: 'monthly') ? null : $interval;
    }

    /** E1.4 — at the start of a new period, a pending switch becomes the period's interval. */
    private function landPendingInterval(Subscription $s): void
    {
        if ($s->pending_interval) {
            $s->interval = $s->pending_interval;
            $s->pending_interval = null;
        }
    }

    // ---- Rows 5 / 8 · A term paid for (or waived) -------------------

    /**
     * E2.3 — the coverage a paid TERM charge buys. Only Settlement calls this, after
     * it has the money (rule 2: nothing unpaid changes access).
     *
     * The rules every renewal has always followed, now in one place:
     *   • branches are re-counted first (REQ-15 / AUDIT-03: a closed branch stops
     *     being billed at the renewal);
     *   • from the clock base — the later of the period end and today — so early
     *     payment loses no days and a late one pays for none it couldn't use (D-7);
     *   • for the charge's cycles at the charge's interval (what they paid for);
     *     any pending switch is resolved by this renewal (E1.4);
     *   • sticky against the gateway until the end ("manual wins for this cycle"),
     *     or a comp override when it was waived.
     *
     * @return array{0: Carbon, 1: Carbon} the coverage actually granted
     */
    public function grantTerm(Subscription $subscription, BillingCharge $charge, string $action, string $reason, bool $free, array $input = []): array
    {
        return $this->locked($subscription, function (Subscription $s) use ($charge, $action, $reason, $free, $input) {
            $before = $this->snapshot($s);
            $this->syncQuantity($s);

            $interval = $charge->interval ?: ($s->interval ?: 'monthly');
            $base = $this->clockBase($s);
            $end = BillingCycle::add($base, $interval, max(1, (int) $charge->cycles));

            $s->status = 'active';
            $s->interval = $interval;
            $s->pending_interval = null;
            $s->current_period_start = $base->toDateString();
            $s->current_period_end = $end;
            $s->cancel_at_period_end = false;
            $s->applyOverride($free ? 'comp' : 'manual_renewal', $end, $reason !== '' ? $reason : 'Manual renewal');
            $s->manual = true;
            $s->save();

            AdminAuditLog::record("subscription.{$action}", $this->describe($action, $s, $input), $s->tenant_id, [
                'account_id' => $s->account_id,
                'reason' => $reason ?: null,
                'charge' => $charge->number,
                // E1.12 — paid online: the audit names the gateway, not "System".
                'actor' => $input['actor'] ?? null,
                'input' => collect($input)->except(['password', 'password_confirmation', '_token', '_once'])->all(),
                'before' => $before,
                'after' => $this->snapshot($s),
            ]);

            return [$base, $end];
        });
    }

    // ---------------------------------------------------------------
    // E1.3 — the writers that used to live outside the engine
    // ---------------------------------------------------------------

    /**
     * W3 — the subscription a new store's ACCOUNT starts with (Tenant::booted()).
     *
     * L-2: the ACCOUNT row is locked first and "does it already have a clock?"
     * re-asked under that lock, so two stores created for one customer at the
     * same instant can no longer both mint a trial (two clocks, two renewal dates).
     *
     * P.2 kept: an account that has already had its trial gets a LOCKED
     * subscription and a warning, never a second free fortnight.
     */
    public function startTrial(Tenant $tenant): ?Subscription
    {
        return MoneyWrite::transaction(fn () => self::writing(function () use ($tenant) {
            $account = $tenant->account_id
                ? Account::query()->whereKey($tenant->account_id)->lockForUpdate()->first()
                : null;

            if ($tenant->account_id
                && Subscription::withoutGlobalScopes()->where('account_id', $tenant->account_id)->exists()) {
                return null;   // a branch joins its payer's existing clock (REQ-12)
            }

            $tz = config('billing.timezone', 'Asia/Kolkata');
            $planId = Plan::query()->where('code', 'basic')->value('id');

            if ($account?->trial_used_at) {
                $locked = $tenant->subscription()->create([
                    'status' => 'canceled',
                    'tier' => 'basic',
                    'account_id' => $tenant->account_id,
                    'plan_id' => $planId,
                    'current_period_start' => now($tz)->subDay()->toDateString(),
                    'current_period_end' => now($tz)->subDay(),
                ]);

                Log::warning(
                    'A store was added to an account that has already used its free trial and has no subscription; created it locked rather than minting a second trial.',
                    ['account_id' => $tenant->account_id, 'tenant_id' => $tenant->id],
                );

                return $locked;
            }

            $trial = $tenant->subscription()->create([
                'status' => 'trialing',
                'tier' => 'basic',
                // Account-bound from birth when the tenant knows its account
                // (StoreProvisioner sets it before create). Pre-backfill tenants
                // pass null; osms:backfill-accounts fills those in.
                'account_id' => $tenant->account_id,
                'plan_id' => $planId,
                'current_period_start' => now($tz)->toDateString(),
                'current_period_end' => now($tz)->addDays((int) config('billing.trial_days', 14)),
            ]);

            // Stamp it once; never overwrite an earlier date.
            if ($account && ! $account->trial_used_at) {
                Account::query()->whereKey($account->id)->whereNull('trial_used_at')->update(['trial_used_at' => now()]);
            }

            return $trial;
        }));
    }

    /**
     * W5 — the terms an operator provisions a customer on: plan, trial length.
     * Zero trial days is a real choice (they paid on the spot), not "unset".
     */
    public function applyProvisionTerms(Subscription $subscription, ?string $planCode, ?int $trialDays): void
    {
        $this->locked($subscription, function (Subscription $s) use ($planCode, $trialDays) {
            if ($planCode !== null && $planCode !== '') {
                $plan = Plan::query()->where('code', $planCode)->first();

                if ($plan) {
                    $s->plan_id = $plan->id;
                    $s->tier = $plan->code;
                }
            }

            if ($trialDays !== null) {
                $tz = config('billing.timezone', 'Asia/Kolkata');
                $s->current_period_start = now($tz)->toDateString();
                $s->current_period_end = now($tz)->addDays($trialDays);
            }

            if ($s->isDirty()) {
                $s->save();
            }
        });
    }

    /**
     * W4 — one more branch on the account's clock. Returns the new quantity.
     * The part-period charge is still recorded by StoreProvisioner (FB-18 → E2).
     */
    public function addBranch(Subscription $subscription): int
    {
        return $this->locked($subscription, function (Subscription $s) {
            $s->quantity = (int) ($s->quantity ?: 1) + 1;
            $s->save();

            return $s->quantity;
        });
    }

    /**
     * W6 — the nightly clock. Each change is re-checked on the LOCKED row: the
     * candidate list was read moments (or minutes) earlier, and an operator may
     * have renewed, comped or suspended the customer since. An operator override
     * is never touched (playbook §5.2 rule 2). Every change is audited as
     * `system` (FB-23) — the trail used to have no record of these at all.
     */
    public function expireTrial(Subscription $subscription): bool
    {
        return $this->locked($subscription, function (Subscription $s) {
            if ($s->status !== 'trialing' || $s->hasActiveOverride() || $s->accessState() !== 'locked') {
                return false;
            }

            $before = $this->snapshot($s);
            $s->status = 'canceled';
            $s->save();

            $this->auditAs('system', 'subscription.trial_expired', 'Trial ended without a payment', $s, $before);

            return true;
        });
    }

    /** W6 — a paying customer past their period and grace: active → past_due. */
    public function lapse(Subscription $subscription): bool
    {
        return $this->locked($subscription, function (Subscription $s) {
            if ($s->status !== 'active' || $s->hasActiveOverride() || $s->accessState() !== 'locked') {
                return false;
            }

            $before = $this->snapshot($s);
            $s->status = 'past_due';
            $s->save();

            $this->auditAs('system', 'subscription.lapsed', 'Not renewed by the end of the grace window', $s, $before);

            return true;
        });
    }

    /**
     * W6 — unpaid long enough to be churn: past_due → canceled, and the gateway
     * is told to stop (rule 5; it was never told before).
     */
    public function lapseToChurn(Subscription $subscription, int $graceDays, int $graceToCancelDays): bool
    {
        $changed = $this->locked($subscription, function (Subscription $s) use ($graceDays, $graceToCancelDays) {
            if ($s->status !== 'past_due' || $s->hasActiveOverride()
                || $s->accessState() !== 'locked' || ! $s->current_period_end) {
                return false;
            }

            $tz = config('billing.timezone', 'Asia/Kolkata');
            $deadline = Carbon::parse($s->current_period_end->toDateString(), $tz)
                ->addDays($graceDays + $graceToCancelDays)
                ->endOfDay();

            if (! now($tz)->greaterThan($deadline)) {
                return false;
            }

            $before = $this->snapshot($s);
            $s->status = 'canceled';
            $s->save();

            $this->auditAs('system', 'subscription.churned', 'Unpaid past the grace window and '
                . $graceToCancelDays . ' days more — treated as churn', $s, $before);

            return true;
        });

        if ($changed) {
            $this->stopGatewayCharging($subscription, immediately: true, why: 'lapsed to churn');
        }

        return $changed;
    }

    /** W8 — the owner asked to switch billing period, from their next renewal (E1.4). */
    public function ownerRequestInterval(Subscription $subscription, string $interval): void
    {
        if (! in_array($interval, ['monthly', 'yearly'], true)) {
            throw new InvalidArgumentException('Interval must be monthly or yearly.');
        }

        $this->locked($subscription, function (Subscription $s) use ($interval) {
            $before = $this->snapshot($s);
            $keep = $interval === ($s->interval ?: 'monthly');
            $s->pending_interval = $keep ? null : $interval;
            $s->save();

            $this->auditAs('owner', 'subscription.owner_interval', $keep
                ? "Owner cancelled the pending switch and stays on {$interval} billing"
                : "Owner switched to {$interval} billing from their next renewal", $s, $before);
        });
    }

    /** W8 — the owner cancelled; access continues to the end of what they paid for. */
    public function ownerCancelAtPeriodEnd(Subscription $subscription): void
    {
        $this->locked($subscription, function (Subscription $s) {
            $before = $this->snapshot($s);
            $s->cancel_at_period_end = true;
            $s->save();

            $this->auditAs('owner', 'subscription.owner_cancel', 'Owner cancelled at the end of the current period', $s, $before);
        });

        // Rule 5 — a legacy autopay (if any) stops at the cycle end, after the commit.
        $this->stopGatewayCharging($subscription, immediately: false, why: 'cancelled by the owner');
    }

    /**
     * E1.10 / rule 5 — stop automated charging at the gateway. Best effort, AFTER
     * the local decision has committed; a failure is logged and audited (never
     * thrown), so a gateway outage can neither block nor undo an operator.
     */
    public function stopGatewayCharging(Subscription $subscription, bool $immediately, string $why): void
    {
        // Only once the decision is durable: immediately when no transaction is
        // open, otherwise after the OUTERMOST commit — and never if it rolls back.
        DB::afterCommit(fn () => $this->callGatewayStop($subscription, $immediately, $why));
    }

    private function callGatewayStop(Subscription $subscription, bool $immediately, string $why): void
    {
        $gatewayId = $subscription->razorpay_subscription_id;

        if (! $gatewayId) {
            return;
        }

        $billing = app(BillingService::class);

        if (! $billing->isConfigured()) {
            return;
        }

        try {
            $billing->cancelSubscription($gatewayId, atCycleEnd: ! $immediately);

            AdminAuditLog::record('subscription.gateway_cancelled', 'Razorpay subscription cancelled '
                . ($immediately ? 'immediately' : 'at the end of the cycle') . " — {$why}", $subscription->tenant_id, [
                    'account_id' => $subscription->account_id,
                    'gateway_subscription_id' => $gatewayId,
                ]);
        } catch (\Throwable $e) {
            Log::warning('Could not cancel the Razorpay subscription after an ending.', [
                'account_id' => $subscription->account_id,
                'gateway_subscription_id' => $gatewayId,
                'error' => $e->getMessage(),
            ]);

            AdminAuditLog::record('subscription.gateway_cancel_failed', "Razorpay did not confirm the cancellation — {$why}. Cancel it in the Razorpay dashboard.", $subscription->tenant_id, [
                'account_id' => $subscription->account_id,
                'gateway_subscription_id' => $gatewayId,
                'error' => mb_substr($e->getMessage(), 0, 300),
            ]);
        }
    }

    /** An audit row for a change nobody in the panel made: `system`, `gateway` or `owner`. */
    private function auditAs(string $actor, string $action, string $description, Subscription $s, array $before, array $extra = []): void
    {
        AdminAuditLog::record($action, $description, $s->tenant_id, [
            'account_id' => $s->account_id,
            'actor' => $actor,
            'before' => $before,
            'after' => $this->snapshot($s),
        ] + $extra);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * AUD-06 / AUD-04 - things the operator should be told BEFORE committing,
     * which are not errors and do not block the action.
     *
     * The panel must never let one deliberate decision quietly erase another:
     * granting a comp over a live suspension is probably intended, but the
     * suspension's reason vanishes from the record and nothing said so.
     *
     * @return list<string>
     */
    private function warningsFor(Subscription $s, string $action): array
    {
        $out = [];

        if (in_array($action, ['comp', 'extend', 'renew', 'mark_paid', 'waive'], true)
            && $s->override_kind === 'suspension'
            && $s->hasActiveOverride()) {
            $out[] = 'This replaces the suspension in force'
                . ($s->override_reason ? ' (' . $s->override_reason . ')' : '')
                . ' and restores their access.';
        }

        // A-8 — they asked to leave at the end of this period; a renewal (or a
        // free cycle) clears that. Probably intended — but never silently.
        if (in_array($action, ['renew', 'mark_paid', 'waive', 'comp', 'extend'], true) && $s->cancel_at_period_end) {
            $out[] = 'They are set to cancel at the end of this period'
                . ($s->current_period_end ? ' (' . $s->current_period_end->format('d M Y') . ')' : '')
                . '. This undoes that cancellation and keeps them on.';
        }

        if ($action === 'switch_interval' && $s->hasNegotiatedPrice()) {
            $out[] = 'Their negotiated price of Rs ' . number_format((float) $s->negotiated_price, 2)
                . ' was agreed per ' . $s->negotiatedInterval()
                . '. From their next renewal, on a different billing period, they revert to the list price until you agree a new one.';
        }

        // E1.10 — an ending now reaches the gateway, and a cancelled Razorpay
        // subscription cannot be revived. Say both BEFORE the operator commits.
        if ($s->razorpay_subscription_id) {
            if ($action === 'switch_interval') {
                $out[] = 'They pay by Razorpay autopay, which stays on its current plan: this switch applies to renewals you take by hand. To change what autopay charges, they switch from their own billing page.';
            }

            if (in_array($action, ['cancel', 'force_expire'], true)) {
                $out[] = 'This also cancels their Razorpay autopay. It cannot be restarted from here: to pay online again they would subscribe afresh.';
            }

            if (in_array($action, ['reactivate', 'renew', 'extend', 'comp', 'mark_paid', 'waive'], true)
                && $s->override_kind === 'cancellation') {
                $out[] = 'Their Razorpay autopay was cancelled with the cancellation and does not restart: bill them by hand, or they subscribe again online.';
            }
        }

        return $out;
    }

    /**
     * REQ-15 — reconcile what they are billed for with what they actually have.
     *
     * `quantity` is stored rather than derived because a receipt has to be able
     * to reproduce itself months later, after the branch count has moved again.
     * Storing it means it can drift, so it is re-synced at every renewal — the
     * one moment where "what are we charging for?" is being answered anyway.
     */
    private function syncQuantity(Subscription $s): void
    {
        if (! $s->account) {
            return;
        }

        $billable = $s->account->billableStores()->count();

        // Never below 1: an account with every store closed still has a clock
        // until somebody cancels it, and quantity 0 would price it at ₹0 —
        // which would read as a comp rather than as the anomaly it is.
        $s->quantity = max(1, $billable);
    }

    /** Never shorten coverage by accident: extend from today or the end, whichever is later. */
    private function clockBase(Subscription $s): Carbon
    {
        $tz = config('billing.timezone', 'Asia/Kolkata');
        $today = Carbon::today($tz);

        $end = $s->current_period_end
            ? Carbon::parse($s->current_period_end->toDateString(), $tz)
            : $today;

        return $end->max($today);
    }

    private function snapshot(Subscription $s): array
    {
        return [
            'status' => $s->status,
            // What the preview SHOWS: a suspension is stored as past_due (house rule, QA-06).
            'display_status' => $s->displayStatus(),
            'access' => $s->accessState(),
            'interval' => $s->interval,
            'pending_interval' => $s->pending_interval,
            'current_period_start' => $s->current_period_start?->toDateString(),
            'current_period_end' => $s->current_period_end?->toDateString(),
            'cancel_at_period_end' => (bool) $s->cancel_at_period_end,
            'negotiated_price' => $s->negotiated_price !== null ? (float) $s->negotiated_price : null,
            'negotiated_interval' => $s->negotiatedInterval(),
            'effective_price' => $this->prices->effectivePrice($s),
            'override_kind' => $s->override_kind,
            'override_until' => $s->override_until?->toDateString(),
            // E2 — what the account owes; a preview shows it move (voids, settles).
            'owed' => app(BillingEngine::class)->owed($s->account_id),
        ];
    }

    /** Only the fields that actually moved — what the confirm dialog shows. */
    private function diff(array $before, array $after): array
    {
        $out = [];

        foreach ($after as $key => $value) {
            if (($before[$key] ?? null) !== $value) {
                $out[$key] = ['from' => $before[$key] ?? null, 'to' => $value];
            }
        }

        return $out;
    }

    private function describe(string $action, Subscription $s, array $input): string
    {
        $who = $s->account?->displayName() ?? 'account';

        return match ($action) {
            'extend' => "Extended {$who} by {$input['days']} days",
            'comp' => "Granted {$who} {$input['months']} months of free access",
            'renew' => match ($input['source'] ?? null) {
                'gateway' => "Renewed {$who} — paid online",
                'unapplied' => "Renewed {$who} from money received online",
                default => "Renewed {$who} manually",
            },
            'set_price' => "Set {$who} to a negotiated price of ₹{$input['price']}",
            'clear_price' => "Cleared {$who}'s negotiated price — back to list",
            'suspend' => "Suspended {$who}",
            'reactivate' => "Reactivated {$who}",
            'cancel' => "Cancelled {$who}",
            'force_expire' => "Force-expired {$who}",
            'switch_interval' => "Set {$who} to switch to {$input['interval']} billing from their next renewal",
            'mark_paid' => "Marked {$who}'s payment as received",
            'waive' => "Waived {$who}'s outstanding payment",
            default => "{$action} on {$who}",
        };
    }
}
