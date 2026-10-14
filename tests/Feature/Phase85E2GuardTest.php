<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\BillingCharge;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Services\MoneyWrite;
use App\Services\MoneyWriteRefused;
use App\Services\PaymentRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * E2.1 — money rows (the ledger and charges) are written only through the engine
 * and never deleted (billing rules 2 and 6). The runtime guard on both models,
 * and a static scan of app/ for the writes model events cannot see.
 */
class Phase85E2GuardTest extends TestCase
{
    use RefreshDatabase;

    /** The only files in app/ that may open the money write scope. */
    private const MONEY_ENGINE = [
        'app/Services/PaymentRecorder.php',
        'app/Services/BillingEngine.php',
        'app/Services/Settlement.php',
    ];

    private function anAccount(): array
    {
        $account = Account::create(['name' => 'Guarded Money']);
        $tenant = Tenant::create(['store_name' => 'Guarded Money', 'account_id' => $account->id]);
        $sub = Subscription::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();

        return [$account, $tenant, $sub];
    }

    /** A real ledger row, written the legitimate way. */
    private function aPayment(Subscription $sub): SubscriptionInvoice
    {
        return app(PaymentRecorder::class)->record($sub, 499, 'cash');
    }

    /** A charge, written inside the scope (BillingEngine arrives in E2.2). */
    private function aCharge(Account $account, Subscription $sub): BillingCharge
    {
        return MoneyWrite::during(fn () => BillingCharge::create([
            'account_id' => $account->id, 'subscription_id' => $sub->id, 'number' => 'CHG-TEST-0001',
            'kind' => 'renewal', 'status' => 'pending', 'amount' => 499, 'raised_via' => 'operator',
        ]));
    }

    /** Run $body from a PHP file OUTSIDE tests/ — standing in for app code (as E1). */
    private function fromOutsideTests(string $body, array $vars): mixed
    {
        $file = tempnam(sys_get_temp_dir(), 'e2guard') . '.php';
        file_put_contents($file, "<?php\nreturn function (array \$v) {\nextract(\$v);\n{$body}\n};\n");

        try {
            $fn = require $file;

            return $fn($vars);
        } finally {
            @unlink($file);
        }
    }

    public function test_the_schema_is_additive(): void
    {
        $this->assertTrue(Schema::hasTable('billing_charges'));

        foreach (['charge_id', 'granted_start', 'granted_end'] as $column) {
            $this->assertTrue(Schema::hasColumn('subscription_invoices', $column), $column);
        }
    }

    public function test_a_ledger_write_from_outside_the_engine_is_refused_and_changes_nothing(): void
    {
        [, , $sub] = $this->anAccount();
        $row = $this->aPayment($sub);

        foreach ([
            'create' => "\\App\\Models\\SubscriptionInvoice::withoutGlobalScopes()->create(['tenant_id' => \$sub->tenant_id, 'account_id' => \$sub->account_id, 'amount' => 1, 'status' => 'paid']);",
            'update' => "\$row->amount = 1; \$row->save();",
            'increment' => "\$row->increment('amount');",
            'delete' => "\$row->delete();",
        ] as $what => $code) {
            try {
                $this->fromOutsideTests($code, ['sub' => $sub, 'row' => $row->fresh()]);
                $this->fail("A ledger {$what} from outside the engine must be refused.");
            } catch (MoneyWriteRefused $e) {
                $this->assertStringContainsString('SubscriptionInvoice', $e->getMessage());
            }
        }

        $this->assertSame(1, SubscriptionInvoice::withoutGlobalScopes()->count());
        $this->assertEquals(499.0, (float) $row->fresh()->amount);
    }

    public function test_a_charge_write_from_outside_the_engine_is_refused_and_changes_nothing(): void
    {
        [$account, , $sub] = $this->anAccount();
        $charge = $this->aCharge($account, $sub);

        foreach ([
            'create' => "\\App\\Models\\BillingCharge::create(['account_id' => \$charge->account_id, 'number' => 'CHG-X', 'kind' => 'renewal', 'amount' => 1, 'raised_via' => 'operator']);",
            'update' => "\$charge->status = 'paid'; \$charge->save();",
            'delete' => "\$charge->delete();",
        ] as $what => $code) {
            try {
                $this->fromOutsideTests($code, ['charge' => $charge->fresh()]);
                $this->fail("A charge {$what} from outside the engine must be refused.");
            } catch (MoneyWriteRefused $e) {
                $this->assertStringContainsString('BillingCharge', $e->getMessage());
            }
        }

        $this->assertSame(1, BillingCharge::count());
        $this->assertSame('pending', $charge->fresh()->status);
    }

