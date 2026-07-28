<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * ST-Billing (S4) — a subscription payment receipt, created from
 * `subscription.charged` webhooks. Tenant-owned (BelongsToTenant) per the
 * multi-tenancy rule.
 */
class SubscriptionInvoice extends Model
{
    use HasUuid, BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'account_id', 'razorpay_payment_id', 'razorpay_invoice_id',
        'razorpay_subscription_id', 'amount', 'currency', 'status', 'paid_at',
        // P1 / REQ-5 — one channel-agnostic ledger. Every money path writes these.
        'method', 'source', 'reference', 'recorded_by', 'reason',
        'period_start', 'period_end', 'receipt_no',
        'reversed_at', 'reversed_by', 'reversal_reason',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'period_start' => 'date',
        'period_end' => 'date',
        'reversed_at' => 'datetime',
    ];

    /** P1 / REQ-12 — the payer this ledger row belongs to. */
    public function account(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** A reversal is a state, never a delete — both rows stay visible. */
    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }

    /** The store this line related to (null for an account-level charge). */
    public function tenant(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** The operator who entered this row by hand (null for webhook rows). */
    public function recordedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** Human label for the payment channel. */
    public function methodLabel(): string
    {
        return match ($this->method) {
            'razorpay' => 'Razorpay',
            'cash' => 'Cash',
            'upi' => 'UPI',
            'bank_transfer' => 'Bank transfer',
            'cheque' => 'Cheque',
            'comp' => 'Complimentary',
            'adjustment' => 'Adjustment',
            default => ucfirst((string) $this->method),
        };
    }

    /** GST split for the invoice PDF (India, 18% assumed inclusive). */
    public function taxBreakdown(): array
    {
        $rate = (float) config('billing.gst_rate', 18);
        $gross = (float) $this->amount;
        $base = $rate > 0 ? $gross / (1 + $rate / 100) : $gross;
        $tax = $gross - $base;

        return [
            'rate' => $rate,
            'base' => round($base, 2),
            'cgst' => round($tax / 2, 2),
            'sgst' => round($tax / 2, 2),
            'total' => round($gross, 2),
        ];
    }
}
