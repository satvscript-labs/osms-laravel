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
        'store_name',
        'tax_id',
        'logo_url',
        'address',
        'internal_notes',
    ];

    /**
     * ST-Enforce (S1): creating a store starts its free trial. Keeping this a
     * model invariant guarantees every tenant — from onboarding, seeders, or
     * tests — always has a subscription, so access enforcement is never bypassed
     * by a missing row.
     */
    protected static function booted(): void
    {
        static::created(function (Tenant $tenant) {
            // Trial end is a calendar date measured in the billing timezone, so
            // create it there too (avoids a UTC/IST off-by-one at day boundaries).
            $tz = config('billing.timezone', 'Asia/Kolkata');

            $tenant->subscription()->create([
                'status' => 'trialing',
                'tier' => 'basic',
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

    /** True when this store has a live subscription (trial in-window, paid, or in grace). */
    public function hasActiveAccess(): bool
    {
        return (bool) $this->subscription?->hasAccess();
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