    public function test_money_rows_are_never_deleted_even_inside_the_engine_scope(): void
    {
        [$account, , $sub] = $this->anAccount();
        $row = $this->aPayment($sub);
        $charge = $this->aCharge($account, $sub);

        foreach (['row' => $row, 'charge' => $charge] as $name => $model) {
            try {
                $this->fromOutsideTests("\\App\\Services\\MoneyWrite::during(fn () => \${$name}->delete());", [$name => $model]);
                $this->fail("Deleting a {$name} must be refused even inside the scope.");
            } catch (MoneyWriteRefused $e) {
                $this->assertStringContainsString('never deleted', $e->getMessage());
            }
        }

        $this->assertSame(1, SubscriptionInvoice::withoutGlobalScopes()->count());
        $this->assertSame(1, BillingCharge::count());
    }

    public function test_in_production_even_a_direct_write_is_refused(): void
    {
        [, , $sub] = $this->anAccount();
        $row = $this->aPayment($sub);
        $this->app['env'] = 'production';   // the fixture allowance exists only in the test runner

        try {
            $row->forceFill(['amount' => 1])->save();
            $this->fail('Outside the test runner there is no fixture allowance.');
        } catch (MoneyWriteRefused) {
            // expected
        } finally {
            $this->app['env'] = 'testing';
        }

        $this->assertEquals(499.0, (float) $row->fresh()->amount);
    }

    public function test_an_exception_inside_the_scope_never_leaves_the_door_open(): void
    {
        try {
            MoneyWrite::during(fn () => throw new \RuntimeException('boom'));
        } catch (\RuntimeException) {
        }

        $this->assertFalse(MoneyWrite::isOpen());
    }

    public function test_the_recorder_still_writes_the_ledger(): void
    {
        [, , $sub] = $this->anAccount();
        $row = $this->aPayment($sub);
        app(PaymentRecorder::class)->reverse($row, 'mistyped');

        $this->assertNotNull($row->fresh()->reversed_at, 'the recorder opens the scope for reversals too');
    }

    // ------------------------------------------------------------------
    // The static scan — what model events cannot see
    // ------------------------------------------------------------------

