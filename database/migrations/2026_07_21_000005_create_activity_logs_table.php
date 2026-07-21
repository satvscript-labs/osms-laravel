<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRIV-04 — a tenant-scoped, append-only activity trail so a store can answer
 * "who changed / deleted this record?" (DPDP grievance handling, internal misuse
 * detection). Records staff MUTATIONS (Rx create/edit/delete, customer permanent
 * delete, order cancel, settle-time discount changes). The actor name is
 * denormalised so the entry survives a user deletion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_name')->nullable();
            $table->string('action')->index();       // e.g. eye_record.deleted
            $table->string('subject_type')->nullable();
            $table->uuid('subject_id')->nullable();
            $table->string('description');
            $table->json('meta')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
