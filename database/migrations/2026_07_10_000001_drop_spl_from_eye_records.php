<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the "SPL" (special) prescription columns — the measurement carries no
 * clinical meaning for the stores using OSMS, so it's removed from the eye-record
 * form, card, and schema. Reversible: re-adds the nullable decimals on rollback.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('eye_records', function (Blueprint $table) {
            $table->dropColumn(['od_spl', 'os_spl']);
        });
    }

    public function down(): void
    {
        Schema::table('eye_records', function (Blueprint $table) {
            $table->decimal('od_spl', 6, 2)->nullable()->after('od_va');
            $table->decimal('os_spl', 6, 2)->nullable()->after('os_va');
        });
    }
};
