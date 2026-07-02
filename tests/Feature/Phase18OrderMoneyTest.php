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
 * Session 3 — FT-OrderMoney M-a: order-level discount (percent / amount, clamped),
 * per-line custom selling price with a list-price snapshot, and a chosen advance
 * payment method. Money is always resolved server-side; totals stay backward
 * compatible when no discount / custom price is supplied.
 */
class Phase18OrderMoneyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $tenant = Tenant::create(['store_name' => 'Test Optical', 'tax_id' => 'GST123', 'address' => 'Mumbai']);
        $this->user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'store_admin']);
    }

    private function makeItem(float $price = 250, int $stock = 10): Inventory
    {
        return Inventory::create([
            'tenant_id' => $this->user->tenant_id,
            'sku' => 'SKU-' . uniqid(), 'barcode' => (string) random_int(100000000000, 999999999999),
            'item_type' => 'frame', 'brand' => 'Ray-Ban', 'model_name' => 'Aviator',
            'cost_price' => 50, 'selling_price' => $price, 'stock_qty' => $stock, 'min_alert_qty' => 2,
        ]);
    }

    private function customer(): Customer
    {
        return Customer::create([
            'tenant_id' => $this->user->tenant_id,
            'name' => 'Rahul', 'phone' => '+91 90000' . random_int(10000, 99999),
        ]);
    }

    private function place(array $payload): Order
    {
        $this->actingAs($this->user)->post(route('tenant.orders.store'), $payload)->assertRedirect();

        return Order::latest()->first();
    }

    // ---- Backward compatibility ----

    public function test_order_without_discount_keeps_subtotal_equal_to_total(): void
    {
        $item = $this->makeItem(250, 10);
        $order = $this->place([
            'customer_id' => $this->customer()->id,
            'items' => [['inventory_id' => $item->id, 'quantity' => 2]],
        ]);

        $this->assertEquals(500, $order->subtotal);
        $this->assertEquals(0, $order->discount_amount);
        $this->assertSame('none', $order->discount_type);
        $this->assertEquals(500, $order->total_amount);
        // list_price snapshot captured for the line.
        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id, 'inventory_id' => $item->id, 'unit_price' => 250, 'list_price' => 250,
        ]);
    }

    // ---- Percentage discount ----

    public function test_percent_discount_reduces_total(): void
    {
        $item = $this->makeItem(250, 10);
        $order = $this->place([
            'customer_id' => $this->customer()->id,
            'discount_type' => 'percent', 'discount_value' => 10,
            'items' => [['inventory_id' => $item->id, 'quantity' => 2]], // subtotal 500
        ]);

        $this->assertEquals(500, $order->subtotal);
        $this->assertEquals(50, $order->discount_amount);  // 10% of 500
        $this->assertEquals(450, $order->total_amount);
    }

    public function test_percent_discount_is_capped_at_100(): void
    {
        $item = $this->makeItem(250, 10);
        $order = $this->place([
            'customer_id' => $this->customer()->id,
            'discount_type' => 'percent', 'discount_value' => 250, // absurd → clamps to 100%
            'items' => [['inventory_id' => $item->id, 'quantity' => 2]],
        ]);

        $this->assertEquals(100, $order->discount_value);
        $this->assertEquals(500, $order->discount_amount);
        $this->assertEquals(0, $order->total_amount);
    }

    // ---- Flat amount discount ----

    public function test_amount_discount_reduces_total(): void
    {
        $item = $this->makeItem(250, 10);
        $order = $this->place([
            'customer_id' => $this->customer()->id,
            'discount_type' => 'amount', 'discount_value' => 150,
            'items' => [['inventory_id' => $item->id, 'quantity' => 2]], // subtotal 500
        ]);

        $this->assertEquals(150, $order->discount_amount);
        $this->assertEquals(350, $order->total_amount);
    }

    public function test_amount_discount_cannot_exceed_subtotal(): void
    {
        $item = $this->makeItem(250, 10);
        $order = $this->place([
            'customer_id' => $this->customer()->id,
            'discount_type' => 'amount', 'discount_value' => 9999, // clamps to subtotal
            'items' => [['inventory_id' => $item->id, 'quantity' => 2]],
        ]);

        $this->assertEquals(500, $order->discount_amount);
        $this->assertEquals(0, $order->total_amount);
    }

    // ---- Custom per-line price ----

    public function test_custom_unit_price_overrides_list_and_snapshots_list_price(): void
    {
        $item = $this->makeItem(250, 10); // list 250
        $order = $this->place([
            'customer_id' => $this->customer()->id,
            'items' => [['inventory_id' => $item->id, 'quantity' => 2, 'unit_price' => 200]],
        ]);

        $this->assertEquals(400, $order->subtotal);      // 2 × custom 200
        $this->assertEquals(400, $order->total_amount);
        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id, 'inventory_id' => $item->id, 'unit_price' => 200, 'list_price' => 250,
        ]);
    }

    public function test_custom_price_below_cost_is_allowed(): void
    {
        $item = $this->makeItem(250, 10); // cost 50
        $order = $this->place([
            'customer_id' => $this->customer()->id,
            'items' => [['inventory_id' => $item->id, 'quantity' => 1, 'unit_price' => 20]], // below cost
        ]);

        $this->assertEquals(20, $order->total_amount);
    }

    // ---- Advance payment method ----

    public function test_advance_payment_method_is_captured(): void
    {
        $item = $this->makeItem(250, 10);
        $order = $this->place([
            'customer_id' => $this->customer()->id,
            'advance_paid' => 100, 'payment_method' => 'upi',
            'items' => [['inventory_id' => $item->id, 'quantity' => 1]],
        ]);

        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id, 'amount' => 100, 'method' => 'upi', 'note' => 'Initial advance',
        ]);
    }

    public function test_advance_method_defaults_to_cash(): void
    {
        $item = $this->makeItem(250, 10);
        $order = $this->place([
            'customer_id' => $this->customer()->id,
            'advance_paid' => 100,
            'items' => [['inventory_id' => $item->id, 'quantity' => 1]],
        ]);

        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id, 'amount' => 100, 'method' => 'cash',
        ]);
    }

    public function test_advance_is_clamped_to_the_discounted_total(): void
    {
        $item = $this->makeItem(250, 10);
        $order = $this->place([
            'customer_id' => $this->customer()->id,
            'discount_type' => 'percent', 'discount_value' => 50, // total 250
            'advance_paid' => 9999,
            'items' => [['inventory_id' => $item->id, 'quantity' => 2]], // subtotal 500
        ]);

        $this->assertEquals(250, $order->total_amount);
        $this->assertEquals(250, $order->advance_paid); // capped at the discounted total
        $this->assertEquals(0, $order->balance_due);
    }

    // ---- Edit interaction (discount preserved) ----

    public function test_editing_an_order_preserves_its_discount(): void
    {
        $item = $this->makeItem(250, 10);
        $order = $this->place([
            'customer_id' => $this->customer()->id,
            'discount_type' => 'percent', 'discount_value' => 10,
            'items' => [['inventory_id' => $item->id, 'quantity' => 2]], // subtotal 500, total 450
        ]);
        $this->assertEquals(450, $order->total_amount);

        // Edit qty 2 → 4 without re-sending discount: it must be preserved.
        $this->actingAs($this->user)->put(route('tenant.orders.update', $order), [
            'items' => [['inventory_id' => $item->id, 'quantity' => 4]],
        ])->assertRedirect();

        $order->refresh();
        $this->assertSame('percent', $order->discount_type);
        $this->assertEquals(1000, $order->subtotal);        // 4 × 250
        $this->assertEquals(100, $order->discount_amount);   // 10% re-resolved on new subtotal
        $this->assertEquals(900, $order->total_amount);
    }

    public function test_editing_can_apply_a_custom_price_per_line(): void
    {
        $item = $this->makeItem(250, 10);
        $order = $this->place([
            'customer_id' => $this->customer()->id,
            'items' => [['inventory_id' => $item->id, 'quantity' => 2]], // total 500
        ]);

        $this->actingAs($this->user)->put(route('tenant.orders.update', $order), [
            'items' => [['inventory_id' => $item->id, 'quantity' => 2, 'unit_price' => 199]],
        ])->assertRedirect();

        $this->assertEquals(398, $order->fresh()->total_amount);
        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id, 'inventory_id' => $item->id, 'unit_price' => 199, 'list_price' => 250,
        ]);
    }
}
