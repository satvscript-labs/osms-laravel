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
        'supervised', 'supervised_reason', 'trial_used_at',
    ];

    protected $casts = [
        'supervised' => 'boolean',
        // P.2 - when this account's one free trial began. Set once, never cleared.
        'trial_used_at' => 'datetime',
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

    /**
     * P4 — may this customer pay for themselves, or must it go through you?
     *
     * Either switch turns supervision ON: the platform-wide knob, or this
     * account's own flag. Neither can be inverted by the other, because
     * "supervise everyone" and "supervise this one" are different intents and
     * an operator should never have to reason about which wins.
     */
    public function isSupervised(): bool
    {
        return $this->supervised
            || PlatformSetting::supervisedGlobally()
            || $this->needsSupervisionForBranches();
    }

    /**
     * REQ-15 / PR-14 — the multi-branch guard, and why it exists.
     *
     * `BillingService` sends Razorpay a hardcoded `quantity => 1`. That was
     * consistent while price ignored branch count; it is not now. A customer
     * with three branches who checked out through self-serve would pay for
     * ONE — the panel would show them as paid up, and nothing anywhere would
     * flag the shortfall.
     *
     * So multi-branch customers are billed by hand until the gateway lane
     * understands quantity (PR-14). Supervised mode was built for exactly this
     * and the switch already exists; using it costs nothing and closes the hole
     * completely.
     *
     * ⚠ This is a GUARD, not a fix. It means multi-branch customers cannot pay
     * online at all — acceptable at one customer with one branch, and a silent
     * growth ceiling if it is still here in a year. PR-14 removes it.
     */
    public function needsSupervisionForBranches(): bool
    {
        return $this->billableStoreCount() > 1;
    }

    /** Why self-serve is off for them — shown on their billing page. */
    public function supervisionReason(): ?string
    {
        if ($this->supervised && filled($this->supervised_reason)) {
            return $this->supervised_reason;
        }

        if (! $this->supervised
            && ! PlatformSetting::supervisedGlobally()
            && $this->needsSupervisionForBranches()) {
            // Say something true about THEM rather than the generic line —
            // "managed by our team" reads as an error to somebody who simply
            // opened a second shop.
            return 'You have more than one store, so we handle your billing directly. Contact us to pay or change your plan.';
        }

        return $this->isSupervised() ? 'Your account is managed directly by our team.' : null;
    }

    /**
     * Stores that count toward the bill (quantity = this count, from P3).
     *
     * AUD-A11 — a CLOSED store is excluded here rather than by flipping its
     * `is_billable` flag at closure. Flipping the flag would destroy the
     * operator's own earlier "exclude this one from billing" decision, and
     * reopening could not tell the two apart. Closure is a separate axis, so
     * it is filtered on a separate axis.
     */
    public function billableStores(): HasMany
    {
        return $this->stores()->where('is_billable', true)->where('store_status', '!=', 'closed');
    }

    /**
     * How many branches are being paid for — from the loaded relation when the
     * caller eager-loaded it.
     *
     * `billableStores()->count()` is one query, which is fine on a customer's
     * own page and ruinous in a loop: MRR is summed over every active
     * subscription on the dashboard, so a bare count there is one query per
     * customer. Caught by the query-count guards rather than by inspection.
     */
    public function billableStoreCount(): int
    {
        if ($this->relationLoaded('stores')) {
            return $this->stores
                ->filter(fn (Tenant $s) => $s->is_billable && ! $s->isClosed())
                ->count();
        }

        return $this->billableStores()->count();
    }

    /** Stores that are still in use — neither closed nor suspended. */
    public function openStores(): HasMany
    {
        return $this->stores()->whereNotIn('store_status', ['closed']);
    }

    /**
     * AUD-A05 — is there anything left to pay for?
     *
     * A customer whose every store is closed kept contributing to MRR forever,
     * because MRR reads the subscription and closure lives on the store. The
     * clock is deliberately left running when a branch closes (a three-branch
     * customer must not stop paying because one shut), but when the LAST one
     * closes there is nothing being sold and counting it is fiction.
     *
     * Uses the loaded relation when the caller eager-loaded it, so the
     * dashboard's MRR sum stays one query rather than one per customer.
     */
    public function hasOpenStore(): bool
    {
        if ($this->relationLoaded('stores')) {
            return $this->stores->contains(fn (Tenant $s) => ! $s->isClosed());
        }

        return $this->openStores()->exists();
    }
}
