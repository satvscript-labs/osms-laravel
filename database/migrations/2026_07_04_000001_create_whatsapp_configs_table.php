<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FT-WhatsApp — per-tenant WhatsApp messaging configuration.
 *
 * One row per store. The full schema is created now (including the automated /
 * Cloud API columns) even though Phase 3 ships the manual flow only, so later
 * phases never have to reshape this table. A store with no row behaves as a
 * fresh Manual store (zero-config default).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_configs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->unique(); // one config per store
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            // Master switch: off | manual | automated. Default manual = value on day one.
            $table->string('mode')->default('manual');

            // Per-event toggles (apply to both manual + automated).
            $table->boolean('on_placed')->default(true);
            $table->boolean('on_ready')->default(true);
            $table->boolean('on_delivered')->default(true);
            $table->boolean('on_birthday')->default(true);

            // Manual-mode wording (null ⇒ fall back to config('whatsapp.default_messages')).
            $table->text('msg_placed')->nullable();
            $table->text('msg_ready')->nullable();
            $table->text('msg_delivered')->nullable();
            $table->text('msg_birthday')->nullable();

            // Automated-mode (Cloud API) — inert until the send engine (Phase 4) lands.
            $table->string('provider')->default('meta');
            $table->string('phone_number_id')->nullable();
            $table->string('waba_id')->nullable();
            $table->text('access_token')->nullable();       // encrypted cast on the model
            $table->string('display_phone')->nullable();
            $table->string('tpl_placed')->nullable();
            $table->string('tpl_ready')->nullable();
            $table->string('tpl_delivered')->nullable();
            $table->string('tpl_birthday')->nullable();
            $table->string('tpl_lang')->default('en');
            $table->boolean('enabled')->default(false);     // automated master enable
            $table->timestamp('verified_at')->nullable();   // set once a live test send succeeds
            $table->boolean('needs_attention')->default(false); // repeated auth failure ⇒ force fallback

            $table->string('default_country_code')->default('+91');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_configs');
    }
};
