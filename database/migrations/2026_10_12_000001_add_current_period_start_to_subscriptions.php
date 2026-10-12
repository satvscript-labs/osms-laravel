<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E1.1 - the start of the period now running, stored instead of derived.
 *
 * The engine has been working the start out as `end - interval`, which is wrong
 * after a pending interval switch lands (FB-19) or a customer prepays more than
 * one period (FB-13). From here the lifecycle writes it on every period change.
 *
 * DATA SAFETY (rule 8): purely additive. One nullable column, NO backfill: an
 * existing row's true period start is not recorded anywhere reliable, and a
 * guessed date would be invented history. Rows stay NULL until their next period
 * change; readers fall back to the derivation they use today. `down()` drops only
 * this column. Safe to re-run (guarded by hasColumn).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('subscriptions', 'current_period_start')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->date('current_period_start')->nullable()->after('cancel_at_period_end');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('subscriptions', 'current_period_start')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->dropColumn('current_period_start');
            });
        }
    }
};
