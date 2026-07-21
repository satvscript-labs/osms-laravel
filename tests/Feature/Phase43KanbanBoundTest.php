<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PERF-01 — the kanban board is a working view, so it must not hydrate a store's
 * entire delivered history. It loads all open work (pending / ready_for_pickup)
 * plus only recently-delivered orders; older delivered orders live in the table.
 */
class Phase43KanbanBoundTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['store_name' => 'Board Optical', 'tax_id' => 'G', 'address' => 'Goa']);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'store_admin']);
    }

    private function order(string $status, ?int $updatedDaysAgo = null): Order
    {
        $customer = Customer::create([
            'tenant_id' => $this->tenant->id, 'name' => 'C' . uniqid(), 'phone' => '+91 90000' . random_int(10000, 99999),
        ]);
        $order = Order::create([
            'tenant_id' => $this->tenant->id, 'customer_id' => $customer->id,
            'status' => $status, 'fulfillment_type' => 'special',
            'subtotal' => 500, 'total_amount' => 500, 'advance_paid' => 0,
        ]);

        if ($updatedDaysAgo !== null) {
            // Backdate updated_at without tripping the model's saving hook.
            DB::table('orders')->where('id', $order->id)->update(['updated_at' => now()->subDays($updatedDaysAgo)]);
        }

        return $order;
    }

    public function test_board_loads_open_and_recent_delivered_but_not_old_delivered(): void
    {
        $pending = $this->order('pending');
        $ready = $this->order('ready_for_pickup');
        $recentDelivered = $this->order('delivered', updatedDaysAgo: 2);
        $oldDelivered = $this->order('delivered', updatedDaysAgo: 30);

        $response = $this->actingAs($this->user)
            ->get(route('tenant.orders.index', ['view' => 'kanban']))
            ->assertOk();

        $grouped = $response->viewData('orders');
        $loadedIds = collect($grouped)->flatten(1)->pluck('id')->all();

        $this->assertContains($pending->id, $loadedIds);
        $this->assertContains($ready->id, $loadedIds);
        $this->assertContains($recentDelivered->id, $loadedIds, 'A recently delivered order should stay on the board.');
        $this->assertNotContains($oldDelivered->id, $loadedIds, 'An old delivered order must not load on the board.');
    }

    public function test_cancelled_orders_never_appear_on_the_board(): void
    {
        $cancelled = $this->order('pending');
        $cancelled->update(['status' => 'cancelled']);

        $response = $this->actingAs($this->user)
            ->get(route('tenant.orders.index', ['view' => 'kanban']))
            ->assertOk();

        $loadedIds = collect($response->viewData('orders'))->flatten(1)->pluck('id')->all();
        $this->assertNotContains($cancelled->id, $loadedIds);
    }

    public function test_table_view_still_shows_all_orders_including_old_delivered(): void
    {
        $oldDelivered = $this->order('delivered', updatedDaysAgo: 60);

        // Table view paginates but is not date-bounded — the full history stays reachable.
        $response = $this->actingAs($this->user)
            ->get(route('tenant.orders.index'))
            ->assertOk();

        $paginator = $response->viewData('orders');
        $this->assertTrue(
            collect($paginator->items())->contains(fn ($o) => $o->id === $oldDelivered->id),
            'The table view must still list old delivered orders.'
        );
    }
}
