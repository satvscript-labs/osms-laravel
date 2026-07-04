<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Session 3 — FT-PayRevenue (5.4): analytics "Collected by payment method"
 * groups actual payments (from the ledger) by method within the range, so the
 * owner can reconcile the cash drawer + each digital channel.
 */
class Phase23PayRevenueTest extends TestCase
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

    private function order(?string $tenantId = null): Order
    {
        $tid = $tenantId ?? $this->tenant->id;
        $c = Customer::create(['tenant_id' => $tid, 'name' => 'C', 'phone' => '+91 90000' . random_int(10000, 99999)]);

        return Order::create([
            'tenant_id' => $tid, 'customer_id' => $c->id, 'status' => 'delivered',
            'subtotal' => 100, 'total_amount' => 100, 'advance_paid' => 0,
        ]);
    }

    private function pay(Order $order, string $method, float $amount, ?string $when = null): Payment
    {
        $p = Payment::create([
            'tenant_id' => $order->tenant_id, 'order_id' => $order->id,
            'amount' => $amount, 'method' => $method, 'recorded_by' => $this->user->id,
        ]);
        if ($when) {
            DB::table('payments')->where('id', $p->id)->update(['created_at' => $when]);
        }

        return $p;
    }

    private function collected(): \Illuminate\Support\Collection
    {
        return $this->actingAs($this->user)->get(route('tenant.analytics.index'))->viewData('collectedByMethod');
    }

    public function test_groups_collected_amount_by_method(): void
    {
        $o = $this->order();
        $this->pay($o, 'cash', 100);
        $this->pay($o, 'cash', 50);
        $this->pay($o, 'card', 40);
        $this->pay($o, 'upi', 30);

        $by = $this->collected();
        $this->assertEquals(150, $by['cash']);
        $this->assertEquals(40, $by['card']);
        $this->assertEquals(30, $by['upi']);
        $this->assertEquals(0, $by['other']);

        $total = $this->actingAs($this->user)->get(route('tenant.analytics.index'))->viewData('collectedTotal');
        $this->assertEquals(220, $total);
    }

    public function test_excludes_payments_outside_the_range(): void
    {
        $o = $this->order();
        $this->pay($o, 'cash', 100);                              // in range (now)
        $this->pay($o, 'cash', 999, now()->subDays(60)->toDateTimeString()); // out of default 30d range

        $this->assertEquals(100, $this->collected()['cash']);
    }

    public function test_is_tenant_isolated(): void
    {
        $mine = $this->order();
        $this->pay($mine, 'cash', 100);

        $other = Tenant::create(['store_name' => 'Other']);
        $theirs = $this->order($other->id);
        $this->pay($theirs, 'cash', 500);

        $this->assertEquals(100, $this->collected()['cash']); // only my tenant's payments
    }
}
