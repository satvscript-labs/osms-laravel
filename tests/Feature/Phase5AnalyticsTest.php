<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\Order;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase5AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $tenant = Tenant::create(['store_name' => 'Test Optical']);
        $this->admin = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'store_admin']);
    }

    private function deliveredOrder(float $sell, float $cost, int $qty): Order
    {
        $customer = Customer::create(['name' => 'P', 'phone' => (string) random_int(1, 1e9)]);
        $item = Inventory::create([
            'sku' => 'S-' . uniqid(), 'barcode' => (string) random_int(1e11, 9e11), 'item_type' => 'frame',
            'brand' => 'Ray-Ban', 'cost_price' => $cost, 'selling_price' => $sell, 'stock_qty' => 100, 'min_alert_qty' => 1,
        ]);
        $order = Order::create([
            'customer_id' => $customer->id, 'status' => 'delivered',
            'total_amount' => $sell * $qty, 'advance_paid' => 0,
        ]);
        $order->items()->create(['inventory_id' => $item->id, 'quantity' => $qty, 'unit_price' => $sell]);
        return $order;
    }

    public function test_analytics_requires_store_admin(): void
    {
        $staffTenant = Tenant::create(['store_name' => 'Staff Store']);
        $staff = User::factory()->create(['tenant_id' => $staffTenant->id, 'role' => 'staff']);
        $this->actingAs($staff)->get(route('tenant.analytics.index'))->assertForbidden();
    }

    public function test_revenue_cogs_profit_computed(): void
    {
        $this->actingAs($this->admin);
        $this->deliveredOrder(sell: 250, cost: 100, qty: 2); // revenue 500, cogs 200, profit 300

        $this->actingAs($this->admin)->get(route('tenant.analytics.index'))
            ->assertOk()
            ->assertSee('₹ 500')   // revenue
            ->assertSee('₹ 200')   // cogs
            ->assertSee('₹ 300');  // profit
    }

    public function test_pending_dues_listed(): void
    {
        $this->actingAs($this->admin);
        $customer = Customer::create(['name' => 'Debtor', 'phone' => '555']);
        Order::create(['customer_id' => $customer->id, 'status' => 'pending', 'total_amount' => 1000, 'advance_paid' => 400]);

        $this->actingAs($this->admin)->get(route('tenant.analytics.index'))
            ->assertOk()->assertSee('Debtor')->assertSee('600.00'); // balance due
    }
}
