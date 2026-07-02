<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FT-Fulfillment — distinguish an instant "grab & go" counter sale from a
 * special/prepared order that needs lab time.
 *
 * `fulfillment_type` (instant | special) drives the initial order status:
 * instant orders are created already `delivered`; special orders keep the
 * `pending → ready_for_pickup → delivered` pipeline and carry a promised
 * `estimated_ready_at` date.
 *
 * Backward-compatible: the column default backfills every existing order to
 * `special` (informational only — it never changes a historical order's
 * behavior). Portable additive columns (no enum widening / driver branching).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('fulfillment_type')->default('special')->after('status'); // instant | special
            $table->date('estimated_ready_at')->nullable()->after('fulfillment_type');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['fulfillment_type', 'estimated_ready_at']);
        });
    }
};
