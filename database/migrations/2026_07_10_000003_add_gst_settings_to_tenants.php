<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FT-TaxInvoice — whether a store is GST-registered, and at what rate. Drives
 * whether the formal tax invoice computes a CGST/SGST breakup (registered) or
 * stays a plain itemised bill (many small opticians aren't GST-registered).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('gst_enabled')->default(false)->after('tax_id');
            $table->decimal('gst_rate', 5, 2)->nullable()->after('gst_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['gst_enabled', 'gst_rate']);
        });
    }
};
