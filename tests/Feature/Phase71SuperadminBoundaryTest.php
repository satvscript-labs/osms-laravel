<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * P1 / BUG-P08 — the SWEEPING authorization boundary (playbook §7).
 *
 * The old test named four routes by hand; the panel had eight, and every route
 * added later was uncovered BY DEFAULT. This one enumerates the router itself:
 * every route whose name starts `superadmin.` is asserted refused for guests,
 * staff and store admins — so P3's ~16 new actions are covered the day they
 * are registered, automatically, with no test change.
 *
 * It also proves refusal is INERT for mutations: no audit row is written and
 * no subscription field changes when a non-operator hits a mutating endpoint.
 */
class Phase71SuperadminBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private \App\Models\SubscriptionInvoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::factory()->create(['tenant_id' => null, 'role' => 'store_admin']);
        $this->tenant = app(\App\Services\StoreProvisioner::class)
            ->provision($owner, ['store_name' => 'Boundary Optical']);

        // A real ledger row, so the reversal route resolves and its gate is
        // genuinely exercised rather than 404-ing during model binding.
        $this->invoice = app(\App\Services\PaymentRecorder::class)
            ->record($this->tenant->account->subscription, 100, 'cash');
    }

    /**
     * Every registered superadmin route, with {params} resolved to REAL ids.
     *
     * Resolving to real records matters: a bogus id would 404 during route-model
     * binding (which runs in the `web` group, before route middleware) and the
     * role gate would never be reached — so the sweep would be asserting nothing.
     * Every parameter must therefore map to a record that genuinely exists.
     */
    private function superadminRoutes(): array
    {
        $routes = [];
        $ids = [
            'tenant' => $this->tenant->id,
            'account' => $this->tenant->account_id,
            'store' => $this->tenant->id,
            'invoice' => $this->invoice->id,
        ];

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();
            if (! $name || ! str_starts_with($name, 'superadmin.')) {
                continue;
            }

            $uri = preg_replace_callback('/\{(\w+)\??\}/', function ($m) use ($ids, $name) {
                $this->assertArrayHasKey(
                    $m[1],
                    $ids,
                    "[{$name}] has an unmapped route parameter {{$m[1]}} — add a real id for it, "
                    . 'or the sweep will 404 before reaching the authorization gate.',
                );

                return $ids[$m[1]];
            }, $route->uri());

            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }
                $routes[] = [$method, '/' . ltrim($uri, '/'), $name];
            }
        }

        $this->assertNotEmpty($routes, 'No superadmin routes found — the enumeration is broken.');

        return $routes;
    }

    public function test_every_superadmin_route_refuses_guests(): void
    {
        foreach ($this->superadminRoutes() as [$method, $uri, $name]) {
            $response = $this->call($method, $uri);

            $this->assertTrue(
                // Unauthenticated → the auth middleware redirects to login (302).
                $response->isRedirect(route('login')) || $response->status() === 403,
                "[{$name}] {$method} {$uri} let a GUEST through (got {$response->status()}).",
            );
        }
    }

    public function test_every_superadmin_route_refuses_every_non_operator_role(): void
    {
        foreach (['store_admin', 'staff'] as $role) {
            $user = User::factory()->create([
                'tenant_id' => $this->tenant->id,
                'role' => $role,
            ]);

            foreach ($this->superadminRoutes() as [$method, $uri, $name]) {
                $response = $this->actingAs($user)
                    // Even WITH a fresh password confirmation — the role gate alone
                    // must refuse; password.confirm is defence in depth, not the wall.
                    ->withSession(['auth.password_confirmed_at' => time()])
                    ->call($method, $uri);

                $this->assertSame(
                    403,
                    $response->status(),
                    "[{$name}] {$method} {$uri} let a {$role} through (got {$response->status()}).",
                );
            }

            auth()->logout();
        }
    }

    public function test_refused_mutations_are_inert(): void
    {
        $staff = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'staff']);
        $before = $this->tenant->subscription->only(['status', 'current_period_end', 'override_kind']);
        $ledgerBefore = \App\Models\SubscriptionInvoice::withoutGlobalScopes()->count();
        $reversedBefore = $this->invoice->fresh()->reversed_at;

        foreach ($this->superadminRoutes() as [$method, $uri, $name]) {
            if ($method === 'GET') {
                continue;
            }

            $this->actingAs($staff)
                ->withSession(['auth.password_confirmed_at' => time()])
                ->call($method, $uri, [
                    'months' => 12, 'days' => 30, 'interval' => 'yearly',
                    'status' => 'active', 'tier' => 'basic',
                    'action' => 'comp', 'reason' => 'should never apply',
                    'price' => 1, 'amount' => 1, 'method' => 'cash',
                    'store_status' => 'suspended', 'is_billable' => 0,
                ]);
        }

        $after = $this->tenant->subscription()->first()->only(['status', 'current_period_end', 'override_kind']);

        $this->assertEquals($before, $after, 'A refused mutation still changed commercial state.');
        $this->assertSame(0, AdminAuditLog::count(), 'A refused mutation wrote an audit row.');
        // The ledger is untouched — no row added, and the existing one not reversed.
        $this->assertSame($ledgerBefore, \App\Models\SubscriptionInvoice::withoutGlobalScopes()->count());
        $this->assertSame($reversedBefore, $this->invoice->fresh()->reversed_at);
        // And no per-store lever moved either.
        $this->assertSame('active', $this->tenant->fresh()->store_status);
        $this->assertTrue((bool) $this->tenant->fresh()->is_billable);
    }
}
