<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Session 3 — FT-OrderMoney M-c: analytics reconciliation. Revenue is net of
 * discount; the order-level discount is allocated pro-rata to line items so
 * brand revenue sums back to net revenue (D7); a "discounts given" KPI is
 * exposed; profit uses net revenue.
 */
class Phase19AnalyticsMoneyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $tenant = Tenant::create(['store_name' => 'Test Optical', 'tax_id' => 'GST123', 'address' => 'Mumbai']);
        $this->user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'store_admin']);
    }

    private function makeItem(float $price = 250, float $cost = 50): Inventory
    {
        return Inventory::create([
            'tenant_id' => $this->user->tenant_id,
            'sku' => 'SKU-' . uniqid(), 'barcode' => (string) random_int(100000000000, 999999999999),
            'item_type' => 'frame', 'brand' => 'Ray-Ban', 'model_name' => 'Aviator',
            'cost_price' => $cost, 'selling_price' => $price, 'stock_qty' => 100, 'min_alert_qty' => 2,
        ]);
    }

    /** Place a delivered order with a 10% discount: subtotal 1000 → total 900. */
    private function deliveredDiscountedOrder(Inventory $item): Order
    {
        $customer = Customer::create([
            'tenant_id' => $this->user->tenant_id,
            'name' => 'Rahul', 'phone' => '+91 90000' . random_int(10000, 99999),
        ]);

        $this->actingAs($this->user)->post(route('tenant.orders.store'), [
            'customer_id' => $customer->id,
            'discount_type' => 'percent', 'discount_value' => 10,
            'items' => [['inventory_id' => $item->id, 'quantity' => 4]], // subtotal 1000
        ])->assertRedirect();

        $order = Order::latest()->first();
        $order->update(['status' => 'delivered']); // updated_at = now → in range

        return $order;
    }

    public function test_revenue_is_net_of_discount(): void
    {
        $item = $this->makeItem(250, 50);
        $this->deliveredDiscountedOrder($item);

        $stats = $this->actingAs($this->user)->get(route('tenant.analytics.index'))->viewData('stats');

        $this->assertEqualsWithDelta(900, $stats['revenue'], 0.01);   // 1000 − 10%
        $this->assertEqualsWithDelta(100, $stats['discounts'], 0.01); // discount given
    }

    public function test_profit_uses_net_revenue(): void
    {
        $item = $this->makeItem(250, 50);
        $this->deliveredDiscountedOrder($item);

        $stats = $this->actingAs($this->user)->get(route('tenant.analytics.index'))->viewData('stats');

        // COGS = 4 × 50 = 200; profit = net 900 − 200 = 700.
        $this->assertEqualsWithDelta(200, $stats['cogs'], 0.01);
        $this->assertEqualsWithDelta(700, $stats['profit'], 0.01);
    }

    public function test_brand_revenue_is_allocated_net_of_discount(): void
    {
        $item = $this->makeItem(250, 50);
        $this->deliveredDiscountedOrder($item);

        $topBrands = $this->actingAs($this->user)->get(route('tenant.analytics.index'))->viewData('topBrands');
        $rayBan = $topBrands->firstWhere('brand', 'Ray-Ban');

        // Brand revenue reconciles to the net total (900), not the gross subtotal (1000).
        $this->assertEqualsWithDelta(900, $rayBan['revenue'], 0.01);
    }

    public function test_undiscounted_order_revenue_is_unchanged(): void
    {
        $item = $this->makeItem(250, 50);
        $customer = Customer::create([
            'tenant_id' => $this->user->tenant_id, 'name' => 'No Disc', 'phone' => '+91 9111122222',
        ]);
        $this->actingAs($this->user)->post(route('tenant.orders.store'), [
            'customer_id' => $customer->id,
            'items' => [['inventory_id' => $item->id, 'quantity' => 2]], // 500, no discount
        ])->assertRedirect();
        Order::latest()->first()->update(['status' => 'delivered']);

        $stats = $this->actingAs($this->user)->get(route('tenant.analytics.index'))->viewData('stats');
        $this->assertEqualsWithDelta(500, $stats['revenue'], 0.01);
        $this->assertEqualsWithDelta(0, $stats['discounts'], 0.01);
    }
}
