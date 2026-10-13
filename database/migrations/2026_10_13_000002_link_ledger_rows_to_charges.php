<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E2.1 — every payment says what it paid for (rule 6, 06 §3.2).
 *
 * `charge_id`: the charge this money settled. `granted_*`: the coverage it ACTUALLY
 * granted, which differs from the charge's quoted window when a locked-out customer
 * pays late (D-7).
 *
 * DATA SAFETY (rule 8): three nullable columns, NO backfill. Rows written before the
 * engine keep charge_id NULL — inventing a charge for them would be invented
 * history. The FK nulls out on delete (charges are never deleted anyway). `down()`
 * drops only these columns. Safe to re-run (guarded by hasColumn).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('subscription_invoices', 'charge_id')) {
            return;
        }

        Schema::table('subscription_invoices', function (Blueprint $table) {
            $table->uuid('charge_id')->nullable()->after('account_id')->index();
            $table->date('granted_start')->nullable()->after('period_end');
            $table->date('granted_end')->nullable()->after('granted_start');

            $table->foreign('charge_id')->references('id')->on('billing_charges')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('subscription_invoices', 'charge_id')) {
            return;
        }

        Schema::table('subscription_invoices', function (Blueprint $table) {
            // Foreign key, then its index, then the columns: SQLite refuses to drop
            // a column an index still names.
            $table->dropForeign(['charge_id']);
            $table->dropIndex(['charge_id']);
            $table->dropColumn(['charge_id', 'granted_start', 'granted_end']);
        });
    }
};
