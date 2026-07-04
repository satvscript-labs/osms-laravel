<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class Subscription extends Model
{
    use HasUuid, BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'razorpay_subscription_id', 'razorpay_customer_id',
        'status', 'tier', 'interval', 'pending_interval',
        'current_period_end', 'cancel_at_period_end', 'manual',
    ];

    protected $casts = [
        'current_period_end' => 'date',
        'cancel_at_period_end' => 'boolean',
        'manual' => 'boolean',
    ];

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
