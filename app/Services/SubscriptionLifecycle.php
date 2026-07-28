<?php

namespace App\Services;

use App\Models\AdminAuditLog;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
        'force_expire', 'set_price', 'clear_price', 'mark_paid', 'waive',
    ];

    public function __construct(
        private readonly PriceResolver $prices,
        private readonly PaymentRecorder $payments,
    ) {}

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
            DB::transaction(function () use ($subscription, $action, $input, &$after, &$ledgerAfter) {
                $this->apply($subscription, $action, $input, audit: false);

                $after = $this->snapshot($subscription->refresh());
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

    /** Execute for real, audited. */
    public function commit(Subscription $subscription, string $action, array $input = []): Subscription
    {
        return DB::transaction(function () use ($subscription, $action, $input) {
            return $this->apply($subscription, $action, $input, audit: true);
        });
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
            'renew' => $this->doRenew($s, $input),
            'set_price' => $this->doSetPrice($s, $input['price'] ?? null, $reason, $input['interval'] ?? null),
            'clear_price' => $this->doClearPrice($s, $reason),
            'suspend' => $this->doSuspend($s, $reason),
            'reactivate' => $this->doReactivate($s, $reason),
            'cancel' => $this->doCancel($s, $reason, (bool) ($input['at_period_end'] ?? false)),
            'force_expire' => $this->doForceExpire($s, $reason),
            'switch_interval' => $this->doSwitchInterval($s, (string) ($input['interval'] ?? '')),
            'mark_paid' => $this->doMarkPaid($s, $input, $reason),
            'waive' => $this->doWaive($s, $reason),
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
        }

        $s->current_period_end = $base->copy()->addMonths($months);
        $s->cancel_at_period_end = false;
        $s->applyOverride('comp', $s->current_period_end, $reason);

        // A grant is a ₹0 LEDGER ROW, never an absent one — otherwise lifetime
        // value is wrong for every comped customer (playbook §3.2 / BUG-P04).
        $this->payments->recordComp($s, $reason, $base, $s->current_period_end);
    }

    // ---- Row 5 · Renewal (manual) -----------------------------------

    private function doRenew(Subscription $s, array $input): void
    {
        $method = (string) ($input['method'] ?? 'cash');
        $interval = (string) ($input['interval'] ?? $s->interval ?? 'monthly');
        $amount = ($input['amount'] ?? null) !== null && $input['amount'] !== ''
            ? (float) $input['amount']
            : $this->prices->effectivePrice($s, $interval);

        // AUD-05 - a zero-rupee "cash payment" is a lie in the ledger: no money
        // moved. Giving time away for free is what a comp is for, and a comp
        // carries a reason. Refuse rather than silently record a payment that
        // never happened. (A blank amount still falls back to the list price.)
        if ($amount <= 0.0) {
            throw new InvalidArgumentException('A renewal needs an amount. To give them time for free, use Grant free access instead - it records a zero-value entry with your reason.');
        }

        $base = $this->clockBase($s);
        $end = $interval === 'yearly' ? $base->copy()->addYear() : $base->copy()->addMonth();

        $this->payments->record($s, $amount, $method, [
            'reference' => $input['reference'] ?? null,
            'period_start' => $base,
            'period_end' => $end,
            'reason' => $input['reason'] ?? null,
        ]);

        $s->status = 'active';
        $s->interval = $interval;
        $s->current_period_end = $end;
        $s->cancel_at_period_end = false;

        // Manual wins for this cycle — the webhook must not undo a renewal the
        // operator just took money for.
        $s->applyOverride('manual_renewal', $end, $input['reason'] ?? 'Manual renewal');
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
    }

    // ---- Row 9 · Expiry ----------------------------------------------

    private function doForceExpire(Subscription $s, string $reason): void
    {
        $s->status = 'canceled';
        $s->current_period_end = now()->subDay()->toDateString();
        $s->cancel_at_period_end = false;
        $s->applyOverride('cancellation', null, $reason);
    }

    // ---- Row 13 · Interval switch ------------------------------------

    private function doSwitchInterval(Subscription $s, string $interval): void
    {
        if (! in_array($interval, ['monthly', 'yearly'], true)) {
            throw new InvalidArgumentException('Interval must be monthly or yearly.');
        }

        $s->interval = $interval;
        $s->pending_interval = null;
    }

    // ---- Row 8 · Failed payment --------------------------------------

    private function doMarkPaid(Subscription $s, array $input, string $reason): void
    {
        $amount = $input['amount'] !== null && $input['amount'] !== ''
            ? (float) $input['amount']
            : $this->prices->effectivePrice($s);

        $this->payments->record($s, $amount, (string) ($input['method'] ?? 'cash'), [
            'reference' => $input['reference'] ?? null,
            'reason' => $reason,
        ]);

        $s->status = 'active';

        // Manual wins and cancels conflicting automation: the override stops
        // dunning from re-marking this past_due behind the operator's back.
        $s->applyOverride('manual_renewal', $s->current_period_end, $reason);
    }

    private function doWaive(Subscription $s, string $reason): void
    {
        $s->status = 'active';
        $s->applyOverride('comp', $s->current_period_end, $reason);
        $this->payments->recordComp($s, "Waived: {$reason}");
    }

    // ---------------------------------------------------------------
    // Gateway lane (P0) — unchanged contract
    // ---------------------------------------------------------------

    /**
     * Apply a gateway event's entitlement changes — unless an operator override
     * is in force, in which case the subscription is left exactly as the human
     * left it.
     *
     * ⚠ ENTITLEMENT ONLY. The caller records the payment in the ledger BEFORE
     * calling this and must keep doing so: money that actually changed hands is
     * always recorded, whatever the operator decided about access.
     *
     * @return bool true if the gateway's values were applied, false if suppressed.
     */
    public function applyGatewayEntitlement(
        Subscription $subscription,
        ?string $status,
        ?Carbon $periodEnd,
        ?string $interval,
    ): bool {
        if ($subscription->hasActiveOverride()) {
            $this->auditSuppression($subscription, $status, $periodEnd);

            return false;
        }

        if ($status) {
            $subscription->status = $status;

            if ($status === 'canceled') {
                $subscription->cancel_at_period_end = false;
            }
        }

        if ($periodEnd) {
            $subscription->current_period_end = $periodEnd;
        }

        if ($interval) {
            $subscription->interval = $interval;

            if ($subscription->pending_interval === $interval) {
                $subscription->pending_interval = null;
            }
        }

        $subscription->save();

        return true;
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

        if ($action === 'switch_interval' && $s->hasNegotiatedPrice()) {
            $out[] = 'Their negotiated price of Rs ' . number_format((float) $s->negotiated_price, 2)
                . ' was agreed per ' . $s->negotiatedInterval()
                . '. On a different billing period they revert to the list price until you agree a new one.';
        }

        return $out;
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
            'access' => $s->accessState(),
            'interval' => $s->interval,
            'current_period_end' => $s->current_period_end?->toDateString(),
            'cancel_at_period_end' => (bool) $s->cancel_at_period_end,
            'negotiated_price' => $s->negotiated_price !== null ? (float) $s->negotiated_price : null,
            'negotiated_interval' => $s->negotiatedInterval(),
            'effective_price' => $this->prices->effectivePrice($s),
            'override_kind' => $s->override_kind,
            'override_until' => $s->override_until?->toDateString(),
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
            'renew' => "Renewed {$who} manually",
            'set_price' => "Set {$who} to a negotiated price of ₹{$input['price']}",
            'clear_price' => "Cleared {$who}'s negotiated price — back to list",
            'suspend' => "Suspended {$who}",
            'reactivate' => "Reactivated {$who}",
            'cancel' => "Cancelled {$who}",
            'force_expire' => "Force-expired {$who}",
            'switch_interval' => "Switched {$who} to {$input['interval']} billing",
            'mark_paid' => "Marked {$who}'s payment as received",
            'waive' => "Waived {$who}'s outstanding payment",
            default => "{$action} on {$who}",
        };
    }

    private function auditSuppression(Subscription $subscription, ?string $status, ?Carbon $periodEnd): void
    {
        AdminAuditLog::record(
            'subscription.webhook_suppressed',
            "Razorpay update ignored — {$subscription->override_kind} override is in force",
            $subscription->tenant_id,
            [
                'override' => [
                    'kind' => $subscription->override_kind,
                    'until' => $subscription->override_until?->toDateString(),
                    'reason' => $subscription->override_reason,
                ],
                'kept' => [
                    'status' => $subscription->status,
                    'current_period_end' => $subscription->current_period_end?->toDateString(),
                ],
                'ignored' => [
                    'status' => $status,
                    'current_period_end' => $periodEnd?->toDateString(),
                ],
            ],
        );
    }
}
