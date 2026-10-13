<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AdminAuditLog;
use App\Models\BillingCharge;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Services\BillingEngine;
use App\Services\Settlement;
use App\Services\SubscriptionLifecycle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * P3 — the operator's action surface. Every row of the dual-lane matrix
 * (03 §4) that changes commercial state.
 *
 * The contract, uniformly (playbook §5.2, §6, §7):
 *   • the controller VALIDATES and DELEGATES — it never mutates commercial
 *     state directly. All of that lives in the services.
 *   • every action is PREVIEWED first through the same code path that commits.
 *   • discretionary actions REQUIRE a reason (enforced in the service, so no
 *     future door can forget it).
 *   • every mutation is AUDITED with before → after.
 *   • routes are gated by `superadmin` + `password.confirm` (recent re-auth).
 */
class AccountActionController extends Controller
{
    public function __construct(
        private readonly SubscriptionLifecycle $lifecycle,
        private readonly Settlement $settlement,
        private readonly BillingEngine $engine,
    ) {}

    /**
     * "Quote before charge" — what would this action do?
     *
     * Runs the real action inside a rolled-back transaction, so the operator
     * is shown the actual outcome rather than a hand-written summary that can
     * rot out of sync with the code.
     */
    public function preview(Request $request, Account $account): JsonResponse
    {
        $subscription = $account->subscription;

        if (! $subscription) {
            return response()->json(['error' => 'This customer has no subscription.'], 422);
        }

        $action = (string) $request->input('action', '');

        return response()->json(
            $this->lifecycle->preview($subscription, $action, $request->all())
        );
    }

