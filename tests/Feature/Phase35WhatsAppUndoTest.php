<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsAppConfig;
use App\Models\WhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FT-WhatsApp Phase 6 — the automated "undo ring" and its revert endpoint.
 *
 * While a ready/delivered message is inside its grace window, the board shows an
 * undo control; tapping it cancels the scheduled message and steps the order back
 * one status. Reverting delivered→ready must NOT re-schedule the (already sent)
 * ready message. Once the message has sent, undo is refused.
 */
class Phase35WhatsAppUndoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        config(['whatsapp.driver' => 'log']);
        $this->tenant = Tenant::create(['store_name' => 'Test Optical', 'tax_id' => 'GST', 'address' => 'Mumbai']);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'store_admin']);
        WhatsAppConfig::create(['tenant_id' => $this->tenant->id, 'mode' => 'automated', 'enabled' => true, 'verified_at' => now()]);
    }

    private function order(string $status = 'pending', string $phone = '+91 9876543210'): Order
    {
        $customer = Customer::create(['tenant_id' => $this->tenant->id, 'name' => 'Rahul', 'phone' => $phone]);

        return Order::create([
            'tenant_id' => $this->tenant->id, 'customer_id' => $customer->id,
            'status' => $status, 'fulfillment_type' => 'special',
            'subtotal' => 1000, 'discount_type' => 'none', 'discount_value' => 0, 'discount_amount' => 0,
            'total_amount' => 1000, 'advance_paid' => 200,
        ]);
    }

    private function scheduled(Order $order, string $event, string $status = 'scheduled', ?\DateTimeInterface $sendAt = null): WhatsAppMessage
    {
        return WhatsAppMessage::create([
            'tenant_id' => $this->tenant->id, 'order_id' => $order->id, 'customer_id' => $order->customer_id,
            'event' => $event, 'to_phone' => '+919876543210', 'status' => $status,
            'scheduled_for' => $sendAt ?? now()->addSeconds(60),
        ]);
    }

    public function test_board_shows_the_undo_ring_while_a_message_is_pending(): void
    {
        $order = $this->order('ready_for_pickup');
        $this->scheduled($order, 'order_ready');

        $this->actingAs($this->user)
            ->get(route('tenant.orders.index', ['view' => 'kanban']))
            ->assertOk()
            ->assertSee('wa-undo')
            ->assertSee(route('tenant.orders.revert', $order), false);
    }

    public function test_revert_cancels_the_message_and_steps_status_back(): void
    {
        $order = $this->order('ready_for_pickup');
        $msg = $this->scheduled($order, 'order_ready');

        $this->actingAs($this->user)
            ->postJson(route('tenant.orders.revert', $order))
            ->assertOk()
            ->assertJson(['ok' => true, 'status' => 'pending']);

        $this->assertSame('cancelled', $msg->fresh()->status);
        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_reverting_delivered_goes_back_to_ready_without_resending_ready(): void
    {
        $order = $this->order('delivered');
        // The ready message was already sent earlier in the lifecycle.
        $this->scheduled($order, 'order_ready', 'sent', now()->subMinutes(5));
        $deliveredMsg = $this->scheduled($order, 'order_delivered');

        $this->actingAs($this->user)
            ->postJson(route('tenant.orders.revert', $order))
            ->assertOk()
            ->assertJson(['status' => 'ready_for_pickup']);

        $this->assertSame('cancelled', $deliveredMsg->fresh()->status);
        // No NEW order_ready row was created, and the sent one is untouched.
        $this->assertSame(1, WhatsAppMessage::where('order_id', $order->id)->where('event', 'order_ready')->count());
        $this->assertSame('sent', WhatsAppMessage::where('order_id', $order->id)->where('event', 'order_ready')->first()->status);
    }

    public function test_revert_is_refused_once_the_window_has_passed(): void
    {
        $order = $this->order('ready_for_pickup');
        // scheduled_for already in the past → no longer cancellable.
        $this->scheduled($order, 'order_ready', 'scheduled', now()->subSeconds(5));

        $this->actingAs($this->user)
            ->postJson(route('tenant.orders.revert', $order))
            ->assertStatus(422)
            ->assertJson(['ok' => false]);

        $this->assertSame('ready_for_pickup', $order->fresh()->status); // unchanged
    }

    public function test_revert_is_tenant_scoped(): void
    {
        $order = $this->order('ready_for_pickup');
        $this->scheduled($order, 'order_ready');

        $other = Tenant::create(['store_name' => 'Other', 'tax_id' => 'G2', 'address' => 'Pune']);
        $otherUser = User::factory()->create(['tenant_id' => $other->id, 'role' => 'store_admin']);

        // A different tenant can't reach this order (route-model binding 404s).
        $this->actingAs($otherUser)
            ->postJson(route('tenant.orders.revert', $order))
            ->assertNotFound();
    }
}
