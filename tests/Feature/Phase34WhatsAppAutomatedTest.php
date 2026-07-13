<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsAppMessage;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsAppConfig;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\WhatsAppScheduler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * FT-WhatsApp Phase 4/5 — the Automated send engine (against the log driver).
 *
 * A *ready* Automated store schedules a message on order events; the sweep
 * dispatches it once its grace window elapses; the job sends via the gateway and
 * flips the row to sent. Dedupe guarantees one send per order+event, and a
 * reverted/cancelled row is never sent. Manual/Off stores schedule nothing.
 */
class Phase34WhatsAppAutomatedTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        // Tests run on the default `log` driver, so no credentials are needed.
        config(['whatsapp.driver' => 'log']);
        $this->tenant = Tenant::create(['store_name' => 'Test Optical', 'tax_id' => 'GST', 'address' => 'Mumbai']);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'store_admin']);
    }

    /** A connected Automated store. */
    private function automate(string $mode = 'automated'): WhatsAppConfig
    {
        return WhatsAppConfig::create([
            'tenant_id' => $this->tenant->id,
            'mode' => $mode,
            'enabled' => true,
            'verified_at' => now(),
        ]);
    }

    private function order(string $status = 'pending', string $phone = '+91 9876543210'): Order
    {
        $customer = Customer::create(['tenant_id' => $this->tenant->id, 'name' => 'Rahul', 'phone' => $phone]);

        return Order::create([
            'tenant_id' => $this->tenant->id, 'customer_id' => $customer->id,
            'status' => $status, 'fulfillment_type' => 'special',
            'subtotal' => 1000, 'discount_type' => 'none', 'discount_value' => 0, 'discount_amount' => 0,
            'total_amount' => 1000, 'advance_paid' => 200,
        ])->load('customer', 'tenant');
    }

    // ---- Scheduling ----

    public function test_marking_ready_schedules_an_automated_message(): void
    {
        $this->automate();
        $order = $this->order('pending');

        $this->actingAs($this->user)
            ->patchJson(route('tenant.orders.status', $order), ['status' => 'ready_for_pickup'])
            ->assertOk();

        $this->assertDatabaseHas('whatsapp_messages', [
            'order_id' => $order->id, 'event' => 'order_ready',
            'status' => 'scheduled', 'channel' => 'cloud_api',
        ]);
        $msg = WhatsAppMessage::first();
        $this->assertSame('+919876543210', $msg->to_phone);
        // Scheduled ~60s out (the undo window), not immediately.
        $this->assertTrue($msg->scheduled_for->greaterThan(now()->addSeconds(30)));
    }

    public function test_scheduling_is_idempotent(): void
    {
        $this->automate();
        $order = $this->order('ready_for_pickup');
        $scheduler = app(WhatsAppScheduler::class);

        $scheduler->handle($order, 'order_ready');
        $scheduler->handle($order, 'order_ready'); // second call must not duplicate

        $this->assertSame(1, WhatsAppMessage::where('order_id', $order->id)->where('event', 'order_ready')->count());
    }

    public function test_manual_store_schedules_nothing(): void
    {
        $this->automate('manual');
        $order = $this->order('pending');

        $this->actingAs($this->user)
            ->patchJson(route('tenant.orders.status', $order), ['status' => 'ready_for_pickup'])
            ->assertOk();

        $this->assertSame(0, WhatsAppMessage::count());
    }

    public function test_off_store_schedules_nothing(): void
    {
        $this->automate('off');
        $order = $this->order('pending');
        app(WhatsAppScheduler::class)->handle($order, 'order_ready');
        $this->assertSame(0, WhatsAppMessage::count());
    }

    public function test_automated_but_unverified_schedules_nothing(): void
    {
        // Mode automated but never test-connected ⇒ not ready ⇒ falls back to manual.
        WhatsAppConfig::create(['tenant_id' => $this->tenant->id, 'mode' => 'automated']);
        $order = $this->order('ready_for_pickup');

        app(WhatsAppScheduler::class)->handle($order, 'order_ready');
        $this->assertSame(0, WhatsAppMessage::count());
    }

    // ---- The sweep + the job ----

    public function test_sweep_dispatches_only_due_messages(): void
    {
        $this->automate();
        $due = $this->order('ready_for_pickup');
        $future = $this->order('ready_for_pickup', '+91 9000000009');

        WhatsAppMessage::create([
            'tenant_id' => $this->tenant->id, 'order_id' => $due->id, 'event' => 'order_ready',
            'to_phone' => '+919876543210', 'status' => 'scheduled', 'scheduled_for' => now()->subMinute(),
        ]);
        WhatsAppMessage::create([
            'tenant_id' => $this->tenant->id, 'order_id' => $future->id, 'event' => 'order_ready',
            'to_phone' => '+919000000009', 'status' => 'scheduled', 'scheduled_for' => now()->addMinutes(5),
        ]);

        Bus::fake();
        $this->artisan('whatsapp:dispatch-due')->assertSuccessful();
        Bus::assertDispatchedTimes(SendWhatsAppMessage::class, 1);
    }

    public function test_job_sends_a_scheduled_message(): void
    {
        $this->automate();
        $order = $this->order('ready_for_pickup');
        $msg = WhatsAppMessage::create([
            'tenant_id' => $this->tenant->id, 'order_id' => $order->id, 'event' => 'order_ready',
            'to_phone' => '+919876543210', 'template_name' => 'order_ready',
            'payload' => ['body_params' => ['Rahul', 'Test Optical', 'ABCD1234', '₹ 800.00']],
            'status' => 'scheduled', 'scheduled_for' => now()->subMinute(),
        ]);

        app(SendWhatsAppMessage::class, ['messageId' => $msg->id])->handle(app(\App\Services\WhatsApp\WhatsAppGateway::class));

        $msg->refresh();
        $this->assertSame('sent', $msg->status);
        $this->assertNotNull($msg->sent_at);
        $this->assertStringStartsWith('log-', (string) $msg->provider_message_id);
    }

    public function test_job_never_sends_a_cancelled_message(): void
    {
        $this->automate();
        $order = $this->order('ready_for_pickup');
        $msg = WhatsAppMessage::create([
            'tenant_id' => $this->tenant->id, 'order_id' => $order->id, 'event' => 'order_ready',
            'to_phone' => '+919876543210', 'status' => 'cancelled', 'scheduled_for' => now()->subMinute(),
        ]);

        app(SendWhatsAppMessage::class, ['messageId' => $msg->id])->handle(app(\App\Services\WhatsApp\WhatsAppGateway::class));

        $this->assertSame('cancelled', $msg->fresh()->status); // untouched
    }

    // ---- Tenant isolation ----

    public function test_messages_are_tenant_scoped(): void
    {
        $this->automate();
        $order = $this->order('ready_for_pickup');
        app(WhatsAppScheduler::class)->handle($order, 'order_ready');

        // Another tenant's admin sees none of this store's messages.
        $other = Tenant::create(['store_name' => 'Other', 'tax_id' => 'G2', 'address' => 'Pune']);
        $otherUser = User::factory()->create(['tenant_id' => $other->id, 'role' => 'store_admin']);

        $this->actingAs($otherUser);
        $this->assertSame(0, WhatsAppMessage::count());
    }

    // ---- Test-send activates automated mode ----

    public function test_test_send_marks_the_store_connected(): void
    {
        WhatsAppConfig::create(['tenant_id' => $this->tenant->id, 'mode' => 'automated']);

        $this->actingAs($this->user)
            ->post(route('tenant.whatsapp.test'), ['test_to' => '+91 9876543210'])
            ->assertRedirect();

        $config = WhatsAppConfig::first();
        $this->assertTrue((bool) $config->enabled);
        $this->assertNotNull($config->verified_at);
        $this->assertTrue($config->isReady());
    }
}