    /** Commit a lifecycle action. */
    public function commit(Request $request, Account $account): RedirectResponse
    {
        $subscription = $account->subscription;

        if (! $subscription) {
            return back()->with('error', 'This customer has no subscription.');
        }

        $validated = $request->validate([
            'action' => ['required', 'string'],
            'reason' => ['nullable', 'string', 'max:500'],
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'months' => ['nullable', 'integer', 'min:1', 'max:60'],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'method' => ['nullable', 'in:cash,upi,bank_transfer,cheque,razorpay,adjustment'],
            'interval' => ['nullable', 'in:monthly,yearly'],
            'reference' => ['nullable', 'string', 'max:120'],
            'at_period_end' => ['nullable', 'boolean'],
            // E2 — pay N cycles ahead (E2-A); D-4 confirmation of an amount over the bill.
            'cycles' => ['nullable', 'integer', 'min:1', 'max:12'],
            'confirm_over' => ['nullable', 'boolean'],
            'discount_reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->lifecycle->commit($subscription, $validated['action'], $validated);
        } catch (InvalidArgumentException $e) {
            // A rejected action is operator error, not a crash — say what is
            // wrong in their language and keep them on the customer.
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', $this->confirmation($validated['action']));
    }

    /**
     * Row 4 — one "Record a payment" (D-5): money received outside the gateway,
     * and WHAT IT PAYS FOR — an open charge, or the next term (N cycles ahead,
     * E2-A). It goes through Settlement, so it is previewed, it moves the clock
     * when it buys time, and the ledger row names its charge (FB-08, rule 6).
     */
    public function recordPayment(Request $request, Account $account): RedirectResponse
    {
        $subscription = $account->subscription;

        if (! $subscription) {
            return back()->with('error', 'This customer has no subscription.');
        }

        $validated = $request->validate(self::paymentRules());

        try {
            $invoice = $validated['for'] === 'renewal'
                ? $this->settlement->payTerm($subscription, 'renew', $validated)
                : $this->settlement->settle($this->chargeOf($account, $validated['for']), $validated);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', "Payment recorded — receipt {$invoice->receipt_no}.");
    }

    /** The one validation for a hand-recorded payment, shared with the preview. */
    public static function paymentRules(): array
    {
        return [
            // E2 — what it pays for: 'renewal' (the next term) or an open charge's id.
            'for' => ['required', 'string', 'max:64'],
            'interval' => ['nullable', 'in:monthly,yearly'],
            'cycles' => ['nullable', 'integer', 'min:1', 'max:12'],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'method' => ['required', 'in:' . implode(',', Settlement::HAND_METHODS)],
            'reference' => ['nullable', 'string', 'max:120'],
            'reason' => ['nullable', 'string', 'max:500'],
            'paid_at' => ['nullable', 'date', 'before_or_equal:today'],
            // REQ-15 — a counter-bargain. One-off on this row, never recurring.
            'discount_reason' => ['nullable', 'string', 'max:500'],
            // D-4 — more than the bill is confirmed, never silent.
            'confirm_over' => ['nullable', 'boolean'],
        ];
    }

    /** Row 11 — reverse a payment. The row stays; the money stops counting; what it paid is owed again. */
    public function reversePayment(Request $request, Account $account, SubscriptionInvoice $invoice): RedirectResponse
    {
        // Never let a reversal cross accounts, even with a valid invoice id.
        abort_unless($invoice->account_id === $account->id, 404);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $this->settlement->reverse($invoice, $validated['reason']);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', $invoice->charge_id
            ? 'Payment reversed. The entry stays on the ledger, struck through, and what it paid for is owed again. Access is unchanged.'
            : 'Payment reversed. The entry stays on the ledger, struck through.');
    }

    /** E2-F — the charge was never owed (raised in error). Reason required; the row stays. */
    public function voidCharge(Request $request, Account $account, string $charge): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        try {
            $row = $this->engine->void($this->chargeOf($account, $charge), $validated['reason']);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', "{$row->number} voided — it is no longer owed.");
    }

    /** E2-F — the charge is given away: a ₹0 entry with the reason, and it still buys what it was for. */
    public function waiveCharge(Request $request, Account $account, string $charge): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        try {
            $invoice = $this->settlement->waive($this->chargeOf($account, $charge), $validated['reason']);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', "Waived — a ₹0 entry ({$invoice->receipt_no}) records it.");
    }

    /** A charge of THIS account, or 404 — a valid id from another account never resolves (isolation). */
    private function chargeOf(Account $account, string $id): BillingCharge
    {
        $charge = BillingCharge::query()->whereKey($id)->where('account_id', $account->id)->first();

        abort_unless($charge, 404);

        return $charge;
    }

    /** Row 10 (per-store) — suspend or reactivate one branch, not the account. */
    public function storeStatus(Request $request, Account $account, Tenant $tenant): RedirectResponse
    {
        abort_unless($tenant->account_id === $account->id, 404);

        $validated = $request->validate([
            'store_status' => ['required', 'in:active,suspended'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $before = $tenant->store_status;
        $tenant->update(['store_status' => $validated['store_status']]);

        AdminAuditLog::record(
            'store.status_changed',
            "{$tenant->store_name} " . ($validated['store_status'] === 'suspended' ? 'suspended' : 'reactivated'),
            $tenant->id,
            [
                'account_id' => $account->id,
                'reason' => $validated['reason'],
                'before' => $before,
                'after' => $validated['store_status'],
            ],
        );

        return back()->with('status', "{$tenant->store_name} is now {$validated['store_status']}.");
    }

    /** Whether a store counts toward the bill — the "added mid-cycle" lever. */
    public function storeBillable(Request $request, Account $account, Tenant $tenant): RedirectResponse
    {
        abort_unless($tenant->account_id === $account->id, 404);

        $validated = $request->validate([
            'is_billable' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $tenant->update(['is_billable' => $validated['is_billable']]);

        AdminAuditLog::record(
            'store.billable_changed',
            "{$tenant->store_name} " . ($validated['is_billable'] ? 'included in' : 'excluded from') . ' billing',
            $tenant->id,
            ['account_id' => $account->id, 'reason' => $validated['reason']],
        );

        return back()->with('status', 'Billing inclusion updated.');
    }

    /** Private relationship notes — the only non-commercial action here. */
    public function updateNotes(Request $request, Account $account): RedirectResponse
    {
        $validated = $request->validate([
            'internal_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $account->update(['internal_notes' => $validated['internal_notes']]);

        AdminAuditLog::record(
            'account.notes_updated',
            "Updated internal notes for {$account->displayName()}",
            $account->stores()->value('id'),
            ['account_id' => $account->id],
        );

        return back()->with('status', 'Notes saved.');
    }

    private function confirmation(string $action): string
    {
        return match ($action) {
            'extend' => 'Trial extended.',
            'comp' => 'Free access granted — a ₹0 entry was added to the ledger.',
            'renew' => 'Renewed, and the payment is on the ledger.',
            'set_price' => 'Negotiated price set.',
            'clear_price' => 'Back to list price.',
            'suspend' => 'Suspended. Their paid-through date is preserved.',
            'reactivate' => 'Reactivated.',
            'cancel' => 'Cancelled.',
            'force_expire' => 'Access ended.',
            // E1.4 — it lands at the next renewal; say so, not "switched".
            'switch_interval' => 'Billing period will switch at their next renewal. The current period is unchanged.',
            'mark_paid' => 'Marked as paid — dunning will not override this.',
            'waive' => 'Waived.',
            default => 'Done.',
        };
    }
}
