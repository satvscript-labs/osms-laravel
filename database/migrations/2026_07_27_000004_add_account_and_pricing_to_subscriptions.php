<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P1 / REQ-12 + REQ-4 — the subscription becomes account-scoped and carries the
 * negotiated arrangement.
 *
 * `tenant_id` is RETAINED through the transition (owner decision E6: dual-write
 * for one release so rollback is a config flip, not a migration). Nothing reads
 * it once `account_id` is populated; a later release drops it.
 *
 * `quantity` = number of billable stores. 1 for every existing subscription.
 * It exists now so the co-termination work parked as PR-13 is an addition,
 * not a schema rewrite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->uuid('account_id')->nullable()->after('tenant_id')->index();
            $table->unsignedInteger('quantity')->default(1)->after('interval');

            // REQ-4 — pricing. plan_id supersedes the loose `tier` enum over time;
            // tier is kept for one release (same dual-write rule as tenant_id).
            $table->uuid('plan_id')->nullable()->after('tier');
            $table->decimal('negotiated_price', 10, 2)->nullable()->after('plan_id');
            $table->string('negotiated_reason', 500)->nullable()->after('negotiated_price');
            $table->unsignedBigInteger('negotiated_by')->nullable()->after('negotiated_reason');
            $table->timestamp('negotiated_at')->nullable()->after('negotiated_by');

            $table->foreign('account_id')->references('id')->on('accounts')->restrictOnDelete();
            $table->foreign('plan_id')->references('id')->on('plans')->restrictOnDelete();
            $table->foreign('negotiated_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropForeign(['account_id']);
            $table->dropForeign(['plan_id']);
            $table->dropForeign(['negotiated_by']);
            $table->dropColumn([
                'account_id', 'quantity', 'plan_id',
                'negotiated_price', 'negotiated_reason', 'negotiated_by', 'negotiated_at',
            ]);
        });
    }
};
