<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ST-Admin (S11) — an append-only audit trail of every superadmin action.
 * Platform-level (NOT tenant-scoped): it records who did what, to which store,
 * from which IP. Denormalises the actor email so the record survives a user
 * deletion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('admin_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('admin_email')->nullable();
            $table->string('action')->index();
            $table->uuid('tenant_id')->nullable()->index();
            $table->string('description');
            $table->json('meta')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_audit_logs');
    }
};
