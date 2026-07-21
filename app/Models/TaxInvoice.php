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

    protected $fillable = ['tenant_id', 'order_id', 'financial_year', 'sequence', 'snapshot'];

    protected $casts = [
        'sequence' => 'integer',
        'snapshot' => 'array',
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
                // BIZ-03 — freeze the invoice's contents at issue time.
                'snapshot' => self::buildSnapshot($order),
            ]);
        });
    }

    /**
     * BIZ-03 — build the immutable content snapshot for an order's invoice: the
     * store + customer details, each invoiced line (name/qty/price + GST split),
     * and the totals, all as they are RIGHT NOW. Stored at issue time so the
     * numbered document never changes when the order or the GST rate later does.
     * Also used as the live fallback for legacy invoices that predate the column.
     */
    public static function buildSnapshot(Order $order): array
    {
        $tenant = $order->tenant;
        $hasGst = $tenant?->hasGst() ?? false;
        $rate = $tenant?->effectiveGstRate() ?? 0.0;

        // Fresh query so a just-created order (store()) reflects its new items.
        $items = $order->items()->where('on_tax_invoice', true)->with('inventory')->get();

        $lines = [];
        $totals = ['taxable' => 0.0, 'cgst' => 0.0, 'sgst' => 0.0, 'grand' => 0.0];

        foreach ($items as $it) {
            $amount = round((float) $it->unit_price * $it->quantity, 2);
            $split = $hasGst ? \App\Support\Gst::splitInclusive($amount, $rate) : null;

            $lines[] = [
                'name' => $it->is_custom
                    ? $it->display_name
                    : trim(($it->inventory?->brand ?? '—') . ' ' . ($it->inventory?->model_name ?? '')),
                'qty' => (int) $it->quantity,
                'unit_price' => (float) $it->unit_price,
                'amount' => $amount,
                'taxable' => $split['taxable'] ?? null,
                'cgst' => $split['cgst'] ?? null,
                'sgst' => $split['sgst'] ?? null,
            ];

            $totals['grand'] += $amount;
            if ($split) {
                $totals['taxable'] += $split['taxable'];
                $totals['cgst'] += $split['cgst'];
                $totals['sgst'] += $split['sgst'];
            }
        }

        return [
            'has_gst' => $hasGst,
            'gst_rate' => $rate,
            'store' => [
                'name' => $tenant?->store_name,
                'address' => $tenant?->address,
                'gstin' => $tenant?->tax_id,
            ],
            'customer' => [
                'name' => $order->customer?->name,
                'phone' => $order->customer?->phone,
            ],
            'order_ref' => strtoupper(substr((string) $order->id, 0, 8)),
            'lines' => $lines,
            'totals' => [
                'taxable' => round($totals['taxable'], 2),
                'cgst' => round($totals['cgst'], 2),
                'sgst' => round($totals['sgst'], 2),
                'grand' => round($totals['grand'], 2),
            ],
        ];
    }
}
