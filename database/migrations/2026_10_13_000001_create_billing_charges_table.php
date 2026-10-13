<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E2.1 — `billing_charges`: what is OWED (06 §3.1).
 *
 * OSMS had no word for "money asked for that has not arrived", so a branch join
 * wrote a PAID row for money nobody received (FB-18) and a reversal left the debt
 * recorded nowhere (FB-20). A charge is raised by the engine with its amount locked
 * and its working stored; a payment then settles it.
 *
 * DATA SAFETY (rule 8): a NEW table, nothing existing is touched. The account FK
 * restricts deletes (money outlives everything); the store and subscription FKs
 * null out, never cascade, so purging a store can't remove a debt (S-7).
 * `down()` drops only this table. Safe to re-run (guarded by hasTable).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('billing_charges')) {
            return;
        }

        Schema::create('billing_charges', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('account_id')->index();
            $table->uuid('subscription_id')->nullable()->index();
            // The branch that caused it (branch join), for "what did Branch 2 cost?".
            $table->uuid('tenant_id')->nullable()->index();

            // CHG-2026-0001 — what the customer and (E3) the gateway see. Not a receipt.
            $table->string('number')->unique();
            $table->string('kind', 20);                     // first_term · renewal · branch_join
            $table->string('status', 20)->default('pending')->index(); // pending · paid · void · superseded

            // Locked at raise, from the engine. Never edited: a new price is a new charge.
            $table->decimal('amount', 12, 2);
            $table->unsignedSmallInteger('cycles')->default(1);
            $table->string('interval', 10)->nullable();
            $table->unsignedInteger('quantity')->nullable();
            $table->decimal('unit_price', 12, 2)->nullable();
            $table->string('tier_label')->nullable();
            $table->json('calculation')->nullable();        // the working, rendered verbatim

            // The window this charge buys, AS QUOTED (D-7: the payment records what it granted).
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->date('due_on')->nullable();

            // E6 — one open demand per purpose: set while pending, nulled on close.
            $table->string('open_key')->nullable()->unique();

            $table->unsignedBigInteger('raised_by')->nullable();
            $table->string('raised_via', 20);               // operator · owner · clock · provision · system

            $table->timestamp('paid_at')->nullable();
            // E2-G — the coverage this charge has granted. Kept through a reopen, so
            // paying a reopened charge again can never grant the same cycle twice.
            $table->date('granted_start')->nullable();
            $table->date('granted_end')->nullable();

            $table->timestamp('voided_at')->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->string('void_reason', 500)->nullable();
            $table->uuid('superseded_by')->nullable();
            $table->timestamp('reopened_at')->nullable();

            // E3 / later C — the gateway instruments attached to this charge.
            $table->string('gateway_order_id')->nullable()->unique();
            $table->string('gateway_link_id')->nullable()->unique();

            $table->timestamps();

            $table->foreign('account_id')->references('id')->on('accounts')->restrictOnDelete();
            $table->foreign('subscription_id')->references('id')->on('subscriptions')->nullOnDelete();
            $table->foreign('tenant_id')->references('id')->on('tenants')->nullOnDelete();
            $table->foreign('raised_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('voided_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_charges');
    }
};
