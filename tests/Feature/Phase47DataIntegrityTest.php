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
 * DATA-03 — an inventory item referenced by any past order must never be hard-
 * deleted (order_items.inventory_id is nullOnDelete, which would rewrite the
 * historical receipt line to "Custom item"). It stays archived; the nightly purge
 * skips it. Unreferenced items purge as normal.
 */
class Phase47DataIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['store_name' => 'Data Optical', 'tax_id' => 'G', 'address' => 'Indore']);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'store_admin']);
    }

    private function item(): Inventory
    {
        return Inventory::create([
            'tenant_id' => $this->tenant->id, 'sku' => 'SKU-' . uniqid(), 'barcode' => (string) random_int(100000000000, 999999999999),
            'item_type' => 'frame', 'brand' => 'Ray-Ban', 'model_name' => 'Aviator',
            'cost_price' => 100, 'selling_price' => 500, 'stock_qty' => 5, 'min_alert_qty' => 1,
        ]);
    }

    private function deliveredOrderFor(Inventory $item): Order
    {
        $customer = Customer::create(['tenant_id' => $this->tenant->id, 'name' => 'C', 'phone' => '+91 90000' . random_int(10000, 99999)]);
        $order = Order::create([
            'tenant_id' => $this->tenant->id, 'customer_id' => $customer->id,
            'status' => 'delivered', 'fulfillment_type' => 'instant',
            'subtotal' => 500, 'total_amount' => 500, 'advance_paid' => 500,
        ]);
        $order->items()->create(['inventory_id' => $item->id, 'quantity' => 1, 'unit_price' => 500, 'list_price' => 500]);

        return $order;
    }

    public function test_force_delete_is_blocked_for_an_item_with_order_history(): void
    {
        $item = $this->item();
        $this->deliveredOrderFor($item);
        $item->delete(); // archive

        $this->actingAs($this->user)
            ->delete(route('tenant.inventory.force-delete', $item))
            ->assertRedirect();

        // Still present (archived), not hard-deleted.
        $this->assertNotNull(Inventory::withTrashed()->find($item->id));
    }

    public function test_force_delete_succeeds_for_an_unreferenced_item(): void
    {
        $item = $this->item();
        $item->delete();

        $this->actingAs($this->user)
            ->delete(route('tenant.inventory.force-delete', $item))
            ->assertRedirect(route('tenant.inventory.trash'));

        $this->assertNull(Inventory::withTrashed()->find($item->id));
    }

    public function test_purge_skips_referenced_items_but_removes_unreferenced_ones(): void
    {
        $referenced = $this->item();
        $this->deliveredOrderFor($referenced);
        $referenced->delete();

        $unreferenced = $this->item();
        $unreferenced->delete();

        // Age both archives past the 30-day window.
        Inventory::withTrashed()->update(['deleted_at' => now()->subDays(40)]);

        $this->artisan('model:purge-trashed')->assertExitCode(0);

        $this->assertNotNull(Inventory::withTrashed()->find($referenced->id), 'A referenced item must survive the purge.');
        $this->assertNull(Inventory::withTrashed()->find($unreferenced->id), 'An unreferenced item should be purged.');
    }
}