    /** @return list<string> */
    private function scan(string $relative, string $code): array
    {
        $code = implode('', array_map(
            fn ($t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t,
            token_get_all($code),
        ));

        $found = [];

        if (preg_match('/DB::table\(\s*[\'"](subscription_invoices|billing_charges|unapplied_payments)[\'"]/', $code)) {
            $found[] = "{$relative}: DB::table on a money table";
        }

        if (preg_match('/DB::(update|statement|insert|delete|unprepared)\([^;]*\b(subscription_invoices|billing_charges|unapplied_payments)\b/is', $code)) {
            $found[] = "{$relative}: raw SQL against a money table";
        }

        if (! in_array($relative, self::MONEY_ENGINE, true) && str_contains($code, 'MoneyWrite::during(')) {
            $found[] = "{$relative}: opens the money write scope";
        }

        foreach (explode(';', $code) as $statement) {
            if (! preg_match('/SubscriptionInvoice::|BillingCharge::|UnappliedPayment::|->invoices\(\)|->charges\(\)|->subscriptionInvoices\(\)|->payments\(\)/', $statement)) {
                continue;
            }

            if (preg_match('/->(update|delete|forceDelete|increment|decrement|upsert|insert|insertOrIgnore|updateOrInsert)\(/', $statement)
                && ! preg_match('/->(first|firstOrFail|find|findOrFail|sole)\(\)\s*(\?->|->)/', $statement)
                // BackfillAccounts binds existing rows to their payer (account_id only) — engine bootstrap, as in E1.
                && $relative !== 'app/Console/Commands/BackfillAccounts.php') {
                $found[] = "{$relative}: bulk write on a money query";
            }

            if (preg_match('/saveQuietly\(|withoutEvents\(|updateQuietly\(|deleteQuietly\(/', $statement)) {
                $found[] = "{$relative}: event-less write on a money row";
            }
        }

        return $found;
    }

    public function test_the_scanner_catches_every_bypass_it_claims_to(): void
    {
        foreach ([
            "SubscriptionInvoice::withoutGlobalScopes()->where('x', 1)->update(['amount' => 0]);",
            "\$account->invoices()->delete();",
            "BillingCharge::query()->where('status', 'pending')->update(['status' => 'paid']);",
            "\$account->charges()->update(['amount' => 1]);",
            "DB::table('billing_charges')->insert([]);",
            "DB::statement('DELETE FROM subscription_invoices');",
            "MoneyWrite::during(fn () => null);",
            "SubscriptionInvoice::withoutEvents(fn () => null);",
        ] as $code) {
            $this->assertNotEmpty($this->scan('app/Http/Controllers/Example.php', "<?php {$code}"), "missed: {$code}");
        }

        $this->assertSame([], $this->scan('app/X.php', "<?php \$n = BillingCharge::query()->pending()->sum('amount');"));
    }

    /**
     * Rule 6 — who may put a row on the ledger. Settlement (every payment, against
     * a charge); the lifecycle (₹0 comp grants only); the legacy webhook (gateway
     * money, until E3 routes it through Settlement). Nobody else.
     */
    public function test_only_the_engine_calls_the_ledger_recorder(): void
    {
        $allowed = [
            'app/Services/Settlement.php' => null,                              // anything
            'app/Services/SubscriptionLifecycle.php' => ['recordComp'],         // ₹0 grants only
        ];
        $root = base_path('app');
        $found = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = 'app/' . str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            // Judge code, not prose: a comment naming the recorder is not a call.
            $code = implode('', array_map(
                fn ($t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t,
                token_get_all(file_get_contents($file->getPathname())),
            ));

            if ($relative === 'app/Services/PaymentRecorder.php' || ! str_contains($code, 'PaymentRecorder')) {
                continue;
            }

            if (! array_key_exists($relative, $allowed)) {
                $found[] = "{$relative}: uses PaymentRecorder";

                continue;
            }

            if ($allowed[$relative] === null) {
                continue;
            }

            preg_match_all('/(?:payments|PaymentRecorder::class\))\s*->\s*(\w+)\(/', $code, $m);

            foreach (array_diff(array_unique($m[1]), $allowed[$relative]) as $method) {
                $found[] = "{$relative}: calls PaymentRecorder::{$method}()";
            }
        }

        $this->assertSame([], $found, "Only the engine writes the ledger:\n" . implode("\n", $found));
    }

    /**
     * A-10 — the subscription row is locked through its PRIMARY KEY, everywhere.
     * Locking it through the account_id index in one path and the key in another
     * deadlocked two payments on MariaDB (E2 race test). SQLite has no row locks,
     * so this is held statically.
     */
    public function test_the_subscription_row_is_only_ever_locked_by_its_key(): void
    {
        $root = base_path('app');
        $found = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = 'app/' . str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            $code = implode('', array_map(
                fn ($t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t,
                token_get_all(file_get_contents($file->getPathname())),
            ));

            foreach (explode(';', $code) as $statement) {
                if (preg_match('/Subscription::|->subscription\(\)/', $statement)
                    && str_contains($statement, 'lockForUpdate(')
                    && ! str_contains($statement, 'whereKey(')) {
                    $found[] = "{$relative}: locks a subscription without whereKey()";
                }
            }
        }

        $this->assertSame([], $found, "Lock the subscription by its key:\n" . implode("\n", $found));
    }

    public function test_nothing_in_app_writes_money_behind_the_engines_back(): void
    {
        $root = base_path('app');
        $violations = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = 'app/' . str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            $violations = [...$violations, ...$this->scan($relative, file_get_contents($file->getPathname()))];
        }

        $this->assertSame([], $violations, "Money is written only by the engine:\n" . implode("\n", $violations));
    }
}
