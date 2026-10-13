<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\GuardsMoneyWrites;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * ST-Billing (S4) — a subscription payment receipt, created from
 * `subscription.charged` webhooks. Tenant-owned (BelongsToTenant) per the
 * multi-tenancy rule.
 */
class SubscriptionInvoice extends Model
{
    // E2.1 — written only through PaymentRecorder; never deleted (rule 6).
    use HasUuid, BelongsToTenant, GuardsMoneyWrites;

    protected $fillable = [
        'tenant_id', 'account_id', 'razorpay_payment_id', 'razorpay_invoice_id',
        'razorpay_subscription_id', 'amount', 'currency', 'status', 'paid_at',
        // P1 / REQ-5 — one channel-agnostic ledger. Every money path writes these.
        'method', 'source', 'reference', 'recorded_by', 'reason',
        'period_start', 'period_end', 'receipt_no',
        'reversed_at', 'reversed_by', 'reversal_reason',
        // REQ-15 — what it would have cost, what was knocked off, and (for a
        // part-period charge) the arithmetic that produced it.
        'list_amount', 'discount_amount', 'discount_reason', 'calculation',
        // E2 — what it paid for, and the coverage it actually granted (D-7).
        'charge_id', 'granted_start', 'granted_end',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'period_start' => 'date',
        'period_end' => 'date',
        'reversed_at' => 'datetime',
        'list_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'calculation' => 'array',
        'granted_start' => 'date',
        'granted_end' => 'date',
    ];

    /** REQ-15 — was something bargained off this particular charge? */
    public function hasDiscount(): bool
    {
        return $this->discount_amount !== null && (float) $this->discount_amount > 0;
    }

    /**
     * REQ-15 — the stored working for a part-period charge.
     *
     * Read back rather than recomputed, deliberately: by the time anyone opens
     * this receipt the branch count and the tier may both have moved, and a
     * recomputation would answer with today's numbers rather than the ones the
     * customer was actually charged on.
     */
    public function hasCalculation(): bool
    {
        return is_array($this->calculation) && ! empty($this->calculation['lines']);
    }

    /** E2 — the charge this money settled (null for ₹0 grants and pre-engine rows). */
    public function charge(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(BillingCharge::class, 'charge_id');
    }

    /** P1 / REQ-12 — the payer this ledger row belongs to. */
    public function account(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** A reversal is a state, never a delete — both rows stay visible. */
    /**
     * UI-05 — the ledger's reading order: by DAY, then by when it was recorded.
     *
     * A payment entered with only a "received on" date is stamped at midnight,
     * so ordering by the full timestamp put the newest entry of the day BELOW
     * everything recorded earlier that same day.
     */
    public function scopeNewestFirst(Builder $query): Builder
    {
        return $query->orderByRaw('DATE(paid_at) DESC')->orderByDesc('created_at');
    }

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
