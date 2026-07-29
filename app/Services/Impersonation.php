<?php

namespace App\Services;

use App\Models\AdminAuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/**
 * P5 / REQ-7 — "view as store", read-only.
 *
 * The support problem it solves: answering "my order list is empty" today means
 * asking the shopkeeper to read numbers down the phone. Seeing their screen is
 * the difference between a five-minute call and a twenty-minute one.
 *
 * It is also the single most dangerous feature in the panel, so every property
 * below is deliberate and none is optional:
 *
 *   READ-ONLY   enforced in middleware on the HTTP VERB, not by hiding buttons.
 *               A hidden button is a suggestion; a rejected POST is a rule.
 *   TIME-BOXED  the session expires on its own. An operator who walks away
 *               having forgotten does not leave a live door open.
 *   BANDED      the UI is unmistakably marked for the whole session, so nobody
 *               ever believes they are looking at their own screen.
 *   AUDITED     on entry AND exit, with the duration. An entry-only log tells
 *               you somebody went in but never that they left, which is exactly
 *               the question asked after an incident.
 *   NEVER PEER  a superadmin can never be impersonated. Otherwise the panel
 *               contains its own privilege-escalation path.
 *
 * Why swap the login rather than "borrow" a tenant_id: TenantScope reads
 * `auth()->user()->tenant_id` and superadmins BYPASS it entirely, so an operator
 * carrying a tenant hint would still be served every store's rows. Becoming the
 * user is the only version where the isolation the customer relies on is the
 * same isolation the operator is testing.
 */
class Impersonation
{
    private const SESSION_KEY = 'impersonation';

    /** Begin a session as $target. Returns the tenant now being viewed. */
    public function start(Request $request, User $target, string $reason): Tenant
    {
        $admin = $request->user();

        if (! $admin || ! $admin->isSuperadmin()) {
            throw new InvalidArgumentException('Only an operator can view as a store.');
        }

        if ($target->isSuperadmin()) {
            throw new InvalidArgumentException('Operators cannot be impersonated.');
        }

        if (! $target->tenant_id) {
            throw new InvalidArgumentException('That user does not belong to a store.');
        }

        $tenant = Tenant::findOrFail($target->tenant_id);

        // Nesting would make "who is really acting?" unanswerable from the logs.
        if ($this->active($request)) {
            throw new InvalidArgumentException('Already viewing as a store — leave that session first.');
        }

        $minutes = max(1, (int) config('saas.impersonation_minutes', 30));
        $expiresAt = now()->addMinutes($minutes);

        $log = AdminAuditLog::record(
            'impersonation.started',
            "{$admin->email} started viewing {$tenant->store_name} as {$target->email} (read-only)",
            $tenant->id,
            [
                'target_user_id' => $target->id,
                'target_email' => $target->email,
                'reason' => $reason,
                'read_only' => true,
                'expires_at' => $expiresAt->toIso8601String(),
            ],
        );

        Auth::login($target);

        // Rotate the session id — session data survives, the identifier does not.
        $request->session()->regenerate();

        $request->session()->put(self::SESSION_KEY, [
            'admin_id' => $admin->id,
            'admin_email' => $admin->email,
            'tenant_id' => $tenant->id,
            'store_name' => $tenant->store_name,
            'target_email' => $target->email,
            'started_at' => now()->toIso8601String(),
            'expires_at' => $expiresAt->toIso8601String(),
            'log_id' => $log->id,
        ]);

        return $tenant;
    }

    /**
     * End the session and put the operator back in their own seat.
     *
     * The operator is restored BEFORE the audit row is written, so the exit
     * entry is attributed to them and not to the customer they were viewing —
     * `AdminAuditLog::record()` reads `auth()->user()`.
     *
     * @param string $how 'operator' when they clicked out, 'expired' when the clock ran out
     */
    public function stop(Request $request, string $how = 'operator'): ?User
    {
        $state = $request->session()->get(self::SESSION_KEY);

        if (! is_array($state)) {
            return null;
        }

        $request->session()->forget(self::SESSION_KEY);

        $admin = User::find($state['admin_id'] ?? null);

        if ($admin) {
            Auth::login($admin);
            $request->session()->regenerate();
        } else {
            // The operator's own account vanished mid-session. Nobody continues
            // as the customer — log them out entirely.
            Auth::logout();
        }

        $started = isset($state['started_at']) ? Carbon::parse($state['started_at']) : null;

        AdminAuditLog::record(
            'impersonation.ended',
            ($state['admin_email'] ?? 'operator') . " stopped viewing {$state['store_name']}"
                . ($how === 'expired' ? ' (session expired)' : ''),
            $state['tenant_id'] ?? null,
            [
                'target_email' => $state['target_email'] ?? null,
                'ended_by' => $how,
                'started_at' => $state['started_at'] ?? null,
                'duration_seconds' => $started ? (int) $started->diffInSeconds(now()) : null,
                'entry_log_id' => $state['log_id'] ?? null,
            ],
        );

        return $admin;
    }

    /** The live impersonation state, or null. Expiry counts as not-active. */
    public function active(Request $request): ?array
    {
        $state = $request->session()->get(self::SESSION_KEY);

        if (! is_array($state) || ! isset($state['expires_at'])) {
            return null;
        }

        return Carbon::parse($state['expires_at'])->isFuture() ? $state : null;
    }

    /** True when a session exists but its clock has run out. */
    public function expired(Request $request): bool
    {
        $state = $request->session()->get(self::SESSION_KEY);

        return is_array($state)
            && isset($state['expires_at'])
            && Carbon::parse($state['expires_at'])->isPast();
    }
}
