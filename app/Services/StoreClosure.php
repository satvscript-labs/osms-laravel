<?php

namespace App\Services;

use App\Models\AdminAuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Support\BackupStatus;
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
    /**
     * Every table whose rows a purge DESTROYS, for the before/after inventory.
     *
     * Billing is deliberately not here (feat-billing P.1 / FB-22): the subscription
     * and the payment ledger belong to the ACCOUNT, not to whichever store they
     * happened to be tagged with first. Counting them as "owned" is what made the
     * operator's "N rows will be destroyed" figure - and the post-delete check -
     * treat the deletion of money as normal.
     */
    public const OWNED_TABLES = [
        'customers', 'eye_records', 'patients', 'orders', 'order_items', 'payments',
        'inventory', 'stock_movements', 'tax_invoices', 'staff_invitations',
        'activity_logs', 'whatsapp_configs', 'whatsapp_messages',
    ];

    /**
     * The account's money. A purge must never delete a row from these tables;
     * deleting a store only clears the row's link to it (`tenant_id` -> NULL).
     */
    public const MONEY_TABLES = ['subscriptions', 'subscription_invoices'];

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
        // S-6 - the way back must exist before the door is closed. Applies to the CLI's
        // --force path too: skipping the retention window is a convenience, not a
        // reason to also skip the backup.
        $this->assertRecentBackup($tenant);

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

        // S-7 - remember exactly which billing rows exist, so that inside the
        // transaction we can prove none of them went with the store.
        $money = $this->moneyRowIds($tenantId, $accountId);

        /*
         * AUD-A06 — never delete an operator.
         *
         * A superadmin can legitimately carry a `tenant_id` (they were promoted
         * from a store account, or were seeded onto one), and this deleted
         * every user whose tenant_id matched — which for the wrong store meant
         * destroying the platform owner's own login, permanently, as a side
         * effect of tidying up a shop. They are detached instead: superadmins
         * are not tenant-owned data and a null tenant_id is their normal state.
         */
        $operators = User::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('role', 'superadmin')->get();

        $users = User::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('role', '!=', 'superadmin')->get();

        DB::transaction(function () use ($tenant, $users, $operators, $money) {
            foreach ($operators as $operator) {
                $operator->forceFill(['tenant_id' => null])->save();
            }

            // Store users first and by hand: the FK would otherwise strand them.
            foreach ($users as $user) {
                $user->forceDelete();
            }

            $tenant->delete();

            // S-7 - THE guard, independent of how the foreign keys happen to be set.
            // If the delete took any billing row with it (a cascade somebody
            // re-introduced, a new money table), this throws and the whole
            // transaction - users, operators, the store - rolls back. Money is
            // never an acceptable side effect of tidying up a shop.
            $this->assertMoneyIntact($money);
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
                // The other half of the record: what was deliberately KEPT.
                'billing_rows_kept' => array_map('count', $money),
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

    /** S-6: refuse to destroy anything unless a recent, plausible database backup exists. */
    private function assertRecentBackup(Tenant $tenant): void
    {
        if (! config('saas.purge_requires_backup', true)) {
            return;
        }

        if ($problem = BackupStatus::problem()) {
            throw new InvalidArgumentException(
                "Refusing to permanently delete {$tenant->store_name}: {$problem} "
                . 'A purge cannot be undone, so a recent backup has to exist first (scripts/backup-db.sh), then try again.'
            );
        }
    }

    /**
     * The ids of every billing row this store touches - tagged to it directly, or
     * belonging to its account (a row may be tagged with a DIFFERENT branch than the
     * one being purged, and rows from before the account backfill have no account yet).
     *
     * @return array<string, list<string>> table => ids
     */
    private function moneyRowIds(string $tenantId, ?string $accountId): array
    {
        $ids = [];

        foreach (self::MONEY_TABLES as $table) {
            $ids[$table] = DB::table($table)
                ->where(function ($w) use ($table, $tenantId, $accountId) {
                    $w->where('tenant_id', $tenantId);

                    if ($accountId && Schema::hasColumn($table, 'account_id')) {
                        $w->orWhere('account_id', $accountId);
                    }
                })
                ->pluck('id')
                ->all();
        }

        return $ids;
    }

    /**
     * S-7: every billing row that existed before the delete must still exist after it.
     * Throws (and so rolls the transaction back) if even one is gone.
     *
     * @param array<string, list<string>> $before table => ids, from moneyRowIds()
     */
    private function assertMoneyIntact(array $before): void
    {
        foreach ($before as $table => $ids) {
            $remaining = 0;

            // Chunked: an account with a long history must not hit the driver's
            // bound-parameter limit.
            foreach (array_chunk($ids, 500) as $chunk) {
                $remaining += DB::table($table)->whereIn('id', $chunk)->count();
            }

            if ($remaining !== count($ids)) {
                throw new InvalidArgumentException(sprintf(
                    'Refused: deleting this store would have destroyed %d billing record(s) in %s. '
                    . 'Nothing was deleted. Billing records must outlive a store.',
                    count($ids) - $remaining,
                    $table,
                ));
            }
        }
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
