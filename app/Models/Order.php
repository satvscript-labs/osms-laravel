<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasUuid, BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'customer_id', 'eye_record_id', 'status',
        'fulfillment_type', 'estimated_ready_at',
        'subtotal', 'discount_type', 'discount_value', 'discount_amount',
        'total_amount', 'advance_paid', 'balance_due',
        'cancelled_at', 'cancel_reason',
    ];

    protected $casts = [
        'estimated_ready_at' => 'date',
        'subtotal' => 'decimal:2',
        'discount_value' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'advance_paid' => 'decimal:2',
        'balance_due' => 'decimal:2',
        'cancelled_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Keep balance_due in sync (replaces the Supabase computed column).
        static::saving(function (Order $order) {
            $order->balance_due = (float) $order->total_amount - (float) $order->advance_paid;
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function eyeRecord(): BelongsTo
    {
        return $this->belongsTo(EyeRecord::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'pending' => 'Pending',
            'ready_for_pickup' => 'Ready for pickup',
            'delivered' => 'Delivered',
            'cancelled' => 'Cancelled',
            default => ucfirst((string) $this->status),
        };
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    /** An instant "grab & go" counter sale (created already delivered). */
    public function isInstant(): bool
    {
        return $this->fulfillment_type === 'instant';
    }

    /** A special/prepared order that moves through the prep pipeline. */
    public function needsPrep(): bool
    {
        return $this->fulfillment_type === 'special';
    }

    public function getFulfillmentLabelAttribute(): string
    {
        return match ($this->fulfillment_type) {
            'instant' => 'Instant sale',
            'special' => 'Special order',
            default   => ucfirst((string) $this->fulfillment_type),
        };
    }

    /** Whether an order-level discount was applied. */
    public function hasDiscount(): bool
    {
        return $this->discount_type !== 'none' && (float) $this->discount_amount > 0;
    }

    /** Human label for the applied discount, e.g. "10% off" / "₹ 150.00 off". */
    public function getDiscountLabelAttribute(): ?string
    {
        return match ($this->discount_type) {
            'percent' => rtrim(rtrim(number_format((float) $this->discount_value, 2), '0'), '.') . '% off',
            'amount'  => '₹ ' . number_format((float) $this->discount_value, 2) . ' off',
            default   => null,
        };
    }
}
