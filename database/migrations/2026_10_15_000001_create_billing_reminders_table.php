<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E4 — which renewal reminder went to whom, once per stage per cycle (E4-C).
 *
 * The unique key is what makes the daily run idempotent: run it twice, or twice at
 * once, and a stage is still sent once. Keyed on the cycle (the date the period
 * ends), not the bill, because a re-quoted bill gets a new number.
 *
 * DATA SAFETY (rule 8): a NEW table; nothing existing is touched. Not a money
 * table. Links null out on delete so the record survives. `down()` drops only it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('billing_reminders')) {
            return;
        }

        Schema::create('billing_reminders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id')->nullable();
            $table->uuid('subscription_id')->nullable()->index();
            $table->uuid('charge_id')->nullable()->index();      // the bill it was about, if one was open
            $table->date('cycle_end');                           // the period end the reminder is about
            $table->string('stage', 20);                         // due_7 · due_1 · due_0 · grace · locked
            $table->string('channel', 20)->default('email');
            $table->string('recipients', 500)->nullable();
            $table->decimal('amount', 12, 2)->nullable();        // what it asked for, as sent
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->unique(['account_id', 'cycle_end', 'stage']);
            $table->foreign('account_id')->references('id')->on('accounts')->nullOnDelete();
            $table->foreign('charge_id')->references('id')->on('billing_charges')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_reminders');
    }
};
