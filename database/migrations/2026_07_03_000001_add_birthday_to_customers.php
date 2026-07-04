<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FT-Birthday (5.5) — capture a customer's birthday so age can be derived (and
 * kept for future reminders/marketing). `age` stays as an optional fallback for
 * customers who won't share a birthday. Additive, portable, reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->date('birthday')->nullable()->after('age');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('birthday');
        });
    }
};
