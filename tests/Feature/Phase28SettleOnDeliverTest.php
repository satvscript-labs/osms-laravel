<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 6.1 — settle a payment (± last-moment discount) when marking an order
 * delivered, via the shared POST orders/{order}/settle endpoint. Skippable, and
 * a last-moment discount can never drop the total below what's already paid.
 */
class Phase28SettleOnDeliverTest extends TestCase
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

    private function order(float $subtotal, float $advance, string $status = 'ready_for_pickup', ?Tenant $tenant = null): Order
    {
        $tenant ??= $this->tenant;
        $customer = Customer::create(['tenant_id' => $tenant->id, 'name' => 'Rahul', 'phone' => '+91 90000' . random_int(10000, 99999)]);

        return Order::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'status' => $status,
            'fulfillment_type' => 'special',
            'subtotal' => $subtotal,
            'discount_type' => 'none',
            'discount_value' => 0,
            'discount_amount' => 0,
            'total_amount' => $subtotal,
            'advance_paid' => $advance,
        ]);
    }

    public function test_full_settlement_marks_delivered_and_zeroes_balance(): void
    {
        $order = $this->order(1000, 200); // balance 800

        $this->actingAs($this->user)->postJson(route('tenant.orders.settle', $order), [
            'amount' => 800, 'method' => 'cash', 'mark_delivered' => 1,
        ])->assertOk()->assertJson(['ok' => true, 'status' => 'delivered']);

        $order->refresh();
        $this->assertSame('delivered', $order->status);
        $this->assertEqualsWithDelta(1000, (float) $order->advance_paid, 0.01);
        $this->assertEqualsWithDelta(0, (float) $order->balance_due, 0.01);
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'amount' => 800, 'method' => 'cash']);
    }

    public function test_partial_settlement_marks_delivered_with_balance_remaining(): void
    {
        $order = $this->order(1000, 200); // balance 800

        $this->actingAs($this->user)->postJson(route('tenant.orders.settle', $order), [
            'amount' => 300, 'method' => 'upi', 'mark_delivered' => 1,
        ])->assertOk();

        $order->refresh();
        $this->assertSame('delivered', $order->status);
        $this->assertEqualsWithDelta(500, (float) $order->advance_paid, 0.01);
        $this->assertEqualsWithDelta(500, (float) $order->balance_due, 0.01);
    }

    public function test_skip_settlement_marks_delivered_without_a_payment(): void
    {
        $order = $this->order(1000, 200); // balance 800

        $this->actingAs($this->user)->postJson(route('tenant.orders.settle', $order), [
            'mark_delivered' => 1, // no amount → "Mark delivered without settling"
        ])->assertOk();

        $order->refresh();
        $this->assertSame('delivered', $order->status);
        $this->assertEqualsWithDelta(200, (float) $order->advance_paid, 0.01); // untouched
        $this->assertEqualsWithDelta(800, (float) $order->balance_due, 0.01);
        $this->assertDatabaseMissing('payments', ['order_id' => $order->id]);
    }

    public function test_last_moment_discount_lowers_the_total(): void
    {
        $order = $this->order(1000, 200); // balance 800

        $this->actingAs($this->user)->postJson(route('tenant.orders.settle', $order), [
            'discount_type' => 'percent', 'discount_value' => 10, // ₹100 off → total 900
            'amount' => 700, 'method' => 'card', 'mark_delivered' => 1,
        ])->assertOk();

        $order->refresh();
        $this->assertEqualsWithDelta(100, (float) $order->discount_amount, 0.01);
        $this->assertEqualsWithDelta(900, (float) $order->total_amount, 0.01);
        $this->assertEqualsWithDelta(900, (float) $order->advance_paid, 0.01); // 200 + 700
        $this->assertEqualsWithDelta(0, (float) $order->balance_due, 0.01);
        $this->assertSame('delivered', $order->status);
    }

    public function test_discount_cannot_drop_total_below_what_is_already_paid(): void
    {
        $order = $this->order(1000, 950); // already ₹950 paid

        $this->actingAs($this->user)->postJson(route('tenant.orders.settle', $order), [
            'discount_type' => 'amount', 'discount_value' => 100, // would make total 900 < 950
            'mark_delivered' => 1,
        ])->assertStatus(422)->assertJson(['ok' => false]);

        $order->refresh();
        $this->assertEqualsWithDelta(1000, (float) $order->total_amount, 0.01); // unchanged
        $this->assertSame('ready_for_pickup', $order->status); // not delivered
    }

    public function test_settle_is_tenant_isolated(): void
    {
        $other = Tenant::create(['store_name' => 'Other', 'tax_id' => 'G2', 'address' => 'Delhi']);
        $order = $this->order(1000, 0, 'ready_for_pickup', $other); // belongs to another store

        $this->actingAs($this->user)->postJson(route('tenant.orders.settle', $order), [
            'amount' => 1000, 'method' => 'cash', 'mark_delivered' => 1,
        ])->assertNotFound();

        $this->assertSame('ready_for_pickup', $order->fresh()->status);
    }
}
