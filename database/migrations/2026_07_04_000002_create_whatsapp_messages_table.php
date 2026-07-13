<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FT-WhatsApp — the message log / schedule / dedupe table (Automated mode).
 *
 * One row per outbound message. It is the source of truth for: idempotency
 * (one non-cancelled row per order+event), the scheduled-send + undo window
 * (status=scheduled, scheduled_for), and delivery feedback. Also carries the
 * optional soft "manual opened" audit rows (channel=manual).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('customer_id')->nullable();
            $table->uuid('order_id')->nullable();

            $table->string('event');                       // order_placed | order_ready | order_delivered | birthday | manual
            $table->string('channel')->default('cloud_api'); // cloud_api | manual
            $table->string('to_phone');
            $table->string('template_name')->nullable();
            $table->json('payload')->nullable();           // resolved body params + rendered text

            $table->string('status')->default('scheduled'); // scheduled | sent | failed | cancelled | opened
            $table->timestamp('scheduled_for')->nullable(); // when the sweep may send it
            $table->timestamp('sent_at')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->string('delivery_status')->nullable();  // delivered | read | failed (webhook)
            $table->text('error')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);

            $table->timestamps();

            $table->index('tenant_id');
            $table->index(['order_id', 'event']);           // dedupe lookups
            $table->index(['status', 'scheduled_for']);     // the sweep
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
    }
};
