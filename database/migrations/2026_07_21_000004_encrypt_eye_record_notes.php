<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * PRIV-03 — eye-record `notes` can carry free-text clinical/health detail. Encrypt
 * it at rest (the model gains an `encrypted` cast alongside this migration). This
 * one-time pass encrypts any existing plaintext notes so the new cast can read them.
 *
 * In production there is little/no real Rx data yet; the loop is a no-op on an empty
 * table (tests) and small elsewhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('eye_records')->whereNotNull('notes')->get(['id', 'notes']) as $row) {
            // Skip anything that already decrypts (defensive against a re-run).
            try {
                Crypt::decryptString($row->notes);
                continue; // already encrypted
            } catch (\Throwable) {
                // plaintext — encrypt it below
            }

            DB::table('eye_records')->where('id', $row->id)
                ->update(['notes' => Crypt::encryptString($row->notes)]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('eye_records')->whereNotNull('notes')->get(['id', 'notes']) as $row) {
            try {
                $plain = Crypt::decryptString($row->notes);
                DB::table('eye_records')->where('id', $row->id)->update(['notes' => $plain]);
            } catch (\Throwable) {
                // already plaintext — leave as-is
            }
        }
    }
};
