<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P4 — the operator's knobs, and supervised mode.
 *
 * Playbook §5.2 rule 7: *"Automation-off must be a switch, per customer and
 * globally. The operator can put one customer (or the whole platform) into
 * supervised/manual mode without a deploy."*
 *
 * "Without a deploy" is the whole requirement, which is why these live in the
 * database rather than in config: a knob you have to ship code to turn is not
 * a knob.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A small, deliberately generic key/value store. Not a settings *table
        // per feature* — that shape needs a migration for every new knob, which
        // is the deploy this exists to avoid.
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();   // JSON-encoded
            $table->timestamps();
        });

        Schema::table('accounts', function (Blueprint $table) {
            // Per-customer supervised mode: this account cannot self-serve, so
            // every payment goes through the operator. Independent of the
            // platform-wide switch — either one turns it on.
            $table->boolean('supervised')->default(false)->after('status');
            $table->string('supervised_reason')->nullable()->after('supervised');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['supervised', 'supervised_reason']);
        });

        Schema::dropIfExists('platform_settings');
    }
};
