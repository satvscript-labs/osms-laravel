<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PRIV-01 — consent capture for customer PII + health data (DPDP).
 *
 * A store must be able to record (a) that the customer consented to storing their
 * details, and (b) that they opted in to WhatsApp messaging. Recording is optional
 * but must persist correctly, preserve the original consent date on re-save, and be
 * capturable at the counter (inline walk-in add) without overwriting an existing
 * customer.
 */
class Phase40ConsentTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['store_name' => 'Consent Optical', 'tax_id' => 'G', 'address' => 'Pune']);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'store_admin']);
    }

    public function test_creating_a_customer_with_consent_records_the_timestamp_and_optin(): void
    {
        $this->actingAs($this->user)->post(route('tenant.customers.store'), [
            'name' => 'Rahul', 'country_code' => '+91', 'phone' => '9876500001',
            'data_consent' => '1', 'whatsapp_opt_in' => '1',
        ])->assertRedirect();

        $customer = Customer::first();
        $this->assertNotNull($customer->data_consent_at);
        $this->assertTrue($customer->whatsapp_opt_in);
        $this->assertTrue($customer->hasDataConsent());
    }

    public function test_creating_a_customer_without_consent_is_rejected(): void
    {
        // Consent is mandatory — saving without it fails validation.
        $this->actingAs($this->user)->post(route('tenant.customers.store'), [
            'name' => 'Priya', 'country_code' => '+91', 'phone' => '9876500002',
        ])->assertSessionHasErrors('data_consent');

        $this->assertSame(0, Customer::count());
    }

    public function test_editing_to_add_consent_stamps_the_date(): void
    {
        $customer = Customer::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Amit', 'phone' => '+91 9876500003',
        ]);
        $this->assertNull($customer->data_consent_at);

        $this->actingAs($this->user)->put(route('tenant.customers.update', $customer), [
            'name' => 'Amit', 'country_code' => '+91', 'phone' => '9876500003',
            'data_consent' => '1',
        ])->assertRedirect();

        $this->assertNotNull($customer->fresh()->data_consent_at);
    }

    public function test_resaving_preserves_the_original_consent_date(): void
    {
        $original = now()->subDays(30);
        $customer = Customer::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Sara', 'phone' => '+91 9876500004',
            'data_consent_at' => $original,
        ]);

        $this->actingAs($this->user)->put(route('tenant.customers.update', $customer), [
            'name' => 'Sara Khan', 'country_code' => '+91', 'phone' => '9876500004',
            'data_consent' => '1',
        ])->assertRedirect();

        $this->assertEquals(
            $original->toDateString(),
            $customer->fresh()->data_consent_at->toDateString(),
            'The original consent date must not be rewritten on re-save.'
        );
    }

    public function test_saving_without_consent_is_rejected_on_update(): void
    {
        // Consent is mandatory, so an edit cannot save with it unchecked — the
        // existing consent stays intact rather than being silently cleared.
        $consentAt = now()->subDay();
        $customer = Customer::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Dev', 'phone' => '+91 9876500005',
            'data_consent_at' => $consentAt, 'whatsapp_opt_in' => true,
        ]);

        $this->actingAs($this->user)->put(route('tenant.customers.update', $customer), [
            'name' => 'Dev', 'country_code' => '+91', 'phone' => '9876500005',
            // no data_consent posted = unchecked
        ])->assertSessionHasErrors('data_consent');

        $customer->refresh();
        $this->assertNotNull($customer->data_consent_at, 'Existing consent must not be cleared by a rejected save.');
    }

    public function test_inline_walkin_add_captures_consent_for_a_new_customer(): void
    {
        $item = Inventory::create([
            'tenant_id' => $this->tenant->id, 'sku' => 'SKU-C1', 'barcode' => '100000000001',
            'item_type' => 'frame', 'brand' => 'B', 'model_name' => 'M',
            'cost_price' => 100, 'selling_price' => 500, 'stock_qty' => 5, 'min_alert_qty' => 1,
        ]);

        // The builder posts the national number + a separate country code; the
        // controller normalises them to the stored "+91 …" shape.
        $this->actingAs($this->user)->post(route('tenant.orders.store'), [
            'customer_name' => 'Walkin Wanda', 'customer_phone' => '9876500006', 'customer_country_code' => '+91',
            'customer_consent' => 1, 'customer_whatsapp_opt_in' => 1,
            'items' => [['inventory_id' => $item->id, 'quantity' => 1]],
        ])->assertRedirect();

        $customer = Customer::where('phone', '+91 9876500006')->first();
        $this->assertNotNull($customer);
        $this->assertNotNull($customer->data_consent_at);
        $this->assertTrue($customer->whatsapp_opt_in);
    }

    public function test_inline_walkin_add_does_not_overwrite_an_existing_customers_consent(): void
    {
        // Existing customer with NO consent on file.
        $existing = Customer::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Existing Ed', 'phone' => '+91 9876500007',
        ]);
        $item = Inventory::create([
            'tenant_id' => $this->tenant->id, 'sku' => 'SKU-C2', 'barcode' => '100000000002',
            'item_type' => 'frame', 'brand' => 'B', 'model_name' => 'M',
            'cost_price' => 100, 'selling_price' => 500, 'stock_qty' => 5, 'min_alert_qty' => 1,
        ]);

        // A new walk-in add posting the SAME phone must reuse the customer and NOT
        // stamp consent onto them (firstOrCreate only applies values on create).
        $this->actingAs($this->user)->post(route('tenant.orders.store'), [
            'customer_name' => 'Different Name', 'customer_phone' => '9876500007', 'customer_country_code' => '+91',
            'customer_consent' => 1,
            'items' => [['inventory_id' => $item->id, 'quantity' => 1]],
        ])->assertRedirect();

        $existing->refresh();
        $this->assertNull($existing->data_consent_at, 'Existing customer consent must not be silently set.');
        $this->assertSame('Existing Ed', $existing->name, 'Existing customer name must not be overwritten.');
        $this->assertSame(1, Customer::count(), 'No duplicate customer should be created.');
    }
}
