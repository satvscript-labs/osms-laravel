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
 * TEST-01 — regression guards for the order status machine (BIZ-01) and the
 * settle-time discount lockdown (BIZ-02). Ports the throwaway audit probes into
 * the permanent suite:
 *   • a cancelled order can never be resurrected (stock/revenue corruption);
 *   • a delivered order is terminal via the status endpoint;
 *   • a delivered order's discount can never be re-priced through settle().
 * Forward flow and legitimate settle-on-deliver must still work unchanged.
 */
class Phase39OrderStatusGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['store_name' => 'Guard Optical', 'tax_id' => 'G', 'address' => 'Mumbai']);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'store_admin']);
    }

    private function makeItem(int $stock = 10, float $price = 500): Inventory
    {
        return Inventory::create([
            'tenant_id' => $this->tenant->id,
            'sku' => 'SKU-' . uniqid(), 'barcode' => (string) random_int(100000000000, 999999999999),
            'item_type' => 'frame', 'brand' => 'Ray-Ban', 'model_name' => 'Aviator',
            'cost_price' => 100, 'selling_price' => $price, 'stock_qty' => $stock, 'min_alert_qty' => 1,
        ]);
    }

    private function placeOrder(Inventory $item, int $qty = 2, float $advance = 0): Order
    {
        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Rahul', 'phone' => '+91 90000' . random_int(10000, 99999),
        ]);

        $this->actingAs($this->user)->post(route('tenant.orders.store'), [
            'customer_id' => $customer->id,
            'advance_paid' => $advance,
            'items' => [['inventory_id' => $item->id, 'quantity' => $qty]],
        ])->assertRedirect();

        return Order::latest()->first();
    }

    // ---- BIZ-01: cancelled orders are terminal ----

    public function test_a_cancelled_order_cannot_be_advanced_to_delivered(): void
    {
        $item = $this->makeItem(10);
        $order = $this->placeOrder($item, 2); // stock 8

        $this->actingAs($this->user)->post(route('tenant.orders.cancel', $order))->assertRedirect();
        $stockAfterCancel = $item->fresh()->stock_qty; // 10 (restored)

        $this->actingAs($this->user)
            ->patch(route('tenant.orders.status', $order), ['status' => 'delivered'])
            ->assertRedirect(); // rejected with a flash error, not applied

        $order->refresh();
        $this->assertSame('cancelled', $order->status);
        $this->assertNotNull($order->cancelled_at);
        // Stock must not have been drawn down a second time.
        $this->assertSame($stockAfterCancel, $item->fresh()->stock_qty);
    }

    public function test_a_cancelled_order_status_change_returns_422_for_json(): void
    {
        $order = $this->placeOrder($this->makeItem(), 1);
        $this->actingAs($this->user)->post(route('tenant.orders.cancel', $order))->assertRedirect();

        $this->actingAs($this->user)
            ->patchJson(route('tenant.orders.status', $order), ['status' => 'delivered'])
            ->assertStatus(422)
            ->assertJson(['ok' => false]);

        $this->assertSame('cancelled', $order->fresh()->status);
    }

    // ---- BIZ-01: delivered orders are terminal via the status endpoint ----

    public function test_a_delivered_order_cannot_be_regressed_to_pending(): void
    {
        $item = $this->makeItem();
        $order = $this->placeOrder($item, 1);
        $order->update(['status' => 'delivered']);

        $this->actingAs($this->user)
            ->patchJson(route('tenant.orders.status', $order), ['status' => 'pending'])
            ->assertStatus(422);

        $this->assertSame('delivered', $order->fresh()->status);
    }

    public function test_a_delivered_order_cannot_be_moved_to_ready(): void
    {
        $order = $this->placeOrder($this->makeItem(), 1);
        $order->update(['status' => 'delivered']);

        $this->actingAs($this->user)
            ->patch(route('tenant.orders.status', $order), ['status' => 'ready_for_pickup'])
            ->assertRedirect();

        $this->assertSame('delivered', $order->fresh()->status);
    }

    // ---- Forward flow + legitimate backward-to-lab still work ----

    public function test_pending_can_advance_to_ready_and_delivered(): void
    {
        $order = $this->placeOrder($this->makeItem(), 1);

        $this->actingAs($this->user)
            ->patch(route('tenant.orders.status', $order), ['status' => 'ready_for_pickup'])
            ->assertRedirect();
        $this->assertSame('ready_for_pickup', $order->fresh()->status);

        $this->actingAs($this->user)
            ->patch(route('tenant.orders.status', $order), ['status' => 'delivered'])
            ->assertRedirect();
        $this->assertSame('delivered', $order->fresh()->status);
    }

    public function test_ready_can_move_back_to_pending(): void
    {
        $order = $this->placeOrder($this->makeItem(), 1);
        $order->update(['status' => 'ready_for_pickup']);

        $this->actingAs($this->user)
            ->patch(route('tenant.orders.status', $order), ['status' => 'pending'])
            ->assertRedirect();

        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_setting_the_same_status_is_an_idempotent_noop(): void
    {
        $order = $this->placeOrder($this->makeItem(), 1); // pending

        $this->actingAs($this->user)
            ->patchJson(route('tenant.orders.status', $order), ['status' => 'pending'])
            ->assertStatus(200)
            ->assertJson(['ok' => true, 'status' => 'pending']);
    }

    // ---- BIZ-02: settle-time discount is locked after delivery ----

    public function test_a_discount_cannot_be_applied_to_a_delivered_order_via_settle(): void
    {
        $item = $this->makeItem(10, 500);
        $order = $this->placeOrder($item, 2); // total 1000
        $order->update(['status' => 'delivered']);

        $this->actingAs($this->user)
            ->postJson(route('tenant.orders.settle', $order), [
                'discount_type' => 'percent', 'discount_value' => 90,
            ])
            ->assertStatus(422);

        $order->refresh();
        $this->assertSame('none', $order->discount_type);
        $this->assertEquals(1000, (float) $order->total_amount);
    }

    public function test_dues_payment_on_a_delivered_order_still_works(): void
    {
        $item = $this->makeItem(10, 500);
        $order = $this->placeOrder($item, 2); // total 1000, balance 1000
        $order->update(['status' => 'delivered']);

        // 6.3 dues settlement: payment only, no discount, no status change.
        $this->actingAs($this->user)
            ->post(route('tenant.orders.settle', $order), [
                'amount' => 400, 'method' => 'cash',
            ])
            ->assertRedirect();

        $order->refresh();
        $this->assertEquals(400, (float) $order->advance_paid);
        $this->assertEquals(600, (float) $order->balance_due);
        $this->assertSame('delivered', $order->status);
    }

    public function test_settle_and_deliver_with_last_moment_discount_still_works(): void
    {
        $item = $this->makeItem(10, 500);
        $order = $this->placeOrder($item, 2); // total 1000, pending

        // Board "Settle & deliver": discount applied while still open, then delivered.
        $this->actingAs($this->user)
            ->postJson(route('tenant.orders.settle', $order), [
                'discount_type' => 'percent', 'discount_value' => 10,
                'amount' => 900, 'method' => 'cash', 'mark_delivered' => 1,
            ])
            ->assertStatus(200);

        $order->refresh();
        $this->assertSame('delivered', $order->status);
        $this->assertEquals(900, (float) $order->total_amount); // 1000 − 10%
        $this->assertEquals(900, (float) $order->advance_paid);
        $this->assertEquals(0, (float) $order->balance_due);
    }

    // ---- Tenant isolation ----

    public function test_cannot_change_another_tenants_order_status(): void
    {
        $order = $this->placeOrder($this->makeItem(), 1);

        $otherTenant = Tenant::create(['store_name' => 'Other', 'address' => 'X']);
        $otherUser = User::factory()->create(['tenant_id' => $otherTenant->id, 'role' => 'store_admin']);

        $this->actingAs($otherUser)
            ->patch(route('tenant.orders.status', $order), ['status' => 'ready_for_pickup'])
            ->assertNotFound();
    }
}
