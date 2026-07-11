<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * FT-TaxInvoice — the formal tax invoice issued for an order (only the items
 * a shop owner opted in for, not necessarily the whole order). One per order;
 * the number is allocated once via `issueFor()` and is permanent from then on.
 */
class TaxInvoice extends Model
{
    use HasUuid, BelongsToTenant;

    protected $table = 'tax_invoices';

    protected $fillable = ['tenant_id', 'order_id', 'financial_year', 'sequence'];

    protected $casts = [
        'sequence' => 'integer',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** The Indian financial year label (Apr–Mar) for a date, e.g. "2526" for FY 2025-26. */
    public static function financialYearLabel(Carbon $date): string
    {
        $startYear = $date->month >= 4 ? $date->year : $date->year - 1;

        return substr((string) $startYear, -2) . substr((string) ($startYear + 1), -2);
    }

    /** Human-facing invoice number, e.g. "INV-2526-00001". */
    public function getNumberAttribute(): string
    {
        return 'INV-' . $this->financial_year . '-' . str_pad((string) $this->sequence, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Issue (or return the already-issued) tax invoice for an order. Idempotent
     * and safe to call every time an order with flagged items is saved — the
     * number, once allocated, never changes even if items are later edited.
     */
    public static function issueFor(Order $order): self
    {
        $existing = self::where('order_id', $order->id)->first();
        if ($existing) {
            return $existing;
        }

        $fy = self::financialYearLabel($order->created_at ?? now());

        return DB::transaction(function () use ($order, $fy) {
            $next = (int) self::where('tenant_id', $order->tenant_id)
                ->where('financial_year', $fy)
                ->lockForUpdate()
                ->max('sequence') + 1;

            return self::create([
                'tenant_id' => $order->tenant_id,
                'order_id' => $order->id,
                'financial_year' => $fy,
                'sequence' => $next,
            ]);
        });
    }
}
