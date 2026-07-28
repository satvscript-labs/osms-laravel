<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P1 / REQ-4 — pricing authority moves from config to the database.
 *
 * Owner decision (2026-07-26): changing a list price, or giving one customer a
 * negotiated rate, must never require a code deploy. Plans become rows the
 * operator edits; config/billing.php 'plans' is demoted to SEED DEFAULTS and
 * the PriceResolver's last-resort fallback.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code')->unique();          // 'basic' — matches subscriptions.tier values
            $table->string('name');
            $table->decimal('monthly_price', 10, 2);
            $table->decimal('yearly_price', 10, 2);
            $table->json('features')->nullable();      // customer-facing bullet list
            // Inactive plans stay resolvable for existing subscribers; they just
            // can't be sold to new ones. Never delete a plan a subscription references.
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
