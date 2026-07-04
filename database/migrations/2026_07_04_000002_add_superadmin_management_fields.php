<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ST-Admin (S11) — fields supporting manual platform management.
 *   tenants.internal_notes  — private superadmin memo about a store.
 *   subscriptions.manual    — flags a subscription the superadmin set by hand
 *                             (bypassing Razorpay), so the UI can label it and
 *                             automation can treat it carefully.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->text('internal_notes')->nullable()->after('address');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->boolean('manual')->default(false)->after('pending_interval');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('internal_notes');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('manual');
        });
    }
};
