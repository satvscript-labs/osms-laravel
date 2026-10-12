<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * feat-billing P.2 - one free trial per ACCOUNT, durably.
 *
 * Until now "this account has had its trial" was only inferable from a
 * subscription row existing. Rows can be lost (FB-22 deleted them), and a store
 * added afterwards then minted a SECOND free trial. A column on the account
 * records the fact independently of any subscription.
 *
 * DATA SAFETY (D-9, rule 8): purely additive. One new nullable column; the
 * backfill writes ONLY that column, ONLY where it is still empty, from a value
 * that already exists (the account's first subscription row), and is safe to run
 * any number of times. Nothing is overwritten.
 *
 * Order of events on production: this migration runs BEFORE `osms:backfill-accounts`
 * creates accounts, so here the backfill finds none and does nothing. The command
 * sets the column itself for each account it creates.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('accounts', 'trial_used_at')) {
            Schema::table('accounts', function (Blueprint $table) {
                $table->timestamp('trial_used_at')->nullable()->after('status');
            });
        }

        // Every account that already has a subscription started with a trial
        // (Tenant::booted() always mints one), dated by that first row.
        DB::statement(
            'UPDATE accounts
                SET trial_used_at = (SELECT MIN(subscriptions.created_at) FROM subscriptions WHERE subscriptions.account_id = accounts.id)
              WHERE trial_used_at IS NULL
                AND EXISTS (SELECT 1 FROM subscriptions WHERE subscriptions.account_id = accounts.id)'
        );
    }

    /** Drops only the column this migration added, and nothing that existed before it. */
    public function down(): void
    {
        if (Schema::hasColumn('accounts', 'trial_used_at')) {
            Schema::table('accounts', fn (Blueprint $table) => $table->dropColumn('trial_used_at'));
        }
    }
};
