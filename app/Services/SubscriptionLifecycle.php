<?php

namespace App\Services;

use App\Models\AdminAuditLog;
use App\Models\Subscription;
use Illuminate\Support\Carbon;

/**
 * The single place entitlement state is mutated (P0 / BUG-P01).
 *
 * Both lanes — the automated Razorpay webhook and the operator's manual actions —
 * route through here, so the "a human decision outlives the robot" rule holds
 * uniformly instead of being re-implemented (and forgotten) per caller.
 *
 * P0 ships only the webhook gate, which is the bleeding. P1 grows this into the
 * full lifecycle service described in _artifacts/platform/03_SUPERADMIN_DESIGN.md §2.4.
 */
class SubscriptionLifecycle
{
    /**
     * Apply a gateway event's entitlement changes — unless an operator override
     * is in force, in which case the subscription is left exactly as the human
     * left it.
     *
     * ⚠ This deliberately governs ENTITLEMENT ONLY. The caller records the
     * payment in the ledger BEFORE calling this, and must keep doing so: money
     * that actually changed hands is always recorded, whatever the operator has
     * decided about access. Dropping the payment record would trade one bug for
     * a worse one.
     *
     * @return bool true if the gateway's values were applied, false if suppressed.
     */
    public function applyGatewayEntitlement(
        Subscription $subscription,
        ?string $status,
        ?Carbon $periodEnd,
        ?string $interval,
    ): bool {
        if ($subscription->hasActiveOverride()) {
            $this->auditSuppression($subscription, $status, $periodEnd);

            return false;
        }

        if ($status) {
            $subscription->status = $status;

            if ($status === 'canceled') {
                $subscription->cancel_at_period_end = false; // the cancel has taken effect
            }
        }

        if ($periodEnd) {
            $subscription->current_period_end = $periodEnd;
        }

        if ($interval) {
            $subscription->interval = $interval;

            if ($subscription->pending_interval === $interval) {
                $subscription->pending_interval = null;
            }
        }

        $subscription->save();

        return true;
    }

    /**
     * Leave a trail when automation is overruled.
     *
     * Without this the suppression is invisible: the operator sees their comp
     * survive but has no way to know the gateway tried to change it, which is
     * exactly the sort of silent divergence that erodes trust in the panel.
     *
     * Runs unauthenticated (webhook context), so AdminAuditLog records a null
     * actor — correct, because this was a system decision, not a person's.
     */
    private function auditSuppression(
        Subscription $subscription,
        ?string $status,
        ?Carbon $periodEnd,
    ): void {
        AdminAuditLog::record(
            'subscription.webhook_suppressed',
            "Razorpay update ignored — {$subscription->override_kind} override is in force",
            $subscription->tenant_id,
            [
                'override' => [
                    'kind' => $subscription->override_kind,
                    'until' => $subscription->override_until?->toDateString(),
                    'reason' => $subscription->override_reason,
                ],
                'kept' => [
                    'status' => $subscription->status,
                    'current_period_end' => $subscription->current_period_end?->toDateString(),
                ],
                'ignored' => [
                    'status' => $status,
                    'current_period_end' => $periodEnd?->toDateString(),
                ],
            ],
        );
    }
}
