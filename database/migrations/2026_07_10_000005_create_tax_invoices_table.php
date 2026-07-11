<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FT-TaxInvoice — one formal tax invoice per order (opt-in, only when at
 * least one line item is flagged `on_tax_invoice`). The invoice number is
 * issued once and never changes; it's scoped to the tenant's Indian
 * financial year (Apr–Mar) so numbering restarts each year, matching how a
 * physical GST bill book is typically numbered — e.g. INV-2526-00001.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('order_id')->unique();
            $table->string('financial_year', 4); // e.g. "2526" = Apr 2025–Mar 2026
            $table->unsignedInteger('sequence');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->unique(['tenant_id', 'financial_year', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_invoices');
    }
};
