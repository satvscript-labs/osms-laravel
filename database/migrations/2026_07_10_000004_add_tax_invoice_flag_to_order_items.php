<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FT-TaxInvoice — per-line opt-in: a shop owner marks only the items a
 * customer wants a formal tax invoice for (e.g. a branded frame), not the
 * whole order. Defaults false so existing/ordinary orders are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->boolean('on_tax_invoice')->default(false)->after('list_price');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('on_tax_invoice');
        });
    }
};
