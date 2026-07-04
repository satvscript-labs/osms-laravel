<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regression — analytics revenue must be bucketed by the order's placement date
 * (created_at), NOT updated_at. updated_at drifts on any later save (payment,
 * edit, status change), which previously filed a sale under the wrong day.
 */
class Phase27AnalyticsDateTest extends TestCase
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

    private function deliveredOrder(float $total, string $createdAt, ?string $updatedAt = null): Order
    {
        $c = Customer::create(['tenant_id' => $this->tenant->id, 'name' => 'C', 'phone' => '+91 90000' . random_int(10000, 99999)]);
        $item = Inventory::create([
            'tenant_id' => $this->tenant->id, 'sku' => 'SKU-' . uniqid(), 'barcode' => (string) random_int(1e11, 9e11),
            'item_type' => 'frame', 'brand' => 'X', 'model_name' => 'Y',
            'cost_price' => 0, 'selling_price' => $total, 'stock_qty' => 10, 'min_alert_qty' => 1,
        ]);
        $order = Order::create([
            'tenant_id' => $this->tenant->id, 'customer_id' => $c->id, 'status' => 'delivered',
            'subtotal' => $total, 'total_amount' => $total, 'advance_paid' => 0,
        ]);
        $order->items()->create(['inventory_id' => $item->id, 'quantity' => 1, 'unit_price' => $total, 'list_price' => $total]);

        DB::table('orders')->where('id', $order->id)->update([
            'created_at' => $createdAt,
            'updated_at' => $updatedAt ?? $createdAt,
        ]);

        return $order;
    }

    private function revenueFor(string $from, string $to): float
    {
        return (float) $this->actingAs($this->user)
            ->get(route('tenant.analytics.index', ['from' => $from, 'to' => $to]))
            ->viewData('stats')['revenue'];
    }

    public function test_revenue_counts_by_placement_date_not_last_touch(): void
    {
        // Placed on the 10th, but "touched" (e.g. balance paid) on the 20th.
        $this->deliveredOrder(7000, '2026-06-10 10:00:00', '2026-06-20 15:00:00');

        // The sale belongs to the 10th's range…
        $this->assertEqualsWithDelta(7000, $this->revenueFor('2026-06-10', '2026-06-11'), 0.01);

        // …and must NOT leak into the 20th's range (the old updated_at bug).
        $this->assertEqualsWithDelta(0, $this->revenueFor('2026-06-20', '2026-06-21'), 0.01);
    }

    public function test_order_outside_range_is_excluded(): void
    {
        $this->deliveredOrder(5000, '2026-05-01 10:00:00');

        $this->assertEqualsWithDelta(0, $this->revenueFor('2026-06-01', '2026-06-30'), 0.01);
    }
}
