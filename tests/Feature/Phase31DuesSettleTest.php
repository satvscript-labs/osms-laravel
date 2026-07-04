<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 6.3 — settle a pending due straight from the Analytics dues list. Reuses
 * the shared settle() endpoint in record-only mode: it records a payment (±
 * last-moment discount) but never changes the order's fulfillment status.
 */
class Phase31DuesSettleTest extends TestCase
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

    private function order(float $subtotal, float $advance, string $status): Order
    {
        $customer = Customer::create(['tenant_id' => $this->tenant->id, 'name' => 'Rahul', 'phone' => '+91 90000' . random_int(10000, 99999)]);

        return Order::create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
            'status' => $status,
            'fulfillment_type' => 'special',
            'subtotal' => $subtotal,
            'discount_type' => 'none', 'discount_value' => 0, 'discount_amount' => 0,
            'total_amount' => $subtotal,
            'advance_paid' => $advance,
        ]);
    }

    public function test_settling_a_due_records_payment_without_changing_status(): void
    {
        // A still-pending order carrying a balance (the dues list shows any
        // non-cancelled order with balance_due > 0, regardless of status).
        $order = $this->order(1000, 200, 'pending'); // balance 800

        $this->actingAs($this->user)->postJson(route('tenant.orders.settle', $order), [
            'amount' => 800, 'method' => 'upi', // no mark_delivered → record only
        ])->assertOk();

        $order->refresh();
        $this->assertSame('pending', $order->status); // status untouched (6.3)
        $this->assertEqualsWithDelta(1000, (float) $order->advance_paid, 0.01);
        $this->assertEqualsWithDelta(0, (float) $order->balance_due, 0.01);
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'amount' => 800, 'method' => 'upi']);
    }

    public function test_a_delivered_order_with_a_balance_can_still_be_settled(): void
    {
        // Instant sales land as delivered but may still carry a balance.
        $order = $this->order(1000, 0, 'delivered'); // balance 1000

        $this->actingAs($this->user)->postJson(route('tenant.orders.settle', $order), [
            'amount' => 1000, 'method' => 'cash',
        ])->assertOk();

        $order->refresh();
        $this->assertSame('delivered', $order->status);
        $this->assertEqualsWithDelta(0, (float) $order->balance_due, 0.01);
    }

    public function test_dues_list_renders_a_settle_button(): void
    {
        $this->order(1000, 200, 'pending');

        $this->actingAs($this->user)
            ->get(route('tenant.analytics.index'))
            ->assertOk()
            ->assertSee('dues-settle-btn', false);
    }
}
