<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * P1 / REQ-12 — the paying identity. "Customer" in the operator UI.
 *
 * Named `Account` in code because `Customer` is already the optical shop's
 * PATIENT model (3,036 rows for Sahaj alone) — a second meaning would collide
 * in every query and conversation for the life of the product. The Super Admin
 * panel labels this "Customer" throughout; the codebase says Account.
 *
 * Deliberately NOT tenant-scoped: accounts sit ABOVE stores. Only superadmin
 * surfaces touch this model; tenant-side code never does.
 *
 * The rule in one line: money and identity live here; data and isolation live
 * on the store (`tenant_id`), unchanged.
 */
class Account extends Model
{
    use HasUuid;

    protected $fillable = [
        'name', 'display_name', 'billing_email', 'billing_phone', 'billing_address',
        'tax_id', 'status', 'internal_notes', 'owner_user_id',
    ];

    /** The stores (tenants) under this account. Fully isolated from each other (Q-B). */
    public function stores(): HasMany
    {
        return $this->hasMany(Tenant::class);
    }

    /**
     * One commercial relationship, one clock — however many stores.
     *
     * ⚠ AUD-02 — `withoutGlobalScopes()` is REQUIRED, not an optimisation.
     *
     * `Subscription` uses `BelongsToTenant`, so the tenant scope constrains it
     * to the signed-in user's `tenant_id`. An account's subscription carries
     * the tenant_id of the account's FIRST store, so a user signed in at a
     * SECOND branch resolved their own payer's subscription to `null` — no
     * billing page, no trial banner, and `EnsureSubscriptionActive` locked them
     * out of a fully paid-up account.
     *
     * Reaching it here is correct and not a leak: you are reading the
     * subscription of the account you already hold, which is by definition the
     * one that governs you.
     */
    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->withoutGlobalScopes();
    }

    /**
     * The one ledger: every payment, comp and reversal for this payer.
     *
     * Same reasoning as `subscription()` — ledger rows carry the tenant_id of
     * whichever store the payment related to, which may not be the store the
     * reader is signed in at.
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(SubscriptionInvoice::class)->withoutGlobalScopes();
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /** What the UI shows on paperwork — falls back to the payer's own name. */
    public function displayName(): string
    {
        return $this->display_name ?: $this->name;
    }

    /** Stores that count toward the bill (quantity = this count, from P3). */
    public function billableStores(): HasMany
    {
        return $this->stores()->where('is_billable', true);
    }
}
