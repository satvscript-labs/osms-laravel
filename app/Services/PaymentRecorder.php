<?php

namespace App\Services;

use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * P1 / REQ-5 — every non-webhook money event enters the ledger through here.
 *
 * The rule this enforces (playbook §3.2): one ledger, one shape. A cash renewal,
 * a UPI payment, a ₹0 comp and a Razorpay auto-debit are the SAME record,
 * differing only in method + source — never a parallel "manual" table.
 *
 * BUG-P04 lives here: a complimentary grant is a ₹0 ROW with method='comp' and
 * a required reason — never an absent row — so lifetime value and "what have we
 * given away?" stay answerable from one query.
 */
class PaymentRecorder
{
    /** Methods whose rows are discretionary and therefore need a reason. */
    private const REASON_REQUIRED = ['comp', 'adjustment'];

    private const METHODS = ['razorpay', 'cash', 'upi', 'bank_transfer', 'cheque', 'comp', 'adjustment'];

    /**
     * Write one ledger row for a subscription payment (or grant).
     *
     * @param array{
     *   reference?: string|null,
     *   reason?: string|null,
     *   period_start?: Carbon|null,
     *   period_end?: Carbon|null,
     *   recorded_by?: int|null,
     *   source?: string,
     *   paid_at?: Carbon|null,
     * } $options
     */
    public function record(
        Subscription $subscription,
        float $amount,
        string $method,
        array $options = [],
    ): SubscriptionInvoice {
        if (! in_array($method, self::METHODS, true)) {
            throw new InvalidArgumentException("Unknown payment method [{$method}].");
        }

        $reason = $options['reason'] ?? null;

        if (in_array($method, self::REASON_REQUIRED, true) && blank($reason)) {
            // Playbook §5.2 rule 4 — discretionary actions carry a reason. Enforced
            // at the service so no future door can forget it.
            throw new InvalidArgumentException("A reason is required for a [{$method}] entry.");
        }

        if ($amount < 0) {
            throw new InvalidArgumentException('Amounts are never negative; reverse a row instead.');
        }

        return DB::transaction(function () use ($subscription, $amount, $method, $options, $reason) {
            return SubscriptionInvoice::withoutGlobalScopes()->create([
                'tenant_id' => $subscription->tenant_id,
                'account_id' => $subscription->account_id,
                'amount' => round($amount, 2), // decimal, rounded once at the boundary
                'currency' => 'INR',
                'status' => 'paid',
                'method' => $method,
                'source' => $options['source'] ?? 'operator',
                'reference' => $options['reference'] ?? null,
                'recorded_by' => $options['recorded_by'] ?? auth()->id(),
                'reason' => $reason,
                'period_start' => ($options['period_start'] ?? null)?->toDateString(),
                'period_end' => ($options['period_end'] ?? null)?->toDateString(),
                'paid_at' => $options['paid_at'] ?? now(),
                'receipt_no' => $this->nextReceiptNo(),
            ]);
        });
    }

    /** A complimentary grant: a ₹0 row, never an absent one (BUG-P04). */
    public function recordComp(Subscription $subscription, string $reason, ?Carbon $periodStart = null, ?Carbon $periodEnd = null): SubscriptionInvoice
    {
        return $this->record($subscription, 0.0, 'comp', [
            'reason' => $reason,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ]);
    }

    /**
     * Platform-wide sequential receipt number: OSMS-2026-0001 (owner decision B1).
     *
     * Computed inside the caller's transaction with a lock on the latest row so
     * two concurrent entries can't mint the same number.
     */
    private function nextReceiptNo(): string
    {
        $year = now(config('billing.timezone', 'Asia/Kolkata'))->year;
        $prefix = "OSMS-{$year}-";

        $latest = SubscriptionInvoice::withoutGlobalScopes()
            ->where('receipt_no', 'like', $prefix . '%')
            ->orderByDesc('receipt_no')
            ->lockForUpdate()
            ->value('receipt_no');

        $next = $latest ? ((int) substr($latest, strlen($prefix))) + 1 : 1;

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
