<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 6.4 — FT-LocalItems: off-inventory / local line items. A line may be a
 * free-text custom item (name + price + qty) with no `inventory_id`: it isn't
 * stock-tracked (no decrement, no oversell guard, no StockMovement), its
 * `list_price` mirrors its `unit_price`, its description is sentence-cased, and
 * its revenue counts in analytics while its (absent) cost keeps it out of COGS
 * under a distinct "Local / custom item" bucket. Multiple custom lines on one
 * order must survive an edit (the null-inventory_id key-collision case).
 */
class Phase30LocalItemsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $tenant = Tenant::create(['store_name' => 'Test Optical', 'tax_id' => 'GST123', 'address' => 'Mumbai']);
        $this->user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'store_admin']);
    }

    private function makeItem(float $price = 250, int $stock = 10, float $cost = 50): Inventory
    {
        return Inventory::create([
            'tenant_id' => $this->user->tenant_id,
            'sku' => 'SKU-' . uniqid(), 'barcode' => (string) random_int(100000000000, 999999999999),
            'item_type' => 'frame', 'brand' => 'Ray-Ban', 'model_name' => 'Aviator',
            'cost_price' => $cost, 'selling_price' => $price, 'stock_qty' => $stock, 'min_alert_qty' => 2,
        ]);
    }

    private function customer(): Customer
    {
        return Customer::create([
            'tenant_id' => $this->user->tenant_id,
            'name' => 'Rahul', 'phone' => '+91 90000' . random_int(10000, 99999),
        ]);
    }

    private function place(array $payload): Order
    {
        $this->actingAs($this->user)->post(route('tenant.orders.store'), $payload)->assertRedirect();

        return Order::latest()->first();
    }

    // ---- Storing custom lines ----

    public function test_order_stores_a_catalog_line_and_a_custom_line_together(): void
    {
        $item = $this->makeItem(250, 10);
        $order = $this->place([
            'customer_id' => $this->customer()->id,
            'items' => [
                ['inventory_id' => $item->id, 'quantity' => 2],                 // catalog: 500
                ['description' => 'Lens cleaning kit', 'quantity' => 1, 'unit_price' => 120], // custom
            ],
        ]);

        $this->assertEquals(620, $order->subtotal);
        $this->assertEquals(620, $order->total_amount);
        $this->assertCount(2, $order->items);

        // Catalog line — tracked, list snapshot captured.
        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id, 'inventory_id' => $item->id, 'description' => null,
            'unit_price' => 250, 'list_price' => 250,
        ]);
        // Custom line — no inventory_id, list_price mirrors unit_price.
        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id, 'inventory_id' => null, 'description' => 'Lens cleaning kit',
            'unit_price' => 120, 'list_price' => 120, 'quantity' => 1,
        ]);
    }

    public function test_custom_description_is_sentence_cased_without_mangling_intentional_caps(): void
    {
        $order = $this->place([
            'customer_id' => $this->customer()->id,
            'items' => [
                ['description' => 'lens cleaning kit', 'quantity' => 1, 'unit_price' => 100], // → capitalized
                ['description' => 'UV400 clip-on', 'quantity' => 1, 'unit_price' => 100],      // → untouched
            ],
        ]);

        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'description' => 'Lens cleaning kit']);
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'description' => 'UV400 clip-on']);
    }

    public function test_custom_line_never_creates_a_stock_movement_and_leaves_stock_untouched(): void
    {
        $item = $this->makeItem(250, 10);
        $order = $this->place([
            'customer_id' => $this->customer()->id,
            'items' => [
                ['inventory_id' => $item->id, 'quantity' => 2],                     // draws 2
                ['description' => 'Buy-in strap', 'quantity' => 3, 'unit_price' => 80], // draws nothing
            ],
        ]);

        // Exactly one movement — for the catalog item; the custom line logs nothing.
        $this->assertEquals(1, StockMovement::where('order_id', $order->id)->count());
        $this->assertEquals(8, $item->fresh()->stock_qty); // 10 − 2 (custom qty 3 ignored)
    }

    // ---- Editing with multiple custom lines (the null-key collision case) ----

    public function test_two_custom_lines_on_one_order_survive_an_edit(): void
    {
        // A pending order (no fulfillment_type → special/pending → editable) with two
        // custom lines. Keyed by inventory_id both would be `null` and collide; the
        // controller partitions them out of stock reconciliation so both persist.
        $order = $this->place([
            'customer_id' => $this->customer()->id,
            'items' => [
                ['description' => 'Strap A', 'quantity' => 1, 'unit_price' => 100],
                ['description' => 'Strap B', 'quantity' => 1, 'unit_price' => 200],
            ],
        ]);
        $this->assertCount(2, $order->items);
        $this->assertEquals(300, $order->subtotal);

        // Edit: bump both quantities.
        $this->actingAs($this->user)->put(route('tenant.orders.update', $order), [
            'items' => [
                ['description' => 'Strap A', 'quantity' => 2, 'unit_price' => 100], // 200
                ['description' => 'Strap B', 'quantity' => 3, 'unit_price' => 200], // 600
            ],
        ])->assertRedirect();

        $order->refresh();
        $this->assertCount(2, $order->items);                                  // both survive
        $this->assertEquals(2, $order->items->whereNull('inventory_id')->count());
        $this->assertEquals(800, $order->subtotal);
        $this->assertEquals(0, StockMovement::where('order_id', $order->id)->count()); // never touched stock
    }

    // ---- Validation ----

    public function test_a_line_with_neither_inventory_nor_description_is_rejected(): void
    {
        $this->actingAs($this->user)->post(route('tenant.orders.store'), [
            'customer_id' => $this->customer()->id,
            'items' => [['quantity' => 1]],
        ])->assertSessionHasErrors('items');
    }

    public function test_a_custom_line_without_a_price_is_rejected(): void
    {
        $this->actingAs($this->user)->post(route('tenant.orders.store'), [
            'customer_id' => $this->customer()->id,
            'items' => [['description' => 'No price item', 'quantity' => 1]],
        ])->assertSessionHasErrors('items');
    }

    // ---- Analytics: revenue counts, COGS excludes, distinct bucket ----

    public function test_custom_line_revenue_counts_but_is_excluded_from_cogs_under_its_own_bucket(): void
    {
        $item = $this->makeItem(250, 10, cost: 50);
        // Instant sale → created delivered → counts in analytics immediately.
        $this->place([
            'customer_id' => $this->customer()->id,
            'fulfillment_type' => 'instant',
            'items' => [
                ['inventory_id' => $item->id, 'quantity' => 1],                    // rev 250, cost 50
                ['description' => 'Local strap', 'quantity' => 1, 'unit_price' => 100], // rev 100, no cost
            ],
        ]);

        $response = $this->actingAs($this->user)->get(route('tenant.analytics.index'));
        $response->assertOk();

        $stats = $response->viewData('stats');
        $this->assertEquals(350, $stats['revenue']);  // both lines' revenue
        $this->assertEquals(50, $stats['cogs']);      // only the catalog item's cost
        $this->assertEquals(200, $stats['profit']);   // 250 costed revenue − 50 cogs

        $brands = collect($response->viewData('topBrands'));
        $local = $brands->firstWhere('brand', 'Local / custom item');
        $this->assertNotNull($local, 'custom lines get a distinct analytics bucket');
        $this->assertEquals(100, $local['revenue']);
        $this->assertEquals(250, $brands->firstWhere('brand', 'Ray-Ban')['revenue']);
    }

    // ---- Tenant isolation ----

    public function test_a_custom_line_is_scoped_to_its_tenant(): void
    {
        $order = $this->place([
            'customer_id' => $this->customer()->id,
            'items' => [['description' => 'Tenant A strap', 'quantity' => 1, 'unit_price' => 100]],
        ]);

        // The order (and its custom line) belongs to tenant A.
        $this->assertSame($this->user->tenant_id, $order->tenant_id);

        // A second tenant's admin can't see it (TenantScope).
        $otherTenant = Tenant::create(['store_name' => 'Other Optical', 'tax_id' => 'GST999', 'address' => 'Delhi']);
        $otherUser = User::factory()->create(['tenant_id' => $otherTenant->id, 'role' => 'store_admin']);

        $this->actingAs($otherUser);
        $this->assertNull(Order::find($order->id));
    }
}
