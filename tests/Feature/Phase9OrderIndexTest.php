<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the redesigned Orders index: search, status/payment filters,
 * sorting, the kanban view toggle, and tenant isolation of the listing.
 */
class Phase9OrderIndexTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $tenant = Tenant::create(['store_name' => 'Test Optical', 'tax_id' => 'GST123', 'address' => 'Mumbai']);
        $this->user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'store_admin']);
        $this->actingAs($this->user);
    }

    private function makeOrder(string $customerName, string $phone, array $attrs = []): Order
    {
        $customer = Customer::create(['name' => $customerName, 'phone' => $phone]);

        return Order::create(array_merge([
            'customer_id' => $customer->id,
            'status' => 'pending',
            'total_amount' => 100,
            'advance_paid' => 0,
        ], $attrs));
    }

    public function test_search_filters_orders_by_customer_name_or_phone(): void
    {
        $this->makeOrder('Alice Wonder', '900000001');
        $this->makeOrder('Bob Builder', '900000002');

        $this->actingAs($this->user)->get(route('tenant.orders.index', ['q' => 'Alice']))
            ->assertOk()->assertSee('Alice Wonder')->assertDontSee('Bob Builder');

        $this->actingAs($this->user)->get(route('tenant.orders.index', ['q' => '900000002']))
            ->assertOk()->assertSee('Bob Builder')->assertDontSee('Alice Wonder');
    }

    public function test_status_filter_limits_rows(): void
    {
        $this->makeOrder('Pending Pat', '901', ['status' => 'pending']);
        $this->makeOrder('Done Dan', '902', ['status' => 'delivered']);

        $this->actingAs($this->user)->get(route('tenant.orders.index', ['status' => 'delivered']))
            ->assertOk()->assertSee('Done Dan')->assertDontSee('Pending Pat');
    }

    public function test_payment_filter_separates_outstanding_and_paid(): void
    {
        $this->makeOrder('Owes Money', '903', ['total_amount' => 500, 'advance_paid' => 100]); // balance 400
        $this->makeOrder('Paid Up', '904', ['total_amount' => 500, 'advance_paid' => 500]);     // balance 0

        $this->actingAs($this->user)->get(route('tenant.orders.index', ['payment' => 'outstanding']))
            ->assertOk()->assertSee('Owes Money')->assertDontSee('Paid Up');

        $this->actingAs($this->user)->get(route('tenant.orders.index', ['payment' => 'paid']))
            ->assertOk()->assertSee('Paid Up')->assertDontSee('Owes Money');
    }

    public function test_kanban_view_renders(): void
    {
        $this->makeOrder('Board Person', '905');

        $this->actingAs($this->user)->get(route('tenant.orders.index', ['view' => 'kanban']))
            ->assertOk()->assertSee('Board Person')->assertSee('kanban-column', false);
    }

    public function test_partial_request_returns_only_the_results_fragment(): void
    {
        $this->makeOrder('Fragment Fran', '908');

        $res = $this->actingAs($this->user)
            ->get(route('tenant.orders.index', ['q' => 'Fragment', 'partial' => 1]))
            ->assertOk()
            ->assertSee('Fragment Fran')
            ->assertSee('osms-orders-table', false);

        // The fragment must NOT carry the full page chrome (no layout/sidebar),
        // and pagination/sort links must not leak the internal partial flag.
        $res->assertDontSee('app-sidebar', false);
        $res->assertDontSee('partial=1', false);
    }

    public function test_index_only_shows_current_tenants_orders(): void
    {
        $this->makeOrder('Mine', '906');

        $other = Tenant::create(['store_name' => 'Other']);
        $otherUser = User::factory()->create(['tenant_id' => $other->id, 'role' => 'store_admin']);
        $this->actingAs($otherUser);
        $this->makeOrder('Theirs', '907');

        $this->actingAs($this->user)->get(route('tenant.orders.index'))
            ->assertOk()->assertSee('Mine')->assertDontSee('Theirs');
    }
}
