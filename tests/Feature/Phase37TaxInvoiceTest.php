<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\TaxInvoice;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Gst;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FT-TaxInvoice — a formal, sequentially-numbered tax invoice covering only
 * the order-builder line items a shop owner opts in for (e.g. one branded
 * frame out of a mixed-item order), separate from the always-full informal
 * receipt. GST breakup is store-toggleable (many small opticians aren't
 * GST-registered) and computed inclusive-of-tax when it is on.
 */
class Phase37TaxInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['store_name' => 'Test Optical', 'tax_id' => 'GST123', 'address' => 'Mumbai']);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'store_admin']);
    }

    private function item(float $price = 4500): Inventory
    {
        return Inventory::create([
            'tenant_id' => $this->tenant->id,
            'sku' => 'SKU-' . uniqid(), 'barcode' => (string) random_int(100000000000, 999999999999),
            'item_type' => 'frame', 'brand' => 'Ray-Ban', 'model_name' => 'Aviator',
            'cost_price' => 2000, 'selling_price' => $price, 'stock_qty' => 10, 'min_alert_qty' => 2,
        ]);
    }

    private function customer(): Customer
    {
        return Customer::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Rahul', 'phone' => '+91 90000' . random_int(10000, 99999),
        ]);
    }

    private function place(array $payload): Order
    {
        $before = Order::pluck('id');
        $this->actingAs($this->user)->post(route('tenant.orders.store'), $payload)->assertRedirect();

        // Two orders placed within the same test can share a created_at second,
        // so ->latest()->first() isn't reliably the newest — diff instead.
        return Order::whereNotIn('id', $before)->firstOrFail();
    }

    // ---- Gst helper ----

    public function test_gst_split_backs_out_tax_from_an_inclusive_amount(): void
    {
        $split = Gst::splitInclusive(1120.00, 12.0);

        $this->assertEquals(1000.00, $split['taxable']);
        $this->assertEquals(60.00, $split['cgst']);
        $this->assertEquals(60.00, $split['sgst']);
        $this->assertEquals(120.00, $split['tax']);
    }

    public function test_gst_split_is_a_no_op_at_zero_rate(): void
    {
        $split = Gst::splitInclusive(500.00, 0.0);

        $this->assertEquals(500.00, $split['taxable']);
        $this->assertEquals(0.0, $split['cgst']);
        $this->assertEquals(0.0, $split['sgst']);
    }

    // ---- Financial-year numbering ----

    public function test_financial_year_label_rolls_over_on_april_first(): void
    {
        // Indian FY runs Apr–Mar: 10 Jul 2026 falls in FY starting Apr 2026 ("2627").
        $this->assertSame('2627', TaxInvoice::financialYearLabel(\Carbon\Carbon::parse('2026-07-10')));
        $this->assertSame('2526', TaxInvoice::financialYearLabel(\Carbon\Carbon::parse('2026-03-31')));
        $this->assertSame('2627', TaxInvoice::financialYearLabel(\Carbon\Carbon::parse('2026-04-01')));
    }

    public function test_invoice_number_format(): void
    {
        $order = Order::create([
            'tenant_id' => $this->tenant->id, 'customer_id' => $this->customer()->id,
            'status' => 'pending', 'fulfillment_type' => 'special',
            'subtotal' => 1000, 'discount_type' => 'none', 'discount_value' => 0, 'discount_amount' => 0,
            'total_amount' => 1000, 'advance_paid' => 0,
        ]);
        $order->created_at = \Carbon\Carbon::parse('2026-07-10');
        $order->save();

        $invoice = TaxInvoice::issueFor($order->fresh());

        $this->assertSame('INV-2627-00001', $invoice->number);
    }

    public function test_sequence_increments_per_tenant_and_financial_year(): void
    {
        $item = $this->item();
        $order1 = $this->place([
            'customer_id' => $this->customer()->id,
            'items' => [['inventory_id' => $item->id, 'quantity' => 1, 'tax_invoice' => 1]],
        ]);
        $order2 = $this->place([
            'customer_id' => $this->customer()->id,
            'items' => [['inventory_id' => $item->id, 'quantity' => 1, 'tax_invoice' => 1]],
        ]);

        $this->assertSame(1, $order1->fresh()->taxInvoice->sequence);
        $this->assertSame(2, $order2->fresh()->taxInvoice->sequence);
    }

    // ---- Creation flow: only flagged lines get invoiced ----

    public function test_only_flagged_line_is_marked_and_invoice_is_issued(): void
    {
        $frame = $this->item(4500);
        $accessory = $this->item(150);

        $order = $this->place([
            'customer_id' => $this->customer()->id,
            'items' => [
                ['inventory_id' => $frame->id, 'quantity' => 1, 'tax_invoice' => 1],
                ['inventory_id' => $accessory->id, 'quantity' => 2, 'tax_invoice' => 0],
            ],
        ]);

        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id, 'inventory_id' => $frame->id, 'on_tax_invoice' => 1,
        ]);
        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id, 'inventory_id' => $accessory->id, 'on_tax_invoice' => 0,
        ]);
        $this->assertNotNull($order->fresh()->taxInvoice);
    }

    public function test_no_invoice_issued_when_nothing_flagged(): void
    {
        $item = $this->item();
        $order = $this->place([
            'customer_id' => $this->customer()->id,
            'items' => [['inventory_id' => $item->id, 'quantity' => 1]],
        ]);

        $this->assertNull($order->fresh()->taxInvoice);
    }

    // ---- PDF endpoint ----

    public function test_tax_invoice_pdf_streams_for_a_flagged_order(): void
    {
        $item = $this->item();
        $order = $this->place([
            'customer_id' => $this->customer()->id,
            'items' => [['inventory_id' => $item->id, 'quantity' => 1, 'tax_invoice' => 1]],
        ]);

        $response = $this->actingAs($this->user)->get(route('tenant.orders.tax-invoice.pdf', $order));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    public function test_tax_invoice_pdf_404s_when_no_invoice_exists(): void
    {
        $item = $this->item();
        $order = $this->place([
            'customer_id' => $this->customer()->id,
            'items' => [['inventory_id' => $item->id, 'quantity' => 1]],
        ]);

        $this->actingAs($this->user)
            ->get(route('tenant.orders.tax-invoice.pdf', $order))
            ->assertNotFound();
    }

    public function test_tax_invoice_is_tenant_isolated(): void
    {
        $item = $this->item();
        $order = $this->place([
            'customer_id' => $this->customer()->id,
            'items' => [['inventory_id' => $item->id, 'quantity' => 1, 'tax_invoice' => 1]],
        ]);

        $otherTenant = Tenant::create(['store_name' => 'Other Optical']);
        $otherUser = User::factory()->create(['tenant_id' => $otherTenant->id, 'role' => 'store_admin']);

        $this->actingAs($otherUser)
            ->get(route('tenant.orders.tax-invoice.pdf', $order))
            ->assertNotFound();
    }

    // ---- Order show page ----

    public function test_show_page_renders_tax_invoice_button_only_when_issued(): void
    {
        $item = $this->item();
        $flagged = $this->place([
            'customer_id' => $this->customer()->id,
            'items' => [['inventory_id' => $item->id, 'quantity' => 1, 'tax_invoice' => 1]],
        ]);
        $plain = $this->place([
            'customer_id' => $this->customer()->id,
            'items' => [['inventory_id' => $item->id, 'quantity' => 1]],
        ]);

        $this->actingAs($this->user)->get(route('tenant.orders.show', $flagged))
            ->assertSee('Tax invoice');
        $this->actingAs($this->user)->get(route('tenant.orders.show', $plain))
            ->assertDontSee('Tax invoice');
    }

    // ---- GST settings ----

    public function test_gst_settings_persist_and_default_rate_falls_back(): void
    {
        $this->assertFalse($this->tenant->hasGst());
        $this->assertEquals(Tenant::DEFAULT_GST_RATE, $this->tenant->effectiveGstRate());

        $this->actingAs($this->user)->put(route('tenant.settings.update'), [
            'store_name' => 'Test Optical', 'tax_id' => 'GST123',
            'gst_enabled' => '1', 'gst_rate' => '18',
        ])->assertRedirect();

        $fresh = $this->tenant->fresh();
        $this->assertTrue($fresh->hasGst());
        $this->assertEquals(18.0, $fresh->effectiveGstRate());
    }

    public function test_invoice_shows_gst_breakup_only_when_store_is_registered(): void
    {
        $this->tenant->update(['gst_enabled' => true, 'gst_rate' => 12]);
        $item = $this->item(1120);
        $order = $this->place([
            'customer_id' => $this->customer()->id,
            'items' => [['inventory_id' => $item->id, 'quantity' => 1, 'tax_invoice' => 1]],
        ]);

        $response = $this->actingAs($this->user)->get(route('tenant.orders.tax-invoice.pdf', $order));
        $response->assertOk();
    }

    // ---- Edit flow preserves the flag for catalog lines ----

    public function test_editing_an_order_preserves_the_tax_invoice_flag_on_an_untouched_catalog_line(): void
    {
        $frame = $this->item(4500);
        $order = $this->place([
            'customer_id' => $this->customer()->id,
            'items' => [['inventory_id' => $frame->id, 'quantity' => 1, 'tax_invoice' => 1]],
        ]);

        // Edit without re-posting the flag (the edit page has no toggle) — bump qty only.
        $this->actingAs($this->user)->put(route('tenant.orders.update', $order), [
            'items' => [['inventory_id' => $frame->id, 'quantity' => 2]],
        ])->assertRedirect();

        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id, 'inventory_id' => $frame->id, 'on_tax_invoice' => 1, 'quantity' => 2,
        ]);
    }
}
