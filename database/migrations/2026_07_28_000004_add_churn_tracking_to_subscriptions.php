<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P6 / 03 §8 — make churn measurable, honestly.
 *
 * Churn was in the metrics table as "ship, but label honestly". It could not be
 * shipped at all, because nothing recorded WHEN a customer stopped. The only
 * available proxy was `updated_at`, which any later edit moves — a number that
 * looks like churn and is not. Playbook §9: *"if the underlying data cannot
 * support a metric honestly, do not display a fake one."* So the data changes,
 * not the standard.
 *
 * Three columns, because one date cannot answer the question:
 *
 *   churned_at    when they stopped. NULL for every row that predates this
 *                 migration, and the dashboard says so out loud rather than
 *                 pretending those cancellations never happened.
 *   churned_mrr   what they were worth per month at the moment they left.
 *                 Captured then because it is unrecoverable afterwards — MRR
 *                 of a cancelled subscription is 0 by definition.
 *   churned_from  the status they left FROM. This is what separates a paying
 *                 customer walking away (real churn) from a trial that never
 *                 converted (a different question, deferred in §8 for want of
 *                 volume). Counting them together would flatter or damn the
 *                 number depending on the month.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('churned_at')->nullable()->after('override_at');
            $table->decimal('churned_mrr', 10, 2)->nullable()->after('churned_at');
            $table->string('churned_from', 20)->nullable()->after('churned_mrr');

            // The dashboard asks "who left in the last 90 days" on every load.
            $table->index('churned_at');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex(['churned_at']);
            $table->dropColumn(['churned_at', 'churned_mrr', 'churned_from']);
        });
    }
};
