<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Tenant extends Model
{
    use HasUuid;

    protected $fillable = [
        'account_id',
        'store_name',
        'tax_id',
        'logo_url',
        'address',
        'internal_notes',
        'gst_enabled',
        'gst_rate',
        'is_billable',
        'store_status',
        'closed_at',
        'closure_reason',
        'purge_after',
        'closed_by',
    ];

    protected $casts = [
        'gst_enabled' => 'boolean',
        'gst_rate' => 'decimal:2',
        'is_billable' => 'boolean',
        'closed_at' => 'datetime',
        'purge_after' => 'datetime',
    ];

    /** P1 / REQ-12 — the paying identity this store belongs to ("Customer" in the panel). */
    public function account(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** Default GST rate assumed when a registered store hasn't set one (%). */
    public const DEFAULT_GST_RATE = 12.0;

    /** Whether this store's formal tax invoices should show a CGST/SGST breakup. */
    public function hasGst(): bool
    {
        return $this->gst_enabled && filled($this->tax_id);
    }

    /** The GST rate to apply on the formal tax invoice (%). */
    public function effectiveGstRate(): float
    {
        return $this->gst_rate !== null ? (float) $this->gst_rate : self::DEFAULT_GST_RATE;
    }

    /**
     * ST-Enforce (S1): creating a store starts its free trial. Keeping this a
     * model invariant guarantees every tenant — from onboarding, seeders, or
     * tests — always has a subscription, so access enforcement is never bypassed
     * by a missing row.
     */
    protected static function booted(): void
    {
        static::created(function (Tenant $tenant) {
            // P1 / REQ-12 — ONE subscription per ACCOUNT, not per store.
            //
            // A second branch joins the payer's EXISTING clock; it does not start
            // its own. Minting one per store would give a single customer several
            // drifting renewal dates — exactly what the account layer exists to
            // prevent, and what PR-13 (co-termination) would then have to undo.
            // Adding a branch mid-cycle bills from the next renewal (decision A3).
            if ($tenant->account_id
                && Subscription::withoutGlobalScopes()->where('account_id', $tenant->account_id)->exists()) {
                return;
            }

            // Trial end is a calendar date measured in the billing timezone, so
            // create it there too (avoids a UTC/IST off-by-one at day boundaries).
            $tz = config('billing.timezone', 'Asia/Kolkata');

            $tenant->subscription()->create([
                'status' => 'trialing',
                'tier' => 'basic',
                // P1 / REQ-12 — dual-write: the subscription is account-scoped from
                // birth when the tenant already knows its account (StoreProvisioner
                // sets it before create). Pre-backfill tenants pass null; the
                // osms:backfill-accounts command fills those in.
                'account_id' => $tenant->account_id,
                // REQ-4 — bind to the plan row matching the tier, if seeded. The
                // PriceResolver falls back to config when plans aren't seeded (tests).
                'plan_id' => Plan::query()->where('code', 'basic')->value('id'),
                'current_period_end' => now($tz)->addDays((int) config('billing.trial_days', 14)),
            ]);
        });
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    /** FT-WhatsApp — this store's WhatsApp messaging configuration (may be absent). */
    public function whatsappConfig(): HasOne
    {
        return $this->hasOne(WhatsAppConfig::class);
    }

    /**
     * The subscription that governs this store's access.
     *
     * P1 / REQ-12 — money lives on the ACCOUNT, so a store is covered by its
     * payer's subscription. Falls back to its own row for stores not yet
     * backfilled (transition period, decision E6) — so behaviour is identical
     * before and after `osms:backfill-accounts` runs.
     */
    public function governingSubscription(): ?Subscription
    {
        return $this->account?->subscription ?? $this->subscription;
    }

    /**
     * True when this store may be used: the money is live AND the store itself
     * has not been individually suspended (06 §5 — two independent levers).
     */
    public function hasActiveAccess(): bool
    {
        if ($this->isBlocked()) {
            return false;
        }

        return (bool) $this->governingSubscription()?->hasAccess();
    }

    /**
     * P5 — the store itself is shut, whatever the money says.
     *
     * Two different intents, one answer for the enforcement layer: `suspended`
     * is a lever you expect to release, `closed` is the end of the relationship.
     * Every caller that asks "may this store be used?" must go through here, so
     * a third state can never be added and silently forgotten in one branch.
     */
    public function isBlocked(): bool
    {
        return in_array($this->store_status, ['suspended', 'closed'], true);
    }

    public function isClosed(): bool
    {
        return $this->store_status === 'closed';
    }

    /**
     * Whether the retention window has run out and the data may now be destroyed.
     * A closed store with time left on the clock is deliberately NOT purgeable —
     * the window is the whole safety mechanism.
     */
    public function isPurgeable(): bool
    {
        return $this->isClosed()
            && $this->purge_after !== null
            && $this->purge_after->isPast();
    }

    /** Whole days left before this store's data may be destroyed. */
    public function daysUntilPurge(): ?int
    {
        if (! $this->isClosed() || ! $this->purge_after) {
            return null;
        }

        return max(0, (int) ceil(now()->diffInDays($this->purge_after, false)));
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function inventory(): HasMany
    {
        return $this->hasMany(Inventory::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(StaffInvitation::class);
    }

    public function subscriptionInvoices(): HasMany
    {
        return $this->hasMany(SubscriptionInvoice::class);
    }

    /** ST-Admin — the superadmin audit trail for actions on this store. */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AdminAuditLog::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Seat management (ST-Staff / S3)
    |--------------------------------------------------------------------------
    | Single flat cap at launch. Kept behind these methods so it can later become
    | tier-based (read from $this->subscription->tier) without touching callers.
    */

    public function seatLimit(): int
    {
        return (int) config('saas.max_staff', 5);
    }

    /** Seats consumed = current members + still-pending invitations. */
    public function seatsUsed(): int
    {
        $pending = $this->invitations()
            ->whereNull('accepted_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->count();

        return $this->users()->count() + $pending;
    }

    public function canAddSeat(): bool
    {
        return $this->seatsUsed() < $this->seatLimit();
    }
}
