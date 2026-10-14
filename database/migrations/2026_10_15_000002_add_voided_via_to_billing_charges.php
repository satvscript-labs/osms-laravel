<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F4 (E1–E4 audit) — HOW a bill was voided: `operator` (someone pressed Void),
 * `ending` (a cancellation, free months, end of access) or `system` (churn, trial
 * expiry). The morning clock respects a person's Void for the cycle (A-16) but
 * must not mistake an ending for one — or undoing a cancellation leaves the next
 * renewal unbilled.
 *
 * DATA SAFETY (rule 8): one nullable column, no backfill. `down()` drops only it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('billing_charges', 'voided_via')) {
            Schema::table('billing_charges', function (Blueprint $table) {
                $table->string('voided_via', 20)->nullable()->after('voided_by');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('billing_charges', 'voided_via')) {
            Schema::table('billing_charges', function (Blueprint $table) {
                $table->dropColumn('voided_via');
            });
        }
    }
};
