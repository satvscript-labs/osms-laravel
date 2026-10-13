<?php

namespace App\Models;

use App\Models\Concerns\GuardsMoneyWrites;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * E3 — money that arrived online and matched no open bill (FB-04, rule 4).
 *
 * Never dropped, never silently applied: it waits here, on the operator's Today
 * page, until a person applies it to a bill or records that it was refunded.
 * Written only by Settlement (money guard) and never deleted. Not collected
 * revenue until applied (06 §7).
 */
class UnappliedPayment extends Model
{
    use GuardsMoneyWrites, HasUuid;

    protected $fillable = [
        'account_id', 'charge_id', 'gateway', 'gateway_payment_id', 'gateway_order_id', 'gateway_event',
        'amount', 'currency', 'reason', 'status', 'resolved_by', 'resolved_at', 'resolution_note',
        'ledger_row_id', 'paid_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'resolved_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function charge(): BelongsTo
    {
        return $this->belongsTo(BillingCharge::class, 'charge_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
