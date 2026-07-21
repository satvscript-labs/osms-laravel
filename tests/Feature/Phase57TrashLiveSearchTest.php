<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UX-04 — live search on the archive (trash) pages.
 *
 * The important property is that filtering happens in SQL across the WHOLE
 * archive, not client-side over the current page (the same bug class as WEB-02),
 * and that the AJAX path returns just the rows fragment.
 */
class Phase57TrashLiveSearchTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['store_name' => 'Trash Optical', 'address' => 'X']);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'store_admin']);
    }

    private function archivedCustomer(string $name, string $phone): Customer
    {
        $c = Customer::create(['tenant_id' => $this->tenant->id, 'name' => $name, 'phone' => $phone]);
        $c->delete();

        return $c;
    }

    private function archivedItem(string $sku, string $brand): Inventory
    {
        $i = Inventory::create([
            'tenant_id' => $this->tenant->id,
            'sku' => $sku, 'barcode' => (string) random_int(100000000000, 999999999999),
            'item_type' => 'frame', 'brand' => $brand, 'model_name' => 'Aviator',
            'cost_price' => 10, 'selling_price' => 20, 'stock_qty' => 1, 'min_alert_qty' => 1,
        ]);
        $i->delete();

        return $i;
    }

    // ---------------------------------------------------------- customers

    public function test_customer_archive_lists_everything_without_a_search(): void
    {
        $this->archivedCustomer('Rahul Kumar', '+91 9000000001');
        $this->archivedCustomer('Priya Shah', '+91 9000000002');

        $response = $this->actingAs($this->user)->get(route('tenant.customers.trash'));

        $response->assertOk();
        $response->assertSee('Rahul Kumar');
        $response->assertSee('Priya Shah');
    }

    public function test_customer_archive_search_filters_in_sql(): void
    {
        $this->archivedCustomer('Rahul Kumar', '+91 9000000001');
        $this->archivedCustomer('Priya Shah', '+91 9000000002');

        $response = $this->actingAs($this->user)
            ->get(route('tenant.customers.trash', ['search' => 'Priya']));

        $response->assertOk();
        $response->assertSee('Priya Shah');
        $response->assertDontSee('Rahul Kumar');
    }

    public function test_customer_archive_search_matches_phone_too(): void
    {
        $this->archivedCustomer('Rahul Kumar', '+91 9000000001');
        $this->archivedCustomer('Priya Shah', '+91 9000000002');

        $response = $this->actingAs($this->user)
            ->get(route('tenant.customers.trash', ['search' => '9000000002']));

        $response->assertSee('Priya Shah');
        $response->assertDontSee('Rahul Kumar');
    }

    public function test_the_ajax_request_returns_only_the_rows_fragment(): void
    {
        $this->archivedCustomer('Rahul Kumar', '+91 9000000001');

        $response = $this->actingAs($this->user)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->get(route('tenant.customers.trash', ['search' => 'Rahul']));

        $response->assertOk();
        $response->assertSee('Rahul Kumar');
        // A fragment, not the whole page — no layout chrome.
        $response->assertDontSee('<html', false);
    }

    public function test_a_search_with_no_matches_says_so(): void
    {
        $this->archivedCustomer('Rahul Kumar', '+91 9000000001');

        $this->actingAs($this->user)
            ->get(route('tenant.customers.trash', ['search' => 'zzzznope']))
            ->assertOk()
            ->assertSee('No matches');
    }

    // ---------------------------------------------------------- inventory

    public function test_inventory_archive_search_filters_in_sql(): void
    {
        $this->archivedItem('SKU-RAY-1', 'Ray-Ban');
        $this->archivedItem('SKU-OAK-2', 'Oakley');

        $response = $this->actingAs($this->user)
            ->get(route('tenant.inventory.trash', ['search' => 'Oakley']));

        $response->assertOk();
        $response->assertSee('Oakley');
        $response->assertDontSee('Ray-Ban');
    }

    public function test_inventory_archive_search_matches_sku(): void
    {
        $this->archivedItem('SKU-RAY-1', 'Ray-Ban');
        $this->archivedItem('SKU-OAK-2', 'Oakley');

        $response = $this->actingAs($this->user)
            ->get(route('tenant.inventory.trash', ['search' => 'SKU-OAK-2']));

        $response->assertSee('Oakley');
        $response->assertDontSee('Ray-Ban');
    }

    // ---------------------------------------------------------- isolation

    public function test_archive_search_stays_within_the_tenant(): void
    {
        $other = Tenant::create(['store_name' => 'Rival Optical', 'address' => 'Y']);
        $foreign = Customer::create(['tenant_id' => $other->id, 'name' => 'Foreign Person', 'phone' => '+91 9111100000']);
        $foreign->delete();

        $this->actingAs($this->user)
            ->get(route('tenant.customers.trash', ['search' => 'Foreign']))
            ->assertOk()
            ->assertDontSee('Foreign Person');
    }
}
