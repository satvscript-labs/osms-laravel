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
        'tenant_id', 'razorpay_payment_id', 'razorpay_invoice_id',
        'razorpay_subscription_id', 'amount', 'currency', 'status', 'paid_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

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
