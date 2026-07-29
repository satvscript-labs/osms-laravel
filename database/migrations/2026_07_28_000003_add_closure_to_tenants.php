<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P5 / REQ-7, matrix row 16 — closing a store, safely.
 *
 * Deliberately NOT Eloquent's SoftDeletes. A soft-deleted tenant disappears
 * from every query that does not say `withTrashed()`, which is exactly wrong
 * here: the operator must keep SEEING a closed store for the whole retention
 * window in order to reopen it, and its ledger rows must keep counting toward
 * lifetime revenue. Closure is a state, not an absence.
 *
 * The window is what makes the destructive action safe: closing is instant and
 * reversible; purging is not, and cannot happen before `purge_after`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->timestamp('closed_at')->nullable()->after('store_status');
            $table->string('closure_reason', 500)->nullable()->after('closed_at');
            // When the data may be destroyed. Set at closure, never earlier;
            // reopening clears it. Nothing purges without passing this date.
            $table->timestamp('purge_after')->nullable()->after('closure_reason');
            $table->foreignId('closed_by')->nullable()->after('purge_after')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('closed_by');
            $table->dropColumn(['closed_at', 'closure_reason', 'purge_after']);
        });
    }
};
