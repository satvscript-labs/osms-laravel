<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\StockMovement;
use App\Models\TaxInvoice;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsAppMessage;
use Database\Seeders\ProdDemoSeeder;
use Database\Seeders\SuperadminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Seeders — the default seed must be production-safe (no demo data leaks into a
 * real database), the superadmin comes from the environment, and the explicit
 * ProdDemoSeeder populates the whole schema with variety.
 */
class Phase59SeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_default_seeder_creates_no_demo_store(): void
    {
        // DatabaseSeeder → SuperadminSeeder only. With no SUPERADMIN_* env set,
        // it must be a complete no-op — nothing injected into a real DB.
        $this->seed(\Database\Seeders\DatabaseSeeder::class);

        $this->assertSame(0, Tenant::count(), 'the default seed must not create any store');
        $this->assertSame(0, User::count(), 'no superadmin without SUPERADMIN_* configured');
    }

    public function test_superadmin_seeder_is_env_driven_and_idempotent(): void
    {
        config([]); // ensure clean

        // Laravel's env() reads $_ENV/$_SERVER BEFORE falling back to getenv(), and
        // some CI PHP builds restrict putenv() outright — a bare putenv() here
        // passed locally but silently no-opped on the GitHub Actions runner
        // (env() saw nothing, so the seeder skipped and 0 users were created).
        // Setting $_ENV/$_SERVER directly is what Laravel's own repository checks
        // first, so it's picked up regardless of the putenv() restriction.
        $this->setTestEnv([
            'SUPERADMIN_EMAIL' => 'boss@example.test',
            'SUPERADMIN_PASSWORD' => 's3cret-pass',
            'SUPERADMIN_NAME' => 'The Boss',
        ]);

        try {
            $this->seed(SuperadminSeeder::class);
            $this->seed(SuperadminSeeder::class); // second run must not duplicate

            $admins = User::where('email', 'boss@example.test')->get();
            $this->assertCount(1, $admins);
            $this->assertSame('superadmin', $admins->first()->role);
            $this->assertNull($admins->first()->tenant_id);
        } finally {
            $this->setTestEnv([
                'SUPERADMIN_EMAIL' => null,
                'SUPERADMIN_PASSWORD' => null,
                'SUPERADMIN_NAME' => null,
            ]);
        }
    }

    /** Set/unset env vars in a way env() reliably sees across every PHP/CI setup. */
    private function setTestEnv(array $vars): void
    {
        foreach ($vars as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key], $_SERVER[$key]);
                putenv($key);

                continue;
            }
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            putenv("{$key}={$value}");
        }
    }

    public function test_prod_demo_seeder_populates_the_whole_schema_with_variety(): void
    {
        $this->seed(ProdDemoSeeder::class);

        $tenant = Tenant::where('store_name', 'Satv')->first();
        $this->assertNotNull($tenant);
        $this->assertNotNull($tenant->subscription);
        $this->assertSame('active', $tenant->subscription->status);
        $this->assertFalse((bool) $tenant->subscription->manual, 'demo sub must count as paid MRR');

        // Owner with the requested identity.
        $this->assertDatabaseHas('users', [
            'email' => 'developer@satvscript.com', 'name' => 'Satva Dev', 'role' => 'store_admin',
        ]);

        // Bypass the tenant scope (no auth context in a test) to count rows.
        $orders = Order::withoutGlobalScopes()->where('tenant_id', $tenant->id)->get();

        // Every order status is represented.
        foreach (['pending', 'ready_for_pickup', 'delivered', 'cancelled'] as $status) {
            $this->assertTrue($orders->contains('status', $status), "missing a {$status} order");
        }

        // Every payment method is represented.
        $methods = Payment::withoutGlobalScopes()->where('tenant_id', $tenant->id)->pluck('method')->unique();
        foreach (['cash', 'card', 'upi', 'other'] as $m) {
            $this->assertTrue($methods->contains($m), "missing a {$m} payment");
        }

        // Full spread of the tables that most often get forgotten.
        $this->assertGreaterThanOrEqual(2, TaxInvoice::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereNotNull('snapshot')->count());
        $this->assertGreaterThanOrEqual(4, WhatsAppMessage::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
        $this->assertGreaterThanOrEqual(3, StockMovement::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());

        // A minor and an un-consented customer both exist (privacy edge cases).
        $customers = Customer::withoutGlobalScopes()->where('tenant_id', $tenant->id)->get();
        $this->assertTrue($customers->contains(fn ($c) => $c->isMinor()), 'demo should include a minor');
        $this->assertTrue($customers->contains(fn ($c) => $c->data_consent_at === null), 'demo should include an un-consented customer');
    }

    public function test_prod_demo_seeder_does_not_duplicate_on_re_run(): void
    {
        $this->seed(ProdDemoSeeder::class);
        $this->seed(ProdDemoSeeder::class); // guard: skips when Satv already exists

        $this->assertSame(1, Tenant::where('store_name', 'Satv')->count());
    }
}
