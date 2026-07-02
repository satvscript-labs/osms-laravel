<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FT-OrderMoney — order-level discount + per-line list-price snapshot.
 *
 * Orders gain `subtotal` (gross of line unit_price×qty), `discount_type`
 * (none|percent|amount), `discount_value` (what the user entered), and
 * `discount_amount` (resolved ₹). `total_amount` is now `subtotal − discount_amount`.
 * Order items gain `list_price` — the item's list/MRP at sale time — so a custom
 * `unit_price` can be reported against it (per-item discount).
 *
 * Backward-compatible: existing orders backfill `subtotal = total_amount`
 * (discount none), and existing line items backfill `list_price = unit_price`.
 * Portable (SQLite dev / MySQL prod); `after()` is a no-op on SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('subtotal', 10, 2)->default(0)->after('status');
            $table->string('discount_type')->default('none')->after('subtotal'); // none | percent | amount
            $table->decimal('discount_value', 10, 2)->default(0)->after('discount_type');
            $table->decimal('discount_amount', 10, 2)->default(0)->after('discount_value');
        });

        // Existing orders had no discount: their subtotal equals the stored total.
        DB::table('orders')->update(['subtotal' => DB::raw('total_amount')]);

        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('list_price', 10, 2)->nullable()->after('unit_price');
        });

        // Existing lines had no custom price: list price equals the captured unit price.
        DB::table('order_items')->update(['list_price' => DB::raw('unit_price')]);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['subtotal', 'discount_type', 'discount_value', 'discount_amount']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('list_price');
        });
    }
};
