<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ST-Billing (S4) — subscription self-service + invoices + webhook idempotency.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Pending-cancel flag: keep access until period end, then the webhook
        // flips status to canceled.
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->boolean('cancel_at_period_end')->default(false)->after('status');
        });

        // Subscription payment receipts (from subscription.charged webhooks).
        Schema::create('subscription_invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('razorpay_payment_id')->nullable()->unique();
            $table->string('razorpay_invoice_id')->nullable();
            $table->string('razorpay_subscription_id')->nullable();
            $table->decimal('amount', 10, 2)->default(0);
            $table->string('currency', 3)->default('INR');
            $table->string('status')->default('paid');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        // Idempotency ledger — a Razorpay event is processed at most once.
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->string('id')->primary(); // Razorpay event id
            $table->string('type')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('subscription_invoices');
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('cancel_at_period_end');
        });
    }
};
