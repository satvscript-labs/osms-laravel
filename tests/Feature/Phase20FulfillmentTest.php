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
 * Session 3 — FT-Fulfillment F-a: instant vs. special orders. An instant
 * "grab & go" sale is created already delivered (stock/payment/discount all
 * still apply); a special order requires an estimated ready date and keeps the
 * pending → ready → delivered pipeline. Additive to store()/update().
 */
class Phase20FulfillmentTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $tenant = Tenant::create(['store_name' => 'Test Optical', 'tax_id' => 'GST123', 'address' => 'Mumbai']);
        $this->user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'store_admin']);
    }

    private function makeItem(int $stock = 10): Inventory
    {
        return Inventory::create([
            'tenant_id' => $this->user->tenant_id,
            'sku' => 'SKU-' . uniqid(), 'barcode' => (string) random_int(100000000000, 999999999999),
            'item_type' => 'frame', 'brand' => 'Ray-Ban', 'model_name' => 'Aviator',
            'cost_price' => 50, 'selling_price' => 250, 'stock_qty' => $stock, 'min_alert_qty' => 2,
        ]);
    }

    private function customer(): Customer
    {
        return Customer::create([
            'tenant_id' => $this->user->tenant_id,
            'name' => 'Rahul', 'phone' => '+91 90000' . random_int(10000, 99999),
        ]);
    }

    // ---- Instant ----

    public function test_instant_order_is_created_already_delivered(): void
    {
        $item = $this->makeItem(10);
        $this->actingAs($this->user)->post(route('tenant.orders.store'), [
            'customer_id' => $this->customer()->id,
            'fulfillment_type' => 'instant',
            'advance_paid' => 500, 'payment_method' => 'card',
            'items' => [['inventory_id' => $item->id, 'quantity' => 2]],
        ])->assertRedirect();

        $order = Order::latest()->first();
        $this->assertSame('delivered', $order->status);
        $this->assertSame('instant', $order->fulfillment_type);
        $this->assertNull($order->estimated_ready_at);
        // Stock, payment, and totals still apply exactly as normal.
        $this->assertSame(8, $item->fresh()->stock_qty);
        $this->assertEquals(500, $order->total_amount);
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'amount' => 500, 'method' => 'card']);
    }

    public function test_instant_order_ignores_any_supplied_ready_date(): void
    {
        $item = $this->makeItem();
        $this->actingAs($this->user)->post(route('tenant.orders.store'), [
            'customer_id' => $this->customer()->id,
            'fulfillment_type' => 'instant',
            'estimated_ready_at' => now()->addDays(5)->toDateString(),
            'items' => [['inventory_id' => $item->id, 'quantity' => 1]],
        ])->assertRedirect();

        $this->assertNull(Order::latest()->first()->estimated_ready_at);
    }

    // ---- Special ----

    public function test_special_order_starts_pending_with_a_ready_date(): void
    {
        $item = $this->makeItem();
        $date = now()->addDays(4)->toDateString();

        $this->actingAs($this->user)->post(route('tenant.orders.store'), [
            'customer_id' => $this->customer()->id,
            'fulfillment_type' => 'special',
            'estimated_ready_at' => $date,
            'items' => [['inventory_id' => $item->id, 'quantity' => 1]],
        ])->assertRedirect();

        $order = Order::latest()->first();
        $this->assertSame('pending', $order->status);
        $this->assertSame('special', $order->fulfillment_type);
        $this->assertSame($date, $order->estimated_ready_at->toDateString());
    }

    public function test_special_order_requires_an_estimated_ready_date(): void
    {
        $item = $this->makeItem();
        $this->actingAs($this->user)->post(route('tenant.orders.store'), [
            'customer_id' => $this->customer()->id,
            'fulfillment_type' => 'special',
            'items' => [['inventory_id' => $item->id, 'quantity' => 1]],
        ])->assertSessionHasErrors('estimated_ready_at');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_special_order_rejects_a_past_ready_date(): void
    {
        $item = $this->makeItem();
        $this->actingAs($this->user)->post(route('tenant.orders.store'), [
            'customer_id' => $this->customer()->id,
            'fulfillment_type' => 'special',
            'estimated_ready_at' => now()->subDay()->toDateString(),
            'items' => [['inventory_id' => $item->id, 'quantity' => 1]],
        ])->assertSessionHasErrors('estimated_ready_at');
    }

    // ---- Backward compatibility ----

    public function test_order_without_fulfillment_type_defaults_to_special_pending(): void
    {
        $item = $this->makeItem();
        $this->actingAs($this->user)->post(route('tenant.orders.store'), [
            'customer_id' => $this->customer()->id,
            'items' => [['inventory_id' => $item->id, 'quantity' => 1]],
        ])->assertRedirect();

        $order = Order::latest()->first();
        $this->assertSame('special', $order->fulfillment_type);
        $this->assertSame('pending', $order->status);
        $this->assertNull($order->estimated_ready_at); // programmatic create, no date
    }

    // ---- Edit ----

    public function test_editing_an_open_order_can_update_the_ready_date(): void
    {
        $item = $this->makeItem();
        $this->actingAs($this->user)->post(route('tenant.orders.store'), [
            'customer_id' => $this->customer()->id,
            'fulfillment_type' => 'special',
            'estimated_ready_at' => now()->addDays(3)->toDateString(),
            'items' => [['inventory_id' => $item->id, 'quantity' => 1]],
        ])->assertRedirect();
        $order = Order::latest()->first();

        $newDate = now()->addDays(7)->toDateString();
        $this->actingAs($this->user)->put(route('tenant.orders.update', $order), [
            'estimated_ready_at' => $newDate,
            'items' => [['inventory_id' => $item->id, 'quantity' => 1]],
        ])->assertRedirect();

        $this->assertSame($newDate, $order->fresh()->estimated_ready_at->toDateString());
    }
}
