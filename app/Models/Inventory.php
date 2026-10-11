<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Inventory extends Model
{
    use HasUuid, BelongsToTenant, SoftDeletes;

    // Singular table name (matches the original schema)
    protected $table = 'inventory';

    protected $fillable = [
        'tenant_id', 'sku', 'barcode', 'item_type', 'brand', 'model_name',
        'cost_price', 'selling_price', 'stock_qty', 'min_alert_qty', 'is_tracked',
    ];

    protected $casts = [
        'cost_price' => 'decimal:2',
        'selling_price' => 'decimal:2',
        'stock_qty' => 'integer',
        'min_alert_qty' => 'integer',
        'is_tracked' => 'boolean',
    ];

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function isLowStock(): bool
    {
        if (! $this->is_tracked) {
            return false;
        }
        return $this->stock_qty <= $this->min_alert_qty;
    }

    /** Human label for the item type enum. */
    public function getTypeLabelAttribute(): string
    {
        return match ($this->item_type) {
            'frame' => 'Frame',
            'lens' => 'Lens',
            'contact_lens' => 'Contact Lens',
            'accessory' => 'Accessory',
            default => ucfirst((string) $this->item_type),
        };
    }
}
