<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FB-32 — the day of the month this account renews on (1–31).
 *
 * Month-end dates are clamped (31 Jan → 28 Feb), and this remembers the 31 so the
 * next renewal goes back to 31 Mar instead of drifting to 28 Mar for ever.
 *
 * DATA SAFETY (rule 8): one nullable column, no backfill — while it is empty the
 * anchor is the day the current period ends, which is what every live customer
 * already has. `down()` drops only it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('subscriptions', 'billing_anchor_day')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->unsignedTinyInteger('billing_anchor_day')->nullable()->after('current_period_end');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('subscriptions', 'billing_anchor_day')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->dropColumn('billing_anchor_day');
            });
        }
    }
};
