<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P1 / REQ-12 — the account layer: the paying identity, sitting ABOVE stores.
 *
 * "Having a store doesn't mean that store is our customer. We are getting paid
 * by Rushi, who is the owner of the store." A store (tenant) is what data is
 * isolated by; an account is who pays. Today they are 1:1 — the backfill
 * creates exactly one account per existing tenant — but the shape admits
 * multi-branch accounts without ever migrating money again.
 *
 * The rule in one line: money and identity live on the ACCOUNT; data and
 * isolation live on the STORE. `tenant_id` remains the isolation key
 * everywhere (owner decision Q-B, 2026-07-27).
 *
 * See _artifacts/platform/06_CUSTOMER_ACCOUNT_MODEL.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');                          // the payer: "Rushi Dharsandiya"
            $table->string('display_name')->nullable();      // for paperwork, if different
            $table->string('billing_email')->nullable();
            $table->string('billing_phone')->nullable();
            $table->text('billing_address')->nullable();
            $table->string('tax_id')->nullable();            // THEIR GSTIN, if any
            // Presentational lifecycle label, synced from the subscription by the
            // backfill and (from P3) the lifecycle service. Access checks still
            // derive from Subscription::accessState() — never from this column.
            $table->string('status')->default('trialing');
            $table->text('internal_notes')->nullable();      // moves up from tenants
            $table->unsignedBigInteger('owner_user_id')->nullable();
            $table->timestamps();

            // users.id is bigint (Breeze). The commercial record outlives staff.
            $table->foreign('owner_user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('tenants', function (Blueprint $table) {
            // Nullable through the transition; the backfill populates it, and a
            // later release makes it required once everything reads accounts.
            $table->uuid('account_id')->nullable()->after('id')->index();
            // An account may hold a store it isn't charged for (a pilot, a branch
            // during setup). Quantity for billing = count of billable stores.
            $table->boolean('is_billable')->default(true)->after('account_id');
            // Per-store lever, independent of the account lifecycle: suspend one
            // branch without touching the others (dual-lane matrix row 10).
            $table->string('store_status')->default('active')->after('is_billable');

            // Deleting an account with live stores must be impossible by accident;
            // closure (P5) deletes in explicit order instead.
            $table->foreign('account_id')->references('id')->on('accounts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropForeign(['account_id']);
            $table->dropColumn(['account_id', 'is_billable', 'store_status']);
        });

        Schema::dropIfExists('accounts');
    }
};
