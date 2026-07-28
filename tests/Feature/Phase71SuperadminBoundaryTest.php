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

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['store_name' => 'Boundary Optical']);
    }

    /** Every registered superadmin route, with {params} resolved to real ids. */
    private function superadminRoutes(): array
    {
        $routes = [];

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();
            if (! $name || ! str_starts_with($name, 'superadmin.')) {
                continue;
            }

            // Substitute every path parameter with the test tenant's id. Today the
            // only parameter is {tenant}; anything added later still resolves to a
            // syntactically valid uuid, which is enough — the gate must refuse
            // BEFORE binding ever looks the value up.
            $uri = preg_replace('/\{[^}]+\}/', $this->tenant->id, $route->uri());

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

        foreach ($this->superadminRoutes() as [$method, $uri, $name]) {
            if ($method === 'GET') {
                continue;
            }

            $this->actingAs($staff)
                ->withSession(['auth.password_confirmed_at' => time()])
                ->call($method, $uri, ['months' => 12, 'days' => 30, 'interval' => 'yearly', 'status' => 'active', 'tier' => 'basic']);
        }

        $after = $this->tenant->subscription()->first()->only(['status', 'current_period_end', 'override_kind']);

        $this->assertEquals($before, $after, 'A refused mutation still changed commercial state.');
        $this->assertSame(0, AdminAuditLog::count(), 'A refused mutation wrote an audit row.');
        $this->assertDatabaseCount('subscription_invoices', 0);
    }
}
