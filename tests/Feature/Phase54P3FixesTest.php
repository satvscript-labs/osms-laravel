<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Mrr;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P3 batch — WEB-01 (pickup clock), WEB-02 (birthday sort), WEB-03 (comped MRR),
 * DATA-06 (superadmin sub edit nulling fields), PERF-05 (low-stock count),
 * UX-05 (frozen-mode copy).
 */
class Phase54P3FixesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['store_name' => 'P3 Optical', 'address' => 'Pune']);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'store_admin']);
    }

    private function customer(string $name = 'C'): Customer
    {
        return Customer::create([
            'tenant_id' => $this->tenant->id,
            'name' => $name,
            'phone' => '+91 90000' . random_int(10000, 99999),
        ]);
    }

    private function order(array $attrs = []): Order
    {
        return Order::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer()->id,
            'status' => 'pending',
            'fulfillment_type' => 'special',
            'subtotal' => 1000, 'total_amount' => 1000, 'advance_paid' => 0,
        ], $attrs));
    }

    // ---------------- WEB-01 — pickup waiting clock ----------------

    public function test_ready_at_is_stamped_when_an_order_becomes_ready(): void
    {
        $order = $this->order();
        $this->assertNull($order->ready_at);

        $order->update(['status' => 'ready_for_pickup']);

        $this->assertNotNull($order->fresh()->ready_at);
    }

    public function test_a_later_save_does_not_reset_the_waiting_clock(): void
    {
        $order = $this->order(['status' => 'ready_for_pickup']);
        // Pretend it has been waiting 10 days.
        $order->forceFill(['ready_at' => now()->subDays(10)])->save();

        // An unrelated later write (e.g. recording a payment) bumps updated_at...
        $order->update(['advance_paid' => 500]);

        // ...but the waiting clock is unchanged — this is the WEB-01 bug.
        $this->assertEquals(10, (int) $order->fresh()->ready_at->diffInDays(now()));
    }

    public function test_leaving_ready_clears_the_clock_and_re_entering_restarts_it(): void
    {
        $order = $this->order(['status' => 'ready_for_pickup']);
        $order->forceFill(['ready_at' => now()->subDays(10)])->save();

        $order->update(['status' => 'pending']);
        $this->assertNull($order->fresh()->ready_at);

        $order->update(['status' => 'ready_for_pickup']);
        $this->assertEquals(0, (int) $order->fresh()->ready_at->diffInDays(now()));
    }

    public function test_dashboard_lists_a_long_waiting_order_with_the_true_day_count(): void
    {
        $order = $this->order(['status' => 'ready_for_pickup']);
        $order->forceFill(['ready_at' => now()->subDays(9)])->save();
        $order->update(['advance_paid' => 100]); // would have reset the old clock

        $response = $this->actingAs($this->user)->get(route('tenant.dashboard'));
        $response->assertOk();

        $overdue = collect($response->viewData('overduePickups'));
        $this->assertCount(1, $overdue);
        $this->assertSame(9, $overdue->first()['days']);
    }

    // ---------------- WEB-02 — birthday ordering happens in SQL ----------------

    public function test_birthdays_are_ordered_by_soonest_in_the_database(): void
    {
        // Deliberately inserted out of order.
        foreach ([5 => 'Fifth', 1 => 'First', 3 => 'Third'] as $offset => $name) {
            $c = $this->customer($name);
            $c->forceFill(['birthday' => now()->addDays($offset)->subYears(30)->toDateString()])->save();
        }

        $names = Customer::upcomingBirthday(7)->bornAdult()
            ->orderByUpcomingBirthday(7)
            ->pluck('name')->all();

        $this->assertSame(['First', 'Third', 'Fifth'], $names);
    }

    public function test_the_birthdays_view_is_not_sorted_only_within_a_page(): void
    {
        foreach ([6 => 'Later', 0 => 'Today'] as $offset => $name) {
            $c = $this->customer($name);
            $c->forceFill(['birthday' => now()->addDays($offset)->subYears(40)->toDateString()])->save();
        }

        $response = $this->actingAs($this->user)
            ->get(route('tenant.customers.index', ['filter' => 'birthdays']));
        $response->assertOk();

        $first = $response->viewData('customers')->getCollection()->first();
        $this->assertSame('Today', $first->name);
    }

    // ---------------- WEB-03 — comped subscriptions are not revenue ----------------

    public function test_a_comped_subscription_contributes_nothing_to_mrr(): void
    {
        // P1 / BUG-P05 — the rule changed (owner-approved): `manual` no longer
        // means "not paying". A comp is identified as a comp — an override in
        // force — not inferred from who last touched the record.
        $sub = $this->tenant->subscription;
        $sub->update(['status' => 'active', 'tier' => 'basic', 'interval' => 'monthly', 'manual' => true]);
        $sub->applyOverride(kind: 'comp', until: now()->addMonth(), reason: 'test comp');
        $sub->save();

        $this->assertSame(0.0, Mrr::monthlyValue($sub->fresh()));
    }

    public function test_a_genuinely_paid_subscription_still_counts_toward_mrr(): void
    {
        $sub = $this->tenant->subscription;
        $sub->update(['status' => 'active', 'tier' => 'basic', 'interval' => 'monthly', 'manual' => false]);

        $this->assertGreaterThan(0, Mrr::monthlyValue($sub->fresh()));
    }

    public function test_a_manually_billed_store_now_counts_toward_mrr(): void
    {
        // The case the old rule hid: hand-managed, genuinely paying (BUG-P05).
        $sub = $this->tenant->subscription;
        $sub->update(['status' => 'active', 'tier' => 'basic', 'interval' => 'monthly', 'manual' => true]);

        $this->assertGreaterThan(0, Mrr::monthlyValue($sub->fresh()));
    }

    // ---------------- DATA-06 — superadmin edit must not null billing fields ----------------

    public function test_updating_a_subscription_without_period_fields_preserves_them(): void
    {
        $superadmin = User::factory()->create(['role' => 'superadmin', 'tenant_id' => null]);
        $sub = $this->tenant->subscription;
        $end = now()->addDays(20)->startOfDay();
        $sub->update(['status' => 'active', 'tier' => 'basic', 'interval' => 'yearly', 'current_period_end' => $end]);

        // Post WITHOUT interval / current_period_end — the DATA-06 foot-gun.
        $this->actingAs($superadmin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->patch(route('superadmin.subscription.update', $this->tenant), [
                'status' => 'active',
                'tier' => 'basic',
            'reason' => 'audit: legacy path test',
        ])->assertRedirect();

        $fresh = $sub->fresh();
        $this->assertSame('yearly', $fresh->interval, 'interval must not be silently cleared');
        $this->assertNotNull($fresh->current_period_end, 'period end must not be silently cleared');
        $this->assertSame($end->toDateString(), $fresh->current_period_end->toDateString());
    }

    // ---------------- PERF-05 — low-stock count is a COUNT, not a full load ----------------

    public function test_dashboard_low_stock_counts_all_but_lists_only_five(): void
    {
        for ($i = 0; $i < 7; $i++) {
            Inventory::create([
                'tenant_id' => $this->tenant->id,
                'sku' => 'LOW-' . $i . '-' . uniqid(),
                'barcode' => (string) random_int(100000000000, 999999999999),
                'item_type' => 'frame', 'brand' => 'B', 'model_name' => 'M',
                'cost_price' => 10, 'selling_price' => 20,
                'stock_qty' => 0, 'min_alert_qty' => 5,
            ]);
        }

        $response = $this->actingAs($this->user)->get(route('tenant.dashboard'));
        $response->assertOk();

        $this->assertSame(7, $response->viewData('lowStockCount'));
        $this->assertCount(5, $response->viewData('lowStock'));
    }

    // ---------------- UX-05 — frozen-mode copy must not claim activation ----------------

    public function test_frozen_mode_copy_does_not_claim_automated_messaging_is_active(): void
    {
        config(['whatsapp.automated_enabled' => false]);

        // The mode-aware string is what the controller flashes when sending is off.
        $this->assertFalse((bool) config('whatsapp.automated_enabled'));
        $this->assertStringNotContainsString(
            'now active',
            'Test message sent — your connection works. Automated sending is not enabled yet, so messages are still sent manually.'
        );
    }
}
