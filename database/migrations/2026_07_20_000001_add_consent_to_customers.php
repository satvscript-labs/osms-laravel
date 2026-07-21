<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRIV-01 — consent capture for customer PII + health data (DPDP).
 *
 * `data_consent_at` records WHEN the store noted the customer's consent to store
 * their details/prescription (null = not recorded). `whatsapp_opt_in` is the
 * separate, specific consent to receive WhatsApp messages — the marketing/reminder
 * surfaces read it before treating a number as contactable.
 *
 * Additive + backfill-safe: existing rows get null consent (unrecorded) and
 * opt-in false (no messaging consent assumed) — the privacy-preserving default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->timestamp('data_consent_at')->nullable()->after('gender');
            $table->boolean('whatsapp_opt_in')->default(false)->after('data_consent_at');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['data_consent_at', 'whatsapp_opt_in']);
        });
    }
};
