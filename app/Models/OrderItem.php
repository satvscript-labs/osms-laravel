<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    use HasUuid;

    protected $fillable = [
        'order_id', 'inventory_id', 'description', 'quantity', 'unit_price', 'list_price',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'list_price' => 'decimal:2',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class);
    }

    public function getLineTotalAttribute(): float
    {
        return (float) $this->unit_price * (int) $this->quantity;
    }

    /** True for an off-inventory/local line (no catalog item behind it). */
    public function getIsCustomAttribute(): bool
    {
        return $this->inventory_id === null;
    }

    /**
     * Human label for the line: the catalog item's brand + model, or the
     * free-text description for a local/custom line (6.4).
     */
    public function getDisplayNameAttribute(): string
    {
        if ($this->is_custom) {
            return $this->description ?: 'Custom item';
        }

        return trim(($this->inventory?->brand ?? '—') . ' ' . ($this->inventory?->model_name ?? ''));
    }
}
