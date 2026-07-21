<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BIZ-03 — a tax invoice is a numbered legal document; its contents must be frozen
 * at issue time. `snapshot` stores the exact store/customer/line/GST figures as they
 * were when the number was allocated, so a later order edit or GST-rate change can
 * never rewrite an already-issued invoice. Legacy rows (snapshot null) fall back to
 * live rendering.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tax_invoices', function (Blueprint $table) {
            $table->json('snapshot')->nullable()->after('sequence');
        });
    }

    public function down(): void
    {
        Schema::table('tax_invoices', function (Blueprint $table) {
            $table->dropColumn('snapshot');
        });
    }
};
