<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * E4 — a renewal reminder that was sent: one row per account, cycle and stage
 * (E4-C). Written by the daily run only after the email is queued.
 */
class BillingReminder extends Model
{
    use HasUuid;

    /** The ladder, in order (E4-C). */
    public const STAGES = ['due_7', 'due_1', 'due_0', 'grace', 'locked'];

    protected $fillable = [
        'account_id', 'subscription_id', 'charge_id', 'cycle_end', 'stage', 'channel', 'recipients', 'amount', 'sent_at',
    ];

    protected $casts = [
        'cycle_end' => 'date',
        'amount' => 'decimal:2',
        'sent_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** How the operator reads it: "Due in 7 days · emailed 2 days ago". */
    public function label(): string
    {
        return match ($this->stage) {
            'due_7' => 'Due-soon reminder',
            'due_1' => 'Due-tomorrow reminder',
            'due_0' => 'Due-today reminder',
            'grace' => 'Overdue reminder',
            'locked' => 'Access-paused notice',
            default => ucfirst(str_replace('_', ' ', $this->stage)),
        };
    }
}
