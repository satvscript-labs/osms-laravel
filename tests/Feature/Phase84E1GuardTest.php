<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\EntitlementWriteRefused;
use App\Services\SubscriptionLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * E1.5 / E1.6 — only the billing engine writes a subscription (billing rule 1).
 *
 * E1.5  a runtime guard on the model: create / update / delete outside
 *       SubscriptionLifecycle::writing() throws.
 * E1.6  a static scan of app/ for the writes model events cannot see
 *       (mass updates, DB::table, saveQuietly, withoutEvents, the write scope
 *       itself used outside the engine).
 */
class Phase84E1GuardTest extends TestCase
{
    use RefreshDatabase;

    /** Files allowed to write subscriptions / open the write scope. */
    private const ENGINE = [
        'app/Services/SubscriptionLifecycle.php',
        'app/Console/Commands/BackfillAccounts.php',   // engine bootstrap (binds existing clocks to payers)
    ];

    private function aSubscription(): Subscription
    {
        $tenant = Tenant::create(['store_name' => 'Guarded']);

        return Subscription::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
    }

    /**
     * Run $code from a PHP file OUTSIDE tests/ — standing in for app code.
     * A test cannot be app code, so it writes a throwaway file and calls it.
     */
    private function fromOutsideTests(string $body, Subscription $sub): mixed
    {
        $file = tempnam(sys_get_temp_dir(), 'e1guard') . '.php';
        file_put_contents($file, "<?php\nreturn function (\\App\\Models\\Subscription \$s) {\n{$body}\n};\n");

        try {
            $fn = require $file;

            return $fn($sub);
        } finally {
            @unlink($file);
        }
    }

    // ------------------------------------------------------------------
    // E1.5 — the runtime guard
    // ------------------------------------------------------------------

    public function test_a_write_from_outside_the_engine_is_refused_and_changes_nothing(): void
    {
        $sub = $this->aSubscription();

        foreach ([
            'update' => "\$s->status = 'active'; \$s->save();",
            'increment' => "\$s->increment('quantity');",
            'delete' => "\$s->delete();",
            'create' => "\\App\\Models\\Subscription::withoutGlobalScopes()->create(['tenant_id' => \$s->tenant_id, 'status' => 'active']);",
        ] as $what => $code) {
            try {
                $this->fromOutsideTests($code, $sub->fresh());
                $this->fail("A {$what} from outside the engine must be refused.");
            } catch (EntitlementWriteRefused $e) {
                $this->assertStringContainsString('SubscriptionLifecycle', $e->getMessage());
            }
        }

        $fresh = $sub->fresh();
        $this->assertSame('trialing', $fresh->status, 'nothing changed');
        $this->assertSame(1, Subscription::withoutGlobalScopes()->count(), 'nothing created, nothing deleted');
    }

    public function test_the_same_write_inside_the_engine_scope_is_allowed(): void
    {
        $sub = $this->aSubscription();

        $this->fromOutsideTests("\\App\\Services\\SubscriptionLifecycle::writing(fn () => \$s->forceFill(['quantity' => 3])->save());", $sub);

        $this->assertSame(3, (int) $sub->fresh()->quantity);
    }

    public function test_in_production_even_a_direct_write_is_refused(): void
    {
        $sub = $this->aSubscription();
        $this->app['env'] = 'production';   // the test-fixture allowance only exists in the test runner

        try {
            $sub->forceFill(['status' => 'active'])->save();
            $this->fail('Outside the test runner there is no fixture allowance.');
        } catch (EntitlementWriteRefused) {
            // expected
        } finally {
            $this->app['env'] = 'testing';
        }

        $this->assertSame('trialing', $sub->fresh()->status);
    }

    public function test_an_exception_inside_the_write_scope_never_leaves_the_door_open(): void
    {
        try {
            SubscriptionLifecycle::writing(function () {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }

        $this->assertFalse(SubscriptionLifecycle::isWriting());

        $sub = $this->aSubscription();
        $this->expectException(EntitlementWriteRefused::class);
        $this->fromOutsideTests("\$s->status = 'active'; \$s->save();", $sub);
    }

    // ------------------------------------------------------------------
    // E1.6 — the static scan of app/
    // ------------------------------------------------------------------

    /**
     * @return list<string> one line per violation: "file: reason"
     */
    private function scan(string $relative, string $code): array
    {
        // Judge code, not prose: comments that MENTION a bypass are not one.
        $code = implode('', array_map(
            fn ($t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t,
            token_get_all($code),
        ));

        $found = [];
        $isEngine = in_array($relative, self::ENGINE, true);

        if (preg_match('/DB::table\(\s*[\'"]subscriptions[\'"]/', $code)) {
            $found[] = "{$relative}: DB::table('subscriptions')";
        }

        if (preg_match('/DB::(update|statement|insert|delete|unprepared)\([^;]*\bsubscriptions\b/is', $code)) {
            $found[] = "{$relative}: raw SQL against subscriptions";
        }

        if (! $isEngine && str_contains($code, 'SubscriptionLifecycle::writing(')) {
            $found[] = "{$relative}: opens the engine's write scope";
        }

        // Statement by statement: a subscription query that writes in bulk
        // (no model events fire, so the runtime guard cannot see it).
        foreach (explode(';', $code) as $statement) {
            if (! preg_match('/Subscription::|->subscription\(\)|->subscriptions\(\)/', $statement)) {
                continue;
            }

            if (preg_match('/->(update|delete|forceDelete|increment|decrement|upsert|insert|insertOrIgnore|updateOrInsert)\(/', $statement)
                && ! preg_match('/->(first|firstOrFail|find|findOrFail|sole)\(\)\s*(\?->|->)/', $statement)) {
                $found[] = "{$relative}: bulk write on a subscription query";
            }

            if (preg_match('/saveQuietly\(|withoutEvents\(|updateQuietly\(|deleteQuietly\(/', $statement)) {
                $found[] = "{$relative}: event-less write on a subscription";
            }
        }

        return $found;
    }

    public function test_the_scanner_catches_every_bypass_it_claims_to(): void
    {
        // The scan must not pass vacuously: each known bypass is caught.
        $bad = [
            "Subscription::withoutGlobalScopes()->where('x', 1)->update(['status' => 'active']);",
            "\$tenant->subscription()->update(['status' => 'active']);",
            "DB::table('subscriptions')->where('id', 1)->update(['status' => 'x']);",
            "DB::statement('UPDATE subscriptions SET status = 1');",
            "Subscription::withoutGlobalScopes()->whereKey(1)->increment('quantity');",
            "SubscriptionLifecycle::writing(fn () => null);",
            "Subscription::withoutEvents(fn () => null);",
        ];

        foreach ($bad as $code) {
            $this->assertNotEmpty($this->scan('app/Http/Controllers/Example.php', "<?php {$code}"), "missed: {$code}");
        }

        // …and ordinary reads, or an instance save (which the runtime guard sees), are not flagged.
        $this->assertSame([], $this->scan('app/X.php', "<?php \$s = Subscription::withoutGlobalScopes()->where('a', 1)->count();"));
        $this->assertSame([], $this->scan('app/X.php', "<?php Account::query()->whereKey(1)->update(['trial_used_at' => now()]);"));
    }

    public function test_nothing_in_app_writes_a_subscription_behind_the_engines_back(): void
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

        $this->assertSame([], $violations, "Only SubscriptionLifecycle may write subscriptions:\n" . implode("\n", $violations));
    }
}
