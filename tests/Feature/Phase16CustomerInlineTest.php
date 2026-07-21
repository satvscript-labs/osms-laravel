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
 * Session 3 — FT-Customers C-b: inline auto-register in the order builder. An
 * order can be placed for an existing customer (customer_id) OR by supplying a
 * new name + phone, which find-or-creates the customer on submit (no separate
 * registration step). Existing phone reuses that customer.
 */
class Phase16CustomerInlineTest extends TestCase
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

    public function test_order_with_existing_customer_still_works(): void
    {
        $item = $this->makeItem();
        $customer = Customer::create(['tenant_id' => $this->user->tenant_id, 'name' => 'Rahul', 'phone' => '+91 9876543210']);

        $this->actingAs($this->user)->post(route('tenant.orders.store'), [
            'customer_id' => $customer->id,
            'items' => [['inventory_id' => $item->id, 'quantity' => 1]],
        ])->assertRedirect();

        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseHas('orders', ['customer_id' => $customer->id]);
    }

    public function test_inline_creates_a_new_customer_and_attaches_the_order(): void
    {
        $item = $this->makeItem();

        $this->actingAs($this->user)->post(route('tenant.orders.store'), [
            'customer_name' => 'Walk In Wanda',
            'customer_country_code' => '+91',
            'customer_phone' => '90000 12345',
            'customer_consent' => 1,
            'items' => [['inventory_id' => $item->id, 'quantity' => 1]],
        ])->assertRedirect();

        // Customer created with the normalised phone, and the order links to it.
        $this->assertDatabaseHas('customers', [
            'name' => 'Walk In Wanda',
            'phone' => '+91 9000012345',
            'tenant_id' => $this->user->tenant_id,
        ]);
        $customer = Customer::where('phone', '+91 9000012345')->first();
        $this->assertDatabaseHas('orders', ['customer_id' => $customer->id]);
    }

    public function test_inline_reuses_an_existing_customer_by_phone(): void
    {
        $item = $this->makeItem();
        $existing = Customer::create(['tenant_id' => $this->user->tenant_id, 'name' => 'Rahul', 'phone' => '+91 9000012345']);

        $this->actingAs($this->user)->post(route('tenant.orders.store'), [
            // Same phone, different typed name — must reuse, not duplicate or rename.
            'customer_name' => 'Different Name',
            'customer_country_code' => '+91',
            'customer_phone' => '90000 12345',
            'customer_consent' => 1,
            'items' => [['inventory_id' => $item->id, 'quantity' => 1]],
        ])->assertRedirect();

        $this->assertDatabaseCount('customers', 1);
        $this->assertSame('Rahul', $existing->fresh()->name); // name untouched
        $this->assertDatabaseHas('orders', ['customer_id' => $existing->id]);
    }

    public function test_order_requires_a_customer_id_or_a_new_name(): void
    {
        $item = $this->makeItem();

        $this->actingAs($this->user)->post(route('tenant.orders.store'), [
            'items' => [['inventory_id' => $item->id, 'quantity' => 1]],
        ])->assertSessionHasErrors('customer_id');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_inline_new_customer_requires_a_phone(): void
    {
        $item = $this->makeItem();

        $this->actingAs($this->user)->post(route('tenant.orders.store'), [
            'customer_name' => 'No Phone',
            'items' => [['inventory_id' => $item->id, 'quantity' => 1]],
        ])->assertSessionHasErrors('customer_phone');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_inline_new_customer_phone_format_is_validated(): void
    {
        $item = $this->makeItem();

        $this->actingAs($this->user)->post(route('tenant.orders.store'), [
            'customer_name' => 'Bad Phone',
            'customer_country_code' => '+91',
            'customer_phone' => 'abc',
            'items' => [['inventory_id' => $item->id, 'quantity' => 1]],
        ])->assertSessionHasErrors('customer_phone');

        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_cannot_place_order_for_another_tenants_customer(): void
    {
        $item = $this->makeItem();

        $other = Tenant::create(['store_name' => 'Other']);
        $foreign = Customer::create(['tenant_id' => $other->id, 'name' => 'Theirs', 'phone' => '+91 9111100000']);

        $this->actingAs($this->user)->post(route('tenant.orders.store'), [
            'customer_id' => $foreign->id,
            'items' => [['inventory_id' => $item->id, 'quantity' => 1]],
        ])->assertNotFound();

        $this->assertDatabaseCount('orders', 0);
    }
}
