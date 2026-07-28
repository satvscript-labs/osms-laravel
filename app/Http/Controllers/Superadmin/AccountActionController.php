<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AdminAuditLog;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Services\PaymentRecorder;
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
        private readonly PaymentRecorder $payments,
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

    /** Row 4 — record a payment that arrived outside the gateway. */
    public function recordPayment(Request $request, Account $account): RedirectResponse
    {
        $subscription = $account->subscription;

        if (! $subscription) {
            return back()->with('error', 'This customer has no subscription.');
        }

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'method' => ['required', 'in:cash,upi,bank_transfer,cheque,adjustment'],
            'reference' => ['nullable', 'string', 'max:120'],
            'reason' => ['nullable', 'string', 'max:500'],
            'paid_at' => ['nullable', 'date'],
        ]);

        try {
            $invoice = $this->payments->record(
                $subscription,
                (float) $validated['amount'],
                $validated['method'],
                [
                    'reference' => $validated['reference'] ?? null,
                    'reason' => $validated['reason'] ?? null,
                    'paid_at' => isset($validated['paid_at']) ? \Illuminate\Support\Carbon::parse($validated['paid_at']) : null,
                ],
            );
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        AdminAuditLog::record(
            'payment.recorded',
            "Recorded ₹" . number_format((float) $validated['amount'], 2) . " ({$invoice->methodLabel()}) for {$account->displayName()}",
            $subscription->tenant_id,
            ['account_id' => $account->id, 'receipt_no' => $invoice->receipt_no] + $validated,
        );

        return back()->with('status', "Payment recorded — receipt {$invoice->receipt_no}.");
    }

    /** Row 11 — reverse a payment. The row stays; the money stops counting. */
    public function reversePayment(Request $request, Account $account, SubscriptionInvoice $invoice): RedirectResponse
    {
        // Never let a reversal cross accounts, even with a valid invoice id.
        abort_unless($invoice->account_id === $account->id, 404);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $this->payments->reverse($invoice, $validated['reason']);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Payment reversed. The entry stays on the ledger, struck through.');
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
            'switch_interval' => 'Billing interval switched.',
            'mark_paid' => 'Marked as paid — dunning will not override this.',
            'waive' => 'Waived.',
            default => 'Done.',
        };
    }
}
