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
 * Session 3 — FT-ZeroPrice (5.3): a cost_price of 0 means "not recorded" (many
 * owners skip it), so it's excluded from COGS/profit/margin — no bogus 100%
 * margin — while the sale's revenue still counts fully.
 */
class Phase24ZeroCostAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['store_name' => 'Test Optical', 'tax_id' => 'GST', 'address' => 'Mumbai']);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'store_admin']);
    }

    private function item(float $cost, float $sell): Inventory
    {
        return Inventory::create([
            'tenant_id' => $this->tenant->id, 'sku' => 'SKU-' . uniqid(), 'barcode' => (string) random_int(1e11, 9e11),
            'item_type' => 'frame', 'brand' => 'Ray-Ban', 'model_name' => 'Aviator',
            'cost_price' => $cost, 'selling_price' => $sell, 'stock_qty' => 10, 'min_alert_qty' => 2,
        ]);
    }

    /** A delivered order with one line for $item at $price. */
    private function deliveredLine(Inventory $item, float $price): Order
    {
        $c = Customer::create(['tenant_id' => $this->tenant->id, 'name' => 'C', 'phone' => '+91 90000' . random_int(10000, 99999)]);
        $order = Order::create([
            'tenant_id' => $this->tenant->id, 'customer_id' => $c->id, 'status' => 'delivered',
            'subtotal' => $price, 'total_amount' => $price, 'advance_paid' => 0,
        ]);
        $order->items()->create(['inventory_id' => $item->id, 'quantity' => 1, 'unit_price' => $price, 'list_price' => $price]);

        return $order;
    }

    private function stats(): array
    {
        return $this->actingAs($this->user)->get(route('tenant.analytics.index'))->viewData('stats');
    }

    public function test_zero_cost_line_is_excluded_from_cogs_and_margin_but_counts_revenue(): void
    {
        $this->deliveredLine($this->item(100, 250), 250); // costed: cost 100, rev 250
        $this->deliveredLine($this->item(0, 400), 400);   // uncosted: cost 0, rev 400

        $s = $this->stats();
        $this->assertEqualsWithDelta(650, $s['revenue'], 0.01);   // both sales count
        $this->assertEqualsWithDelta(100, $s['cogs'], 0.01);      // only the costed line
        $this->assertEqualsWithDelta(150, $s['profit'], 0.01);    // 250 − 100 (costed only)
        $this->assertEqualsWithDelta(60, $s['margin'], 0.01);     // 150 / 250 = 60% (not diluted by the free-cost line)
        $this->assertSame(1, $s['uncostedLines']);
    }

    public function test_all_zero_cost_reports_null_margin_not_hundred_percent(): void
    {
        $this->deliveredLine($this->item(0, 250), 250);

        $s = $this->stats();
        $this->assertEqualsWithDelta(250, $s['revenue'], 0.01);
        $this->assertEqualsWithDelta(0, $s['cogs'], 0.01);
        $this->assertNull($s['margin']); // never a bogus 100%
        $this->assertSame(1, $s['uncostedLines']);
    }

    public function test_all_costed_margin_is_unchanged(): void
    {
        $this->deliveredLine($this->item(50, 200), 200);

        $s = $this->stats();
        $this->assertEqualsWithDelta(150, $s['profit'], 0.01); // 200 − 50
        $this->assertEqualsWithDelta(75, $s['margin'], 0.01);  // 150 / 200
        $this->assertSame(0, $s['uncostedLines']);
    }
}
