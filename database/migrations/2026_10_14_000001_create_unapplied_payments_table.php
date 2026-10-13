<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E3.1 — money that arrived and matched no open bill (06 §3.3, FB-04, rule 4).
 *
 * A payment for an order OSMS doesn't know, for a bill already closed, or for an
 * amount that differs from the bill used to vanish (200, nothing recorded). It is
 * kept here instead, shown to the operator, and resolved by hand: applied to a
 * bill, or marked refunded. Not counted as collected until applied.
 *
 * DATA SAFETY (rule 8): a NEW table; nothing existing is touched. The account and
 * charge links null out on delete (they never are), so the money row survives.
 * `down()` drops only this table. Safe to re-run (guarded by hasTable).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('unapplied_payments')) {
            return;
        }

        Schema::create('unapplied_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id')->nullable()->index();
            $table->uuid('charge_id')->nullable()->index();   // the bill it was meant for, if known

            $table->string('gateway', 20)->default('razorpay');
            $table->string('gateway_payment_id')->unique();   // a payment is recorded here once
            $table->string('gateway_order_id')->nullable()->index();
            $table->string('gateway_event', 60)->nullable();   // order.paid / payment.captured / subscription.charged …

            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('INR');
            $table->string('reason', 500);                     // why it matched nothing — said to the operator
            $table->string('status', 20)->default('open')->index();   // open · applied · refunded

            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution_note', 500)->nullable();
            $table->uuid('ledger_row_id')->nullable();         // the ledger row it became, when applied

            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->foreign('account_id')->references('id')->on('accounts')->nullOnDelete();
            $table->foreign('charge_id')->references('id')->on('billing_charges')->nullOnDelete();
            $table->foreign('resolved_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unapplied_payments');
    }
};
