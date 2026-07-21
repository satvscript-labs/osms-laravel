<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsAppMessage;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DATA-01 / PRIV-05 — whatsapp_messages integrity.
 *   • deleting a tenant/customer must not leave orphaned rows holding a phone (FKs);
 *   • the DB unique index on dedupe_key makes a duplicate LIVE row impossible, while
 *     still allowing a re-send once the prior row is cancelled/failed.
 */
class Phase44WhatsAppIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['store_name' => 'Integrity Optical', 'tax_id' => 'G', 'address' => 'Surat']);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'store_admin']);
    }

    private function message(array $overrides = []): WhatsAppMessage
    {
        return WhatsAppMessage::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'event' => 'order_ready',
            'to_phone' => '+919876500000',
            'status' => 'scheduled',
        ], $overrides));
    }

    public function test_deleting_a_tenant_cascades_and_leaves_no_orphaned_messages(): void
    {
        $this->message();
        $this->assertSame(1, WhatsAppMessage::withoutGlobalScopes()->count());

        // Hard-delete the tenant (as ResetPlatformData does).
        $this->tenant->delete();

        $this->assertSame(0, WhatsAppMessage::withoutGlobalScopes()->count(),
            'A deleted store must leave no message rows holding phone numbers.');
    }

    public function test_deleting_a_customer_cascades_to_their_messages(): void
    {
        $customer = Customer::create(['tenant_id' => $this->tenant->id, 'name' => 'C', 'phone' => '+91 9876500001']);
        $this->message(['customer_id' => $customer->id]);

        $customer->forceDelete(); // permanent erasure (as the 30-day purge does)

        $this->assertSame(0, WhatsAppMessage::withoutGlobalScopes()->count());
    }

    public function test_dedupe_key_prevents_a_duplicate_live_row(): void
    {
        $order = $this->order();
        $this->message(['order_id' => $order->id, 'dedupe_key' => $order->id . ':order_ready']);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->message(['order_id' => $order->id, 'dedupe_key' => $order->id . ':order_ready']);
    }

    public function test_a_cancelled_row_frees_the_dedupe_key_for_a_resend(): void
    {
        $order = $this->order();
        $first = $this->message(['order_id' => $order->id, 'dedupe_key' => $order->id . ':order_ready']);

        // Cancel (revert) clears the key.
        $first->update(['status' => 'cancelled', 'dedupe_key' => null]);

        // A re-send with the same key is now allowed (no exception).
        $second = $this->message(['order_id' => $order->id, 'dedupe_key' => $order->id . ':order_ready']);
        $this->assertNotNull($second->id);
        $this->assertSame(2, WhatsAppMessage::withoutGlobalScopes()->count());
    }

    public function test_multiple_null_dedupe_keys_are_allowed(): void
    {
        // Two failed/cancelled rows (key null) must coexist.
        $this->message(['status' => 'failed', 'dedupe_key' => null]);
        $this->message(['status' => 'failed', 'dedupe_key' => null]);

        $this->assertSame(2, WhatsAppMessage::withoutGlobalScopes()->count());
    }

    private function order(): Order
    {
        $customer = Customer::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'phone' => '+91 9876500002']);

        return Order::create([
            'tenant_id' => $this->tenant->id, 'customer_id' => $customer->id,
            'status' => 'ready_for_pickup', 'fulfillment_type' => 'special',
            'subtotal' => 500, 'total_amount' => 500, 'advance_paid' => 0,
        ]);
    }
}
