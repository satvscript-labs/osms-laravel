<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WEB-01 — the dashboard's "waiting N days for pickup" clock read `updated_at`,
 * so ANY later save (recording a payment, an edit) reset it and an order that had
 * been waiting a fortnight could show as fresh. `ready_at` records when the order
 * actually entered ready_for_pickup, independent of unrelated writes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('ready_at')->nullable()->after('status');
        });

        // Backfill: for orders already waiting, updated_at is the best estimate we
        // have of when they became ready. Without this every existing ready order
        // would read as "0 days waiting".
        DB::table('orders')
            ->where('status', 'ready_for_pickup')
            ->whereNull('ready_at')
            ->update(['ready_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('ready_at');
        });
    }
};
