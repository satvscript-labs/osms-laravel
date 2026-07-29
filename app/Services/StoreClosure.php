<?php

namespace App\Services;

use App\Models\AdminAuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * P5 / REQ-7, matrix row 16 — ending a store, in two separable steps.
 *
 *   close()   instant, reversible, keeps every row. The customer loses access;
 *             you lose nothing.
 *   purge()   permanent, and refuses to run until the retention window on the
 *             closure has elapsed.
 *
 * Splitting them is the entire point. One irreversible button is how data gets
 * destroyed by a mis-click during a difficult phone call; a window means the
 * decision and its consequence are separated by weeks, and a customer who
 * changes their mind in that time gets their shop back intact.
 *
 * ⚠ The deletion logic here is LIFTED FROM `osms:remove-tenant`, not rewritten.
 * That command's central lesson came from a real production incident: every
 * tenant-owned table cascades EXCEPT `users`, which is `nullOnDelete` (correct —
 * superadmins have no tenant). So deleting a tenant row strands its staff with a
 * NULL tenant_id, and their email then blocks that person from ever signing up
 * again. Users are deleted explicitly, in the same transaction, and the result
 * is verified rather than assumed.
 */
class StoreClosure
{
    /** Every table that hangs off a tenant, for the before/after inventory. */
    public const OWNED_TABLES = [
        'customers', 'eye_records', 'patients', 'orders', 'order_items', 'payments',
        'inventory', 'stock_movements', 'tax_invoices', 'subscriptions',
        'subscription_invoices', 'staff_invitations', 'activity_logs',
        'whatsapp_configs', 'whatsapp_messages',
    ];

    /**
     * Shut a store. Access stops immediately; nothing is destroyed.
     *
     * The subscription is deliberately NOT touched. Closing one branch of a
     * three-branch customer must not cancel the clock the other two are running
     * on — that is the account layer's whole reason to exist. Ending the money
     * is a separate, explicit decision on the customer.
     */
    public function close(Tenant $tenant, string $reason): Tenant
    {
        if ($tenant->isClosed()) {
            throw new InvalidArgumentException("{$tenant->store_name} is already closed.");
        }

        $days = max(1, (int) config('saas.closure_retention_days', 30));

        $tenant->forceFill([
            'store_status' => 'closed',
            'closed_at' => now(),
            'closure_reason' => $reason,
            'purge_after' => now()->addDays($days),
            'closed_by' => auth()->id(),
        ])->save();

        AdminAuditLog::record(
            'store.closed',
            "Closed {$tenant->store_name} — data kept until " . $tenant->purge_after->format('d M Y'),
            $tenant->id,
            [
                'account_id' => $tenant->account_id,
                'reason' => $reason,
                'retention_days' => $days,
                'purge_after' => $tenant->purge_after->toIso8601String(),
                'rows_retained' => array_sum($this->inventory($tenant->id)),
            ],
        );

        return $tenant;
    }

    /** Undo a closure inside the window. Everything is exactly where it was. */
    public function reopen(Tenant $tenant, string $reason): Tenant
    {
        if (! $tenant->isClosed()) {
            throw new InvalidArgumentException("{$tenant->store_name} is not closed.");
        }

        $tenant->forceFill([
            'store_status' => 'active',
            'closed_at' => null,
            'closure_reason' => null,
            'purge_after' => null,
            'closed_by' => null,
        ])->save();

        AdminAuditLog::record(
            'store.reopened',
            "Reopened {$tenant->store_name}",
            $tenant->id,
            ['account_id' => $tenant->account_id, 'reason' => $reason],
        );

        return $tenant;
    }

    /**
     * Destroy a closed store and everything belonging to it. Irreversible.
     *
     * @param bool $force bypass the retention window (the CLI's --force, for the
     *                    "delete this test store now" case). The panel never
     *                    passes true: a window you can click past is not a window.
     * @return array{rows: int, users: int, clean: bool} what was destroyed
     */
    public function purge(Tenant $tenant, string $reason, bool $force = false): array
    {
        if (! $force) {
            if (! $tenant->isClosed()) {
                throw new InvalidArgumentException('Close the store first. Deleting a live store is never a single step.');
            }

            if (! $tenant->isPurgeable()) {
                $when = $tenant->purge_after?->format('d M Y') ?? 'a later date';
                throw new InvalidArgumentException("The retention window has not elapsed — this store's data can be deleted from {$when}.");
            }
        }

        // Read the identity and the counts BEFORE the row stops existing.
        $tenantId = $tenant->id;
        $name = $tenant->store_name;
        $accountId = $tenant->account_id;
        $counts = $this->inventory($tenantId);
        $users = User::withoutGlobalScopes()->where('tenant_id', $tenantId)->get();

        DB::transaction(function () use ($tenant, $users) {
            // Users first and by hand: the FK would otherwise strand them.
            foreach ($users as $user) {
                $user->forceDelete();
            }

            $tenant->delete();
        });

        // Verify rather than assume — the incident this logic came from was a
        // deletion that "worked" and left staff stranded behind it.
        $verification = $this->verify($tenantId);

        AdminAuditLog::record(
            'store.purged',
            "Permanently deleted {$name} and " . number_format(array_sum($counts)) . ' rows',
            null,   // the tenant no longer exists; a FK here would fail
            [
                'tenant_id' => $tenantId,
                'account_id' => $accountId,
                'store_name' => $name,
                'reason' => $reason,
                'rows_destroyed' => $counts,
                'users_destroyed' => $users->pluck('email')->all(),
                'verified_clean' => $verification['clean'],
                'leftovers' => $verification['leftovers'],
            ],
        );

        return [
            'rows' => array_sum($counts),
            'users' => $users->count(),
            'clean' => $verification['clean'],
        ];
    }

    /**
     * Row counts per tenant-owned table. Used for the "you are about to destroy
     * N rows" confirmation and to VERIFY the cascade actually fired afterwards.
     *
     * @return array<string,int>
     */
    public function inventory(string $tenantId): array
    {
        $counts = [];

        foreach (self::OWNED_TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'tenant_id')) {
                continue;
            }
            $counts[$table] = DB::table($table)->where('tenant_id', $tenantId)->count();
        }

        return $counts;
    }

    /**
     * Prove the deletion left nothing addressable behind.
     *
     * @return array{clean: bool, leftovers: array<string,int>, stranded_users: int}
     */
    public function verify(string $tenantId): array
    {
        $leftovers = array_filter($this->inventory($tenantId));
        $stranded = User::withoutGlobalScopes()->where('tenant_id', $tenantId)->count();

        return [
            'clean' => $leftovers === [] && $stranded === 0 && ! Tenant::find($tenantId),
            'leftovers' => $leftovers,
            'stranded_users' => $stranded,
        ];
    }
}
