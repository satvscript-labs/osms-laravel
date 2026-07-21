<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DATA-04 — users.tenant_id had no foreign key, so a future tenant-deletion path
 * could orphan users pointing at a dead store. Add the FK (nullOnDelete — a
 * superadmin legitimately has no tenant).
 *
 * MySQL only: SQLite cannot ALTER-ADD a foreign key to an existing table without a
 * full rebuild, and rebuilding the framework `users` table (sessions FK, etc.) is
 * riskier than the dormant issue it fixes. The FK's value is production integrity
 * (MySQL); the app-layer guard in ResetPlatformData already deletes users explicitly.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql' && DB::getDriverName() !== 'mariadb') {
            return;
        }

        Schema::table('users', function ($table) {
            $table->foreign('tenant_id')->references('id')->on('tenants')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql' && DB::getDriverName() !== 'mariadb') {
            return;
        }

        Schema::table('users', function ($table) {
            $table->dropForeign(['tenant_id']);
        });
    }
};
