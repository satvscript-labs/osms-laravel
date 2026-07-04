<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FT-LocalItems (6.4) — off-inventory/local line items.
 *
 * A line item may now be a free-text "local" item the store carries but never
 * catalogued (a one-off, a favour item, something bought in for a customer).
 * Such a line has no `inventory_id` (already nullable since the table was
 * created) and instead a free-text `description`. It is not stock-tracked:
 * no decrement, no oversell guard, no StockMovement. `list_price` for a custom
 * line equals its `unit_price`, so "discount given" math reports zero for it.
 *
 * Additive + portable (SQLite dev / MySQL prod). Existing catalog lines keep a
 * null `description`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->string('description')->nullable()->after('inventory_id');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
