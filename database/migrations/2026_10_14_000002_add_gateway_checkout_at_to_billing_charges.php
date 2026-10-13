<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E3.1 — when the owner last opened online checkout for this bill (E3-B).
 *
 * For a short window after that, the bill may be being paid right now, so the
 * operator can't record cash for it, void it or replace it (HostelEase M4).
 *
 * DATA SAFETY (rule 8): one nullable column, no backfill. `down()` drops only it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('billing_charges', 'gateway_checkout_at')) {
            Schema::table('billing_charges', function (Blueprint $table) {
                $table->timestamp('gateway_checkout_at')->nullable()->after('gateway_link_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('billing_charges', 'gateway_checkout_at')) {
            Schema::table('billing_charges', function (Blueprint $table) {
                $table->dropColumn('gateway_checkout_at');
            });
        }
    }
};
