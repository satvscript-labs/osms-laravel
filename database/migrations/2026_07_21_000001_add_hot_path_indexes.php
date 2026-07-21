<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATA-02 / PERF-04 — composite indexes on the columns the hot list/aggregate
 * pages filter, sort, and sum by. Each tenant's queries cut on tenant_id first,
 * then need status / created_at / balance_due (orders), created_at (payments), and
 * birthday (customers). Additive and safe on both engines.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index(['tenant_id', 'status']);       // KPI counts, status filter
            $table->index(['tenant_id', 'created_at']);    // latest(), analytics range
            $table->index(['tenant_id', 'balance_due']);   // outstanding / dues
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->index(['tenant_id', 'created_at']);    // collected-by-method range
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->index(['tenant_id', 'birthday']);      // upcoming-birthday scope
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'status']);
            $table->dropIndex(['tenant_id', 'created_at']);
            $table->dropIndex(['tenant_id', 'balance_due']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'created_at']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'birthday']);
        });
    }
};
