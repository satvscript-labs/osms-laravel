<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Session 3 — FT-Fulfillment F-c: the dashboard "Due to prepare" alert flags
 * special (in-lab) orders whose estimated ready date is today or past. Instant
 * sales and not-yet-due orders are excluded; the query is tenant-scoped.
 */
class Phase21FulfillmentDashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $tenant = Tenant::create(['store_name' => 'Test Optical', 'tax_id' => 'GST123', 'address' => 'Mumbai']);
        $this->user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'store_admin']);
    }

    private function order(array $attrs, ?string $tenantId = null): Order
    {
        $tid = $tenantId ?? $this->user->tenant_id;
        $customer = Customer::create([
            'tenant_id' => $tid, 'name' => 'Cust', 'phone' => '+91 90000' . random_int(10000, 99999),
        ]);

        return Order::create(array_merge([
            'tenant_id' => $tid,
            'customer_id' => $customer->id,
            'status' => 'pending',
            'fulfillment_type' => 'special',
            'subtotal' => 100, 'total_amount' => 100, 'advance_paid' => 0,
        ], $attrs));
    }

    private function dueToPrepare(): \Illuminate\Support\Collection
    {
        return $this->actingAs($this->user)->get(route('tenant.dashboard'))->viewData('dueToPrepare');
    }

    public function test_includes_overdue_and_due_today_excludes_future(): void
    {
        $overdue = $this->order(['estimated_ready_at' => now()->subDay()->toDateString()]);
        $today = $this->order(['estimated_ready_at' => now()->toDateString()]);
        $future = $this->order(['estimated_ready_at' => now()->addDay()->toDateString()]);

        $ids = $this->dueToPrepare()->pluck('id');

        $this->assertTrue($ids->contains($overdue->id));
        $this->assertTrue($ids->contains($today->id));
        $this->assertFalse($ids->contains($future->id));
    }

    public function test_overdue_days_are_reported(): void
    {
        $this->order(['estimated_ready_at' => now()->subDays(2)->toDateString()]);

        $row = $this->dueToPrepare()->first();
        $this->assertSame(2, $row['overdue_days']);
    }

    public function test_excludes_instant_orders(): void
    {
        // Instant sales are created delivered and never carry a ready date.
        $instant = $this->order(['fulfillment_type' => 'instant', 'status' => 'delivered', 'estimated_ready_at' => null]);

        $this->assertFalse($this->dueToPrepare()->pluck('id')->contains($instant->id));
    }

    public function test_excludes_orders_already_out_of_the_lab(): void
    {
        // A special order that's already ready for pickup is no longer "to prepare".
        $ready = $this->order([
            'status' => 'ready_for_pickup', 'estimated_ready_at' => now()->subDay()->toDateString(),
        ]);

        $this->assertFalse($this->dueToPrepare()->pluck('id')->contains($ready->id));
    }

    public function test_is_tenant_isolated(): void
    {
        $other = Tenant::create(['store_name' => 'Other']);
        $foreign = $this->order(['estimated_ready_at' => now()->subDay()->toDateString()], $other->id);

        $this->assertFalse($this->dueToPrepare()->pluck('id')->contains($foreign->id));
    }
}
