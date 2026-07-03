<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ST-Billing — track the billing interval (monthly/yearly) so the subscription
 * page can display it and offer a cycle change. pending_interval holds a change
 * scheduled for the next renewal until the webhook confirms it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('interval')->nullable()->after('tier');
            $table->string('pending_interval')->nullable()->after('interval');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['interval', 'pending_interval']);
        });
    }
};
