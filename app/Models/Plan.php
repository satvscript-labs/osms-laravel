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
        'code', 'name', 'monthly_price', 'yearly_price', 'price_tiers',
        'features', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'monthly_price' => 'decimal:2',
        'yearly_price' => 'decimal:2',
        'price_tiers' => 'array',
        'features' => 'array',
        'is_active' => 'boolean',
    ];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /** The list price for a billing interval, for a SINGLE branch. */
    public function priceFor(string $interval): float
    {
        return $interval === 'yearly'
            ? (float) $this->yearly_price
            : (float) $this->monthly_price;
    }

    /**
     * REQ-15 — the per-branch rate at a given branch count (volume tiers).
     *
     * **Volume, not graduated.** Five branches are charged 5 × the 5+ rate, not
     * 2×A + 2×B + 1×C. Chosen because it is the version a shopkeeper can verify
     * in their head, and because the price breakdown states the applied rate
     * explicitly, so there is no ambiguity about which reading is in force.
     *
     * With no tiers configured — which is every plan today — this is just the
     * flat price, so nobody's bill moves until the owner sets breakpoints.
     *
     * @return array{0: float, 1: array|null} [rate per branch, the tier applied]
     */
    public function rateFor(string $interval, int $quantity = 1): array
    {
        $quantity = max(1, $quantity);
        $tiers = $this->price_tiers;

        if (! is_array($tiers) || $tiers === []) {
            return [$this->priceFor($interval), null];
        }

        foreach ($tiers as $tier) {
            $from = (int) ($tier['from'] ?? 1);
            // `to: null` is the open-ended top band.
            $to = isset($tier['to']) && $tier['to'] !== null ? (int) $tier['to'] : PHP_INT_MAX;

            if ($quantity >= $from && $quantity <= $to) {
                $rate = $interval === 'yearly'
                    ? ($tier['yearly'] ?? null)
                    : ($tier['monthly'] ?? null);

                // A tier that does not price this interval falls back rather
                // than charging ₹0 — a missing number must never read as free.
                return $rate !== null
                    ? [(float) $rate, $tier]
                    : [$this->priceFor($interval), null];
            }
        }

        // Above every configured band and no open-ended top: the flat price is
        // the honest answer, not the last tier's, which was never agreed to
        // apply here.
        return [$this->priceFor($interval), null];
    }

    /** Human label for the applied band — "3–4 branches", "5+ branches". */
    public static function tierLabel(?array $tier): ?string
    {
        if (! $tier) {
            return null;
        }

        $from = (int) ($tier['from'] ?? 1);
        $to = $tier['to'] ?? null;

        return $to === null ? "{$from}+ branches" : "{$from}–{$to} branches";
    }
}
