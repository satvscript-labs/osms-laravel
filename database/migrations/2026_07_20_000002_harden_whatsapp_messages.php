<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DATA-01 / PRIV-05 — harden whatsapp_messages.
 *
 *  • Foreign keys: tenant_id + customer_id cascade on delete (so erasing a store
 *    or a customer never leaves orphaned rows holding a phone number — PRIV-05),
 *    order_id nulls on delete.
 *  • dedupe_key + unique index: DB-level "one LIVE message per order+event"
 *    (scheduled/sent carry the key; cancelled/failed clear it so a legit re-send is
 *    allowed) — closes the check-then-insert race the app-level guard alone can't.
 *
 * SQLite cannot ALTER-ADD foreign keys, so the table is rebuilt via a temp table
 * and swapped in (index/FK names are auto-generated against the temp name, which
 * avoids the global-name collision that a rename-in-place would hit). Existing rows
 * are copied across; in production this table is empty (Automated is frozen).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->buildTable('whatsapp_messages_new', withHardening: true);

        foreach (DB::table('whatsapp_messages')->get() as $row) {
            $data = (array) $row;
            $data['dedupe_key'] = (in_array($row->status, ['scheduled', 'sent'], true) && $row->order_id)
                ? $row->order_id . ':' . $row->event
                : null;
            DB::table('whatsapp_messages_new')->insert($data);
        }

        Schema::dropIfExists('whatsapp_messages');
        Schema::rename('whatsapp_messages_new', 'whatsapp_messages');
    }

    public function down(): void
    {
        $this->buildTable('whatsapp_messages_old', withHardening: false);

        foreach (DB::table('whatsapp_messages')->get() as $row) {
            $data = (array) $row;
            unset($data['dedupe_key']);
            DB::table('whatsapp_messages_old')->insert($data);
        }

        Schema::dropIfExists('whatsapp_messages');
        Schema::rename('whatsapp_messages_old', 'whatsapp_messages');
    }

    private function buildTable(string $name, bool $withHardening): void
    {
        Schema::create($name, function (Blueprint $table) use ($withHardening) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('customer_id')->nullable();
            $table->uuid('order_id')->nullable();
            $table->string('event');
            if ($withHardening) {
                $table->string('dedupe_key')->nullable();
            }
            $table->string('channel')->default('cloud_api');
            $table->string('to_phone');
            $table->string('template_name')->nullable();
            $table->json('payload')->nullable();
            $table->string('status')->default('scheduled');
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->string('delivery_status')->nullable();
            $table->text('error')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamps();

            $table->index('tenant_id');
            $table->index(['order_id', 'event']);
            $table->index(['status', 'scheduled_for']);

            if ($withHardening) {
                $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
                $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
                $table->foreign('order_id')->references('id')->on('orders')->nullOnDelete();
                $table->unique('dedupe_key'); // NULLs are exempt on both MySQL and SQLite
            }
        });
    }
};
