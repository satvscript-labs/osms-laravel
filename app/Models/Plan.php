<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * P1 / REQ-4 — a purchasable plan, as data.
 *
 * List prices live here so the operator edits them in the panel instead of
 * deploying code. `config('billing.plans')` remains only as the seed source
 * and PriceResolver's last-resort fallback.
 *
 * Platform-level (no tenant scope): plans belong to the business, not a store.
 */
class Plan extends Model
{
    use HasUuid;

    protected $fillable = [
        'code', 'name', 'monthly_price', 'yearly_price',
        'features', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'monthly_price' => 'decimal:2',
        'yearly_price' => 'decimal:2',
        'features' => 'array',
        'is_active' => 'boolean',
    ];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /** The list price for a billing interval. */
    public function priceFor(string $interval): float
    {
        return $interval === 'yearly'
            ? (float) $this->yearly_price
            : (float) $this->monthly_price;
    }
}
