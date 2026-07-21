<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATA-07 — eye records (clinical prescriptions) were hard-deleted with no recovery
 * window and no trace. Add soft deletes so a mis-click is recoverable for 30 days
 * (then purged, like customers/inventory) and the deletion can be audited.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('eye_records', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('eye_records', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
