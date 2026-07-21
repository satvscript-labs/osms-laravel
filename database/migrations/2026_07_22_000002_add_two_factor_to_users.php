<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEC-05 — TOTP two-factor for the superadmin account (available to any user).
 *
 * The superadmin can edit any store's subscription and read platform-wide data;
 * password-confirmation guards against CSRF-style abuse but does nothing against a
 * stolen password. Secret and recovery codes are stored encrypted (cast in the
 * model), so a database leak alone does not yield working second factors.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable()->after('password');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            // Only a CONFIRMED secret is enforced at login — this prevents a
            // half-finished setup (secret generated, never verified) from locking
            // the account out of its own dashboard.
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']);
        });
    }
};
