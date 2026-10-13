<?php

namespace App\Models;

use App\Models\Concerns\GuardsMoneyWrites;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * E2 — a charge: money ASKED FOR that has not necessarily arrived (06 §3.1).
 *
 * Raised by `BillingEngine` with its amount locked and its working stored; settled
 * by `Settlement`, which writes the ledger row and grants coverage. Never edited
 * into a different price (a new price is a new charge, the old one `superseded`)
 * and never deleted (a mistake is `void`, with a reason).
 *
 * ⚠ ACCOUNT-owned, deliberately NOT `BelongsToTenant` (E2-H): an account-level
 * renewal has no store, and a branch's charge belongs to the payer, not to that
 * branch's staff. Read it through `Account::charges()`; every door checks the
 * account (QA-08 is the same rule for the ledger).
 */
class BillingCharge extends Model
{
    use GuardsMoneyWrites, HasUuid;

    public const KINDS = ['first_term', 'renewal', 'branch_join'];

    protected $fillable = [
        'account_id', 'subscription_id', 'tenant_id', 'number', 'kind', 'status',
        'amount', 'cycles', 'interval', 'quantity', 'unit_price', 'tier_label', 'calculation',
        'period_start', 'period_end', 'due_on', 'open_key', 'raised_by', 'raised_via',
        'paid_at', 'granted_start', 'granted_end',
        'voided_at', 'voided_by', 'void_reason', 'superseded_by', 'reopened_at',
        'gateway_order_id', 'gateway_link_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'cycles' => 'integer',
        'quantity' => 'integer',
        'calculation' => 'array',
        'period_start' => 'date',
        'period_end' => 'date',
        'due_on' => 'date',
        'paid_at' => 'datetime',
        'granted_start' => 'date',
        'granted_end' => 'date',
        'voided_at' => 'datetime',
        'reopened_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class)->withoutGlobalScopes();
    }

    /** The branch that caused it (branch join), or null for an account-level charge. */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** Every ledger row that settled it — more than one only after a reversal. */
    public function payments(): HasMany
    {
        return $this->hasMany(SubscriptionInvoice::class, 'charge_id')->withoutGlobalScopes();
    }

    public function raisedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /** The stored working (partials.charge-working reads it for charges and payments alike). */
    public function hasCalculation(): bool
    {
        return is_array($this->calculation) && ! empty($this->calculation['lines']);
    }

    /** A bargain is recorded on the PAYMENT against this bill, never on the bill itself. */
    public function hasDiscount(): bool
    {
        return false;
    }

    /** E2-G — has this charge already granted coverage (possibly before a reversal)? */
    public function hasGranted(): bool
    {
        return $this->granted_end !== null;
    }

    /** What the operator reads in a list: "Renewal · 1 year", "Part period · Branch 2". */
    public function label(): string
    {
        return match ($this->kind) {
            'branch_join' => 'Part period' . ($this->tenant?->store_name ? ' · ' . $this->tenant->store_name : ''),
            'first_term' => 'First ' . $this->periodWords(),
            'renewal' => 'Renewal · ' . $this->periodWords(),
            default => ucfirst((string) $this->kind),
        };
    }

    private function periodWords(): string
    {
        $n = max(1, (int) $this->cycles);
        $unit = $this->interval === 'yearly' ? 'year' : 'month';

        return $n . ' ' . $unit . ($n > 1 ? 's' : '');
    }

    /** Never show a status raw (the same rule as subscriptions). */
    public function statusLabel(): string
    {
        return match ($this->status) {
            'pending' => $this->reopened_at ? 'Owed (reopened)' : 'Owed',
            'paid' => 'Paid',
            'void' => 'Void',
            'superseded' => 'Replaced',
            default => ucfirst((string) $this->status),
        };
    }
}
