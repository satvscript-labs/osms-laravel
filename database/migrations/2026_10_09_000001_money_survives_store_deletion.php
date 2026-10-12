<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * feat-billing P.1 / FB-22 - money must outlive the store it was first tagged to.
 *
 * `subscriptions.tenant_id` and `subscription_invoices.tenant_id` were
 * `ON DELETE CASCADE`. Both rows are tagged with the account's ORIGINAL store, so
 * purging that store - the panel's own, reason-gated, typed-confirmation action -
 * silently deleted the account's subscription AND its payment history, and locked
 * out any branch still trading. (Reproduced: subscriptions 1 -> 0, ledger 2 -> 1.)
 *
 * Now a store's deletion only clears the link:
 *
 *     tenant_id   NOT NULL / CASCADE   ->   NULL-able / SET NULL
 *
 * The rows stay. The account (`account_id`, restrict-on-delete) still owns them, so
 * history remains attributable to the customer.
 *
 * DATA SAFETY (D-9, rule 8) - this migration:
 *   - changes constraint BEHAVIOUR only; it modifies no existing row;
 *   - is safe to re-run after a partial failure (MySQL DDL is not transactional,
 *     so each step checks the current state rather than assuming it);
 *   - has a down() that restores the cascade but deliberately KEEPS the column
 *     nullable: re-imposing NOT NULL would fail, or force a choice about data,
 *     the moment any row has lost its store link.
 */
return new class extends Migration
{
    private const TABLES = ['subscriptions', 'subscription_invoices'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            $fk = $this->tenantForeignKey($table);

            if ($fk && ($fk['on_delete'] ?? null) !== 'set null') {
                Schema::table($table, fn (Blueprint $t) => $t->dropForeign(['tenant_id']));
                $fk = null;
            }

            // Idempotent: nullable() on an already-nullable column is a no-op.
            Schema::table($table, fn (Blueprint $t) => $t->uuid('tenant_id')->nullable()->change());

            if (! $fk) {
                Schema::table($table, fn (Blueprint $t) => $t->foreign('tenant_id')
                    ->references('id')->on('tenants')->nullOnDelete());
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if ($this->tenantForeignKey($table)) {
                Schema::table($table, fn (Blueprint $t) => $t->dropForeign(['tenant_id']));
            }

            Schema::table($table, fn (Blueprint $t) => $t->foreign('tenant_id')
                ->references('id')->on('tenants')->cascadeOnDelete());
        }
    }

    /** @return array<string, mixed>|null the foreign key on tenant_id, if there is one */
    private function tenantForeignKey(string $table): ?array
    {
        foreach (Schema::getForeignKeys($table) as $fk) {
            if (($fk['columns'] ?? []) === ['tenant_id']) {
                return $fk;
            }
        }

        return null;
    }
};
