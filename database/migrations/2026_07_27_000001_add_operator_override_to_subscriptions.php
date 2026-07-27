<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P0 / BUG-P01 — make an operator's manual decision survive the next Razorpay webhook.
 *
 * Before this, `RazorpayWebhookController` overwrote `status`, `current_period_end`
 * and `interval` from the gateway payload without consulting the `manual` flag — so a
 * hand-granted 12-month comp silently collapsed to 30 days the moment Razorpay charged
 * the still-live mandate. Reproduced against this codebase before the fix.
 *
 * `manual` alone could not express the fix: it says "a human touched this at some point",
 * not "a human's decision is currently in force, until <date>". These columns say the
 * latter, which is what the guard needs.
 *
 *   override_kind  — null = no override in force. Set = an operator decision governs.
 *   override_until — the date the decision expires. NULL *with a kind set* means
 *                    INDEFINITE (a cancellation or suspension holds until cleared).
 *
 * See _artifacts/platform/03_SUPERADMIN_DESIGN.md §2.4.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('override_kind')->nullable()->after('manual');
            $table->date('override_until')->nullable()->after('override_kind');
            $table->string('override_reason', 500)->nullable()->after('override_until');
            $table->unsignedBigInteger('override_by')->nullable()->after('override_reason');
            $table->timestamp('override_at')->nullable()->after('override_by');

            // users.id is bigint (Breeze), unlike the UUID business tables.
            // nullOnDelete: losing the operator's user row must never cascade into
            // deleting a subscription — the commercial record outlives the staff member.
            $table->foreign('override_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropForeign(['override_by']);
            $table->dropColumn([
                'override_kind',
                'override_until',
                'override_reason',
                'override_by',
                'override_at',
            ]);
        });
    }
};
