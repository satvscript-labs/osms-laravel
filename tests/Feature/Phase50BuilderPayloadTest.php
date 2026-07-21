<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PERF-03 — the order builder must not embed the whole customer list in the page
 * (unbounded growth). Customers are searched server-side; only a pre-selected
 * customer is passed through. Inventory stays embedded (bounded by the catalog and
 * needed by the local barcode-scan path).
 */
class Phase50BuilderPayloadTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['store_name' => 'Builder Optical', 'tax_id' => 'G', 'address' => 'Surat']);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'store_admin']);
    }

    public function test_builder_does_not_embed_the_customer_list(): void
    {
        $secret = Customer::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Zzstealthcustomer', 'phone' => '+91 9700000001',
        ]);

        $html = $this->actingAs($this->user)->get(route('tenant.orders.create'))->assertOk()->getContent();

        $this->assertStringNotContainsString('Zzstealthcustomer', $html,
            'The customer list must not be embedded in the order builder payload.');
    }

    public function test_builder_still_preselects_a_customer_passed_by_id(): void
    {
        $customer = Customer::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Preselected Pat', 'phone' => '+91 9700000002',
        ]);

        $this->actingAs($this->user)
            ->get(route('tenant.orders.create', ['customer' => $customer->id]))
            ->assertOk()
            ->assertSee('Preselected Pat');
    }

    public function test_customer_search_endpoint_backs_the_picker(): void
    {
        Customer::create(['tenant_id' => $this->tenant->id, 'name' => 'Searchable Sam', 'phone' => '+91 9700000003']);
        Customer::create(['tenant_id' => $this->tenant->id, 'name' => 'Other Person', 'phone' => '+91 9700000004']);

        $rows = $this->actingAs($this->user)
            ->getJson(route('tenant.customers.index', ['q' => 'Searchable']))
            ->assertOk()
            ->json('customers');

        $this->assertCount(1, $rows);
        $this->assertSame('Searchable Sam', $rows[0]['name']);
    }

    public function test_inventory_is_still_embedded_for_the_scan_path(): void
    {
        Inventory::create([
            'tenant_id' => $this->tenant->id, 'sku' => 'SKU-EMBED-1', 'barcode' => '700000000001',
            'item_type' => 'frame', 'brand' => 'EmbeddedBrand', 'model_name' => 'M',
            'cost_price' => 100, 'selling_price' => 500, 'stock_qty' => 3, 'min_alert_qty' => 1,
        ]);

        $this->actingAs($this->user)->get(route('tenant.orders.create'))
            ->assertOk()
            ->assertSee('EmbeddedBrand');
    }
}
