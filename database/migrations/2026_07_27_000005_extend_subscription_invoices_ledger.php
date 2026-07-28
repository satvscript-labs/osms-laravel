<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P1 / REQ-5 — subscription_invoices becomes the ONE channel-agnostic ledger.
 *
 * Every column that identified a payment was Razorpay-shaped, so a cash payment
 * could not be represented without lying about what it is. These columns let
 * every money path — webhook, operator cash/UPI/bank entry, ₹0 comp, reversal —
 * write the SAME table with the same shape, differing only in method + source.
 *
 * Deliberate choices:
 *  - method/source DEFAULT to razorpay/webhook: every existing row came from
 *    the webhook, so the defaults double as an exact backfill.
 *  - A reversal is a STATE (reversed_at), never a delete. Both rows stay visible.
 *  - receipt_no is nullable: PaymentRecorder numbers the rows it writes now;
 *    webhook rows get numbered when P4 wires receipt issuance end-to-end.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_invoices', function (Blueprint $table) {
            // The ledger belongs to the payer. tenant_id is retained and now means
            // "which store this line related to" (nullable in P3+ for account-level
            // charges; every existing row keeps its store).
            $table->uuid('account_id')->nullable()->after('tenant_id')->index();

            // razorpay · cash · upi · bank_transfer · cheque · comp · adjustment
            $table->string('method')->default('razorpay')->after('status');
            // self_serve · operator · webhook · import — which lane wrote this
            $table->string('source')->default('webhook')->after('method');
            $table->string('reference')->nullable()->after('source');   // UPI ref / cheque no / UTR
            $table->unsignedBigInteger('recorded_by')->nullable()->after('reference');
            $table->string('reason', 500)->nullable()->after('recorded_by'); // required for comp/adjustment (service-enforced)

            // What coverage this payment bought — the columns co-termination (PR-13)
            // and honest revenue reporting both depend on.
            $table->date('period_start')->nullable()->after('reason');
            $table->date('period_end')->nullable()->after('period_start');

            $table->string('receipt_no')->nullable()->unique()->after('period_end');

            $table->timestamp('reversed_at')->nullable()->after('receipt_no');
            $table->unsignedBigInteger('reversed_by')->nullable()->after('reversed_at');
            $table->string('reversal_reason', 500)->nullable()->after('reversed_by');

            $table->foreign('account_id')->references('id')->on('accounts')->restrictOnDelete();
            $table->foreign('recorded_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('reversed_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('subscription_invoices', function (Blueprint $table) {
            $table->dropForeign(['account_id']);
            $table->dropForeign(['recorded_by']);
            $table->dropForeign(['reversed_by']);
            $table->dropColumn([
                'account_id', 'method', 'source', 'reference', 'recorded_by', 'reason',
                'period_start', 'period_end', 'receipt_no',
                'reversed_at', 'reversed_by', 'reversal_reason',
            ]);
        });
    }
};
