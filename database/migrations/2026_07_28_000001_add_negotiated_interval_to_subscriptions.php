<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AUD-04 — a negotiated price must know which interval it was agreed for.
 *
 * Before this, one column served both: ₹3,500 agreed as a YEARLY rate would be
 * charged as ₹3,500 MONTHLY the moment the billing period was switched — a
 * silent 12x error, which the preview reported faithfully because the code
 * really would have done it.
 *
 * Backfilled from the subscription's current interval, which is the only
 * defensible reading of an existing agreement: whatever they are billed on
 * today is what the price was agreed for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('negotiated_interval')->nullable()->after('negotiated_price');
        });

        // Existing negotiated prices: assume they were agreed for the interval
        // the store is on. Anything without a negotiated price stays null.
        //
        // Done row-by-row rather than one UPDATE ... COALESCE(interval, …):
        // `interval` is a reserved word in MySQL, and quoting it correctly
        // differs between MySQL and SQLite. There are a handful of rows.
        DB::table('subscriptions')
            ->whereNotNull('negotiated_price')
            ->select('id', 'interval')
            ->get()
            ->each(fn ($row) => DB::table('subscriptions')
                ->where('id', $row->id)
                ->update(['negotiated_interval' => $row->interval ?: 'monthly']));
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('negotiated_interval');
        });
    }
};
