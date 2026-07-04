<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FT-SmartRx (5.2.3) — persist the free-text "Examined by" name. Distinct from
 * `recorded_by` (the user FK who saved the record): `checked_by` is who actually
 * performed the exam (may be a visiting optometrist), defaulting to the staff
 * member's name. Additive, portable, reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('eye_records', function (Blueprint $table) {
            $table->string('checked_by')->nullable()->after('recorded_by');
        });
    }

    public function down(): void
    {
        Schema::table('eye_records', function (Blueprint $table) {
            $table->dropColumn('checked_by');
        });
    }
};
