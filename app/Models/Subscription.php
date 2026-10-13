<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use App\Services\EntitlementWriteRefused;
use App\Services\SubscriptionLifecycle;
use App\Support\Mrr;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class Subscription extends Model
{
    use HasUuid, BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'account_id', 'razorpay_subscription_id', 'razorpay_customer_id',
        'status', 'tier', 'plan_id', 'interval', 'pending_interval', 'quantity',
        'current_period_end', 'current_period_start', 'cancel_at_period_end', 'manual',
        'negotiated_price', 'negotiated_interval', 'negotiated_reason', 'negotiated_by', 'negotiated_at',
        'override_kind', 'override_until', 'override_reason', 'override_by', 'override_at',
        'churned_at', 'churned_mrr', 'churned_from',
    ];

    protected $casts = [
        'current_period_end' => 'date',
        // E1.1 — written by the engine on every period change; NULL on rows that
        // predate it (no history is invented). See periodStart().
        'current_period_start' => 'date',
        'cancel_at_period_end' => 'boolean',
        'manual' => 'boolean',
        'quantity' => 'integer',
        'negotiated_price' => 'decimal:2',
        'negotiated_at' => 'datetime',
        'override_until' => 'date',
        'override_at' => 'datetime',
        'churned_at' => 'datetime',
        'churned_mrr' => 'decimal:2',
    ];

    /**
     * P6 / 03 §8 — churn is stamped by the MODEL, not by whoever changed the
     * status.
     *
     * There are four writers that can end a subscription: the operator panel,
     * the Razorpay webhook, the nightly reconcile, and a customer's own
     * self-cancel. A metric that depends on all four remembering to record the
     * same fact is a metric that will be wrong within a month. Putting it here
     * means no lane can forget, and a future fifth lane inherits it.
     */
    protected static function booted(): void
    {
        // E1.5 — the write guard (billing rule 1). Registered FIRST, so nothing
        // else runs for a write that is about to be refused. `increment()` and
        // `decrement()` fire `updating`, so they are caught too.
        //
        // What model events cannot see — query-builder mass updates,
        // DB::table('subscriptions'), saveQuietly(), withoutEvents() — is banned in
        // app/ by a static test instead (Phase84E1GuardTest, E1.6).
        static::creating(fn () => static::guardWrite('create'));
        static::updating(fn () => static::guardWrite('update'));
        static::deleting(fn () => static::guardWrite('delete'));

        static::saving(function (Subscription $subscription) {
            if (! $subscription->isDirty('status')) {
                return;
            }

            $was = $subscription->getOriginal('status');
            $now = $subscription->status;

            if ($now === 'canceled' && $was !== 'canceled') {
                /*
                 * Value the loss at the state they were in BEFORE this save —
                 * all of it, not just the status.
                 *
                 * `Mrr::monthlyValue()` returns 0 for anything that is not
                 * `active` AND 0 for anything past its period end, so asking it
                 * after the change records ₹0. Restoring only the status was
                 * not enough: AUD-A08 — `force_expire` moves the period end to
                 * yesterday in the same save, so it recorded every forced
                 * cancellation as worthless while an ordinary `cancel` of the
                 * identical customer recorded their real value. One commercial
                 * event, two different numbers, depending on which button was
                 * pressed.
                 *
                 * Both fields are put back immediately; this runs inside
                 * `saving`, so nothing is persisted in between. Deliberately
                 * reusing the ONE MRR definition rather than recomputing the
                 * price here: two definitions of revenue drift, and the one
                 * that drifts is always the one nobody is watching.
                 */
                $pendingStatus = $subscription->status;
                $pendingEnd = $subscription->current_period_end;

                $subscription->status = $was;
                $subscription->current_period_end = $subscription->getOriginal('current_period_end');
                $subscription->churned_mrr = Mrr::monthlyValue($subscription);

                $subscription->status = $pendingStatus;
                $subscription->current_period_end = $pendingEnd;

                $subscription->churned_at = now();
                $subscription->churned_from = $was;
            }

            // Came back. Someone who returned did not churn — leaving the stamp
            // would double-count them the next time they leave.
            if ($was === 'canceled' && $now !== 'canceled') {
                $subscription->churned_at = null;
                $subscription->churned_mrr = null;
                $subscription->churned_from = null;
            }
        });
    }

    /** P1 / REQ-12 — the paying identity this subscription belongs to. */
    public function account(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** P1 / REQ-4 — the plan row whose list price applies (tier's successor). */
    public function plan(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** Is this store on a bespoke, hand-agreed price? (⚑ badge in the panel.) */
    public function hasNegotiatedPrice(): bool
    {
        return $this->negotiated_price !== null;
    }

    /**
     * AUD-04 — the interval the bespoke price was agreed FOR.
     *
     * A negotiated price only means something paired with its interval:
     * ₹3,500 is a bargain per year and an overcharge per month. Falls back to
     * the subscription's own interval for rows agreed before this was stored.
     */
    public function negotiatedInterval(): ?string
    {
        return $this->hasNegotiatedPrice()
            ? ($this->negotiated_interval ?: ($this->interval ?: 'monthly'))
            : null;
    }

    /** Does the bespoke price still apply at the interval they are billed on? */
    public function negotiatedPriceApplies(?string $interval = null): bool
    {
        if (! $this->hasNegotiatedPrice()) {
            return false;
        }

        return $this->negotiatedInterval() === ($interval ?: ($this->interval ?: 'monthly'));
    }

    /** Who agreed the bespoke price — shown beside the ⚑ badge. */
    public function negotiatedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'negotiated_by');
    }

    /*
    |--------------------------------------------------------------------------
    | Operator override (P0 / BUG-P01)
    |--------------------------------------------------------------------------
    | A human decision must outlive the next automated pass. `manual` only ever
    | recorded "a human touched this"; these express "a human's decision is in
    | force, until <date>" — which is what the webhook guard actually needs.
    */

    /**
     * Is an operator's decision currently governing this subscription?
     *
     * A set `override_kind` with a NULL `override_until` is deliberately
     * INDEFINITE — a cancellation or suspension holds until an operator clears
     * it, not until a date passes.
     */
    public function hasActiveOverride(): bool
    {
        if (! $this->override_kind) {
            return false;
        }

        if ($this->override_until === null) {
            return true; // indefinite — cancellation / suspension
        }

        $tz = config('billing.timezone', 'Asia/Kolkata');

        return Carbon::today($tz)->lte(
            Carbon::parse($this->override_until->toDateString(), $tz)
        );
    }

    /** Record an operator decision as the governing state. */
    public function applyOverride(
        string $kind,
        ?Carbon $until = null,
        ?string $reason = null,
        ?int $byUserId = null,
    ): void {
        $this->forceFill([
            'override_kind' => $kind,
            'override_until' => $until?->toDateString(),
            'override_reason' => $reason,
            'override_by' => $byUserId ?? auth()->id(),
            'override_at' => now(),
        ]);
    }

    /** Hand control back to the automated lane. */
    public function clearOverride(): void
    {
        $this->forceFill([
            'override_kind' => null,
            'override_until' => null,
            'override_reason' => null,
            'override_by' => null,
            'override_at' => null,
        ]);
    }

    /**
     * QA-06 — a deliberate operator pause, not a missed payment.
     *
     * Suspension is STORED as `past_due` + a suspension override (so access
     * locks and the paid-through date is kept). Read raw, that labelled a fully
     * paid customer "Past due / Payment overdue" — an invitation to chase
     * somebody for money they have paid.
     */
    public function isSuspended(): bool
    {
        return $this->override_kind === 'suspension' && $this->hasActiveOverride();
    }

    /** The override as a person reads it — never the column value ("manual_renewal"). */
    public function overrideLabel(): string
    {
        return match ($this->override_kind) {
            // Since E3 an online payment lands here too: a paid renewal, by hand or online.
            'manual_renewal' => 'Paid renewal',
            'comp' => 'Complimentary',
            'extension' => 'Extended',
            'suspension' => 'Suspended',
            'cancellation' => 'Cancelled',
            default => ucfirst(str_replace('_', ' ', (string) $this->override_kind)),
        };
    }

    /** The state to SHOW and COUNT: `suspended` beats the `past_due` it is stored as. */
    public function displayStatus(): string
    {
        return $this->isSuspended() ? 'suspended' : ($this->status ?: 'none');
    }

    /** SQL twin of isSuspended() — same rule, for counts and filters. */
    public function scopeSuspended(Builder $query): Builder
    {
        $today = Carbon::today(config('billing.timezone', 'Asia/Kolkata'))->toDateString();

        return $query->where('override_kind', 'suspension')
            ->where(fn ($q) => $q->whereNull('override_until')->orWhereDate('override_until', '>=', $today));
    }

    /**
     * Past due in the money sense — owed, not paused by us.
     *
     * Spelled out rather than `whereNot(suspended)`: for an ordinary overdue row
     * `override_kind` is NULL, and SQL's NOT(NULL = 'suspension' …) is NULL, not
     * true — which silently dropped every genuinely overdue customer. Caught by
     * Phase83QaRunFixesTest before it shipped.
     */
    public function scopeOverdue(Builder $query): Builder
    {
        $today = Carbon::today(config('billing.timezone', 'Asia/Kolkata'))->toDateString();

        return $query->where('status', 'past_due')
            ->where(fn ($q) => $q->whereNull('override_kind')
                ->orWhere('override_kind', '!=', 'suspension')
                ->orWhere(fn ($e) => $e->whereNotNull('override_until')->whereDate('override_until', '<', $today)));
    }

    /**
     * E1.5 — refuse a subscription write unless the billing engine is making it.
     *
     * Test fixtures: in the test runner ONLY, a write whose nearest caller
     * outside the framework is a file under tests/ is the test arranging a
     * scenario, and is allowed. A write reached from app/ code is refused exactly
     * as in production — so a hidden writer still fails the suite. There is no
     * global off-switch: that would hide exactly the writers this exists to find.
     */
    private static function guardWrite(string $what): void
    {
        if (SubscriptionLifecycle::isWriting()) {
            return;
        }

        $caller = static::writerFile();

        if (app()->runningUnitTests() && $caller !== null
            && str_starts_with($caller, str_replace('\\', '/', base_path('tests')) . '/')) {
            return;
        }

        throw new EntitlementWriteRefused(sprintf(
            'A subscription may only be written through %s; a %s was attempted from %s.',
            SubscriptionLifecycle::class,
            $what,
            $caller ?? 'unknown code',
        ));
    }

    /** The nearest file in the call stack that is neither the framework nor this model. */
    private static function writerFile(): ?string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 80) as $frame) {
            $file = isset($frame['file']) ? str_replace('\\', '/', $frame['file']) : null;

            if ($file === null || str_contains($file, '/vendor/') || str_ends_with($file, '/app/Models/Subscription.php')) {
                continue;
            }

            return $file;
        }

        return null;
    }

    public function isPastDue(): bool
    {
        return $this->status === 'past_due';
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['active', 'trialing'], true);
    }

    /*
    |--------------------------------------------------------------------------
    | Access state machine (ST-Enforce / S1)
    |--------------------------------------------------------------------------
    | The single source of truth for whether a store may use the workspace.
    | Derived from `status` + `current_period_end` so it stays correct even if
    | a Razorpay webhook is missed. Consumed by EnsureSubscriptionActive and
    | the trial/grace banner.
    */

    /**
     * 'active'  — full access (in-window trial or paid).
     * 'grace'   — a lapse (past_due, or a paid renewal whose webhook is late)
     *             within the grace window; still usable, but warn the user.
     * 'locked'  — trial ended, canceled, or lapsed beyond grace → no access.
     */
    public function accessState(): string
    {
        // P3 — a deliberate operator SUSPENSION beats every grace window.
        //
        // Suspension preserves the paid-through date on purpose (so
        // reactivating loses nothing), and it is expressed as `past_due` +
        // an override. But `past_due` normally earns a grace period, which
        // would leave a suspended-yet-paid-up customer with full access —
        // i.e. the lever would silently do nothing, while the operator
        // believed they had cut access. Caught by test, fixed here.
        if ($this->override_kind === 'suspension' && $this->hasActiveOverride()) {
            return 'locked';
        }

        $boundary = $this->periodEndBoundary();
        $now = Carbon::now();
        $grace = $this->graceDays();

        return match ($this->status) {
            // A trial never gets a grace period — it was never paid for.
            'trialing' => (! $boundary || $now->lte($boundary)) ? 'active' : 'locked',
            // A paying customer past their period is likely a late renewal
            // webhook, so give them grace before locking.
            'active' => (! $boundary || $now->lte($boundary))
                ? 'active'
                : ($now->lte($boundary->copy()->addDays($grace)) ? 'grace' : 'locked'),
            'past_due' => (! $boundary || $now->lte($boundary->copy()->addDays($grace))) ? 'grace' : 'locked',
            default => 'locked', // canceled / unknown
        };
    }

    public function hasAccess(): bool
    {
        return $this->accessState() !== 'locked';
    }

    public function isInGracePeriod(): bool
    {
        return $this->accessState() === 'grace';
    }

    /** Whole calendar days left in a trial (null when not trialing). */
    public function trialDaysLeft(): ?int
    {
        if ($this->status !== 'trialing' || ! $this->current_period_end) {
            return null;
        }

        $tz = config('billing.timezone', 'Asia/Kolkata');
        $today = Carbon::today($tz);
        $end = Carbon::parse($this->current_period_end->toDateString(), $tz);

        return max(0, (int) $today->diffInDays($end, false));
    }

    private function graceDays(): int
    {
        return (int) config('billing.grace_days', 7);
    }

    /** End-of-day of the period end, in the configured billing timezone. */
    private function periodEndBoundary(): ?Carbon
    {
        if (! $this->current_period_end) {
            return null;
        }

        return Carbon::parse(
            $this->current_period_end->toDateString().' 23:59:59',
            config('billing.timezone', 'Asia/Kolkata'),
        );
    }
}
