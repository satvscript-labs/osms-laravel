<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the "D.V." (distance vision) columns. They were never actually
 * captured by the eye-record form — D.V. is just the row label for the
 * SPH/CYL/Axis/VA block, which already has its own columns — so od_dv/os_dv
 * were always null and only cluttered the history card with a dead column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('eye_records', function (Blueprint $table) {
            $table->dropColumn(['od_dv', 'os_dv']);
        });
    }

    public function down(): void
    {
        Schema::table('eye_records', function (Blueprint $table) {
            $table->decimal('od_dv', 6, 2)->nullable()->after('od_va');
            $table->decimal('os_dv', 6, 2)->nullable()->after('os_va');
        });
    }
};
