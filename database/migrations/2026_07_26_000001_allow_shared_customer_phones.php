<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SHARE-01 — a phone number identifies a HOUSEHOLD, not a person.
 *
 * `UNIQUE (tenant_id, phone)` encoded the opposite, and everything downstream
 * inherited that mistake:
 *
 *   • families sharing one handset could not both be recorded — the Sahaj
 *     migration had to invent 608 fake `+91 0…` numbers purely to satisfy this;
 *   • the order builder matched an inline walk-in on phone ALONE, silently
 *     filing one family member's order (and any attached prescription) under a
 *     relative's name;
 *   • a soft-deleted customer's number could never be reused, and staff were
 *     told it "already exists" by a record they cannot see.
 *
 * Uniqueness does not move to some other column set — `(tenant_id, phone, name)`
 * was considered and rejected, because name equality is a human judgement and a
 * hard constraint on a judgement is what caused this. Duplicate handling moves
 * into the application, where a person can decide. See
 * _artifacts/SHARED_PHONE_DESIGN.md.
 *
 * `phone` also becomes nullable: it was required, which is why the migration had
 * to fabricate numbers for people who genuinely had none, and why a walk-in who
 * won't give a number could not be recorded at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The index was created on `patients` and the table was later renamed.
        // MySQL does not rename indexes with their table, so it is still called
        // `patients_tenant_id_phone_unique` in production while a freshly-built
        // database (tests, a new tenant install) may name it differently. Look it
        // up rather than assuming either.
        if ($name = $this->uniquePhoneIndexName()) {
            Schema::table('customers', function (Blueprint $table) use ($name) {
                $table->dropUnique($name);
            });
        }

        Schema::table('customers', function (Blueprint $table) {
            // The unique index was also serving as the lookup index for every
            // phone search; replace it so search on 3,000+ rows stays fast.
            $table->index(['tenant_id', 'phone'], 'customers_tenant_id_phone_index');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->string('phone')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Re-tightening can only work if the data still allows it — by then a
        // store may legitimately have two people on one number, and rows with no
        // number at all. Fail loudly rather than silently destroying either.
        $shared = \DB::table('customers')
            ->selectRaw('tenant_id, phone, COUNT(*) as n')
            ->whereNotNull('phone')
            ->whereNull('deleted_at')
            ->groupBy('tenant_id', 'phone')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        $blank = \DB::table('customers')->whereNull('phone')->count();

        if ($shared > 0 || $blank > 0) {
            throw new RuntimeException(
                "Cannot restore UNIQUE (tenant_id, phone): {$shared} number(s) are shared by "
                . "more than one customer and {$blank} customer(s) have no number. Resolve those "
                . 'first — rolling back would have to delete real people.'
            );
        }

        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex('customers_tenant_id_phone_index');
            $table->string('phone')->nullable(false)->change();
            $table->unique(['tenant_id', 'phone']);
        });
    }

    /** The real name of the unique (tenant_id, phone) index, or null if it's gone. */
    private function uniquePhoneIndexName(): ?string
    {
        foreach (Schema::getIndexes('customers') as $index) {
            $columns = $index['columns'] ?? [];

            if (($index['unique'] ?? false)
                && count($columns) === 2
                && in_array('tenant_id', $columns, true)
                && in_array('phone', $columns, true)) {
                return $index['name'];
            }
        }

        return null;
    }
};
