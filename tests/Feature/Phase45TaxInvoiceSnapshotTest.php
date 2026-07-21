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
 * BIZ-03 — a tax invoice is immutable once numbered. Its contents are frozen in a
 * snapshot at issue time, so a later order edit or GST-rate change can never rewrite
 * the numbered document. Legacy invoices (no snapshot) still render via a live fallback.
 */
class Phase45TaxInvoiceSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create([
            'store_name' => 'Snap Optical', 'tax_id' => 'GST99', 'address' => 'Nashik',
            'gst_enabled' => true, 'gst_rate' => 12,
        ]);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'store_admin']);
    }

    private function item(float $price = 1120): Inventory
    {
        return Inventory::create([
            'tenant_id' => $this->tenant->id,
            'sku' => 'SKU-' . uniqid(), 'barcode' => (string) random_int(100000000000, 999999999999),
            'item_type' => 'frame', 'brand' => 'Ray-Ban', 'model_name' => 'Aviator',
            'cost_price' => 500, 'selling_price' => $price, 'stock_qty' => 10, 'min_alert_qty' => 1,
        ]);
    }

    private function place(Inventory $item, int $qty = 1): Order
    {
        $customer = Customer::create(['tenant_id' => $this->tenant->id, 'name' => 'Rahul', 'phone' => '+91 90000' . random_int(10000, 99999)]);
        $before = Order::pluck('id');
        $this->actingAs($this->user)->post(route('tenant.orders.store'), [
            'customer_id' => $customer->id,
            'items' => [['inventory_id' => $item->id, 'quantity' => $qty, 'tax_invoice' => 1]],
        ])->assertRedirect();

        return Order::whereNotIn('id', $before)->firstOrFail();
    }

    public function test_issuing_captures_a_content_snapshot(): void
    {
        $order = $this->place($this->item(1120), 1);
        $snap = $order->fresh()->taxInvoice->snapshot;

        $this->assertNotNull($snap);
        $this->assertTrue($snap['has_gst']);
        $this->assertEquals(12.0, $snap['gst_rate']);
        $this->assertSame('Snap Optical', $snap['store']['name']);
        $this->assertCount(1, $snap['lines']);
        $this->assertEquals(1120, $snap['lines'][0]['amount']);
        $this->assertEquals(1120, $snap['totals']['grand']);
        $this->assertEquals(1000, $snap['totals']['taxable']); // 1120 incl. 12% → 1000
    }

    public function test_editing_the_order_after_issue_does_not_change_the_invoice(): void
    {
        $item = $this->item(1120);
        $order = $this->place($item, 1);
        $snapBefore = $order->fresh()->taxInvoice->snapshot;

        // Bump the quantity to 3 after the invoice was issued.
        $this->actingAs($this->user)->put(route('tenant.orders.update', $order), [
            'items' => [['inventory_id' => $item->id, 'quantity' => 3]],
        ])->assertRedirect();

        $snapAfter = $order->fresh()->taxInvoice->snapshot;

        // The order line changed, but the numbered invoice is frozen.
        $this->assertEquals(3, $order->fresh()->items->first()->quantity);
        $this->assertEquals($snapBefore['lines'][0]['qty'], $snapAfter['lines'][0]['qty']);
        $this->assertEquals(1, $snapAfter['lines'][0]['qty']);
        $this->assertEquals(1120, $snapAfter['totals']['grand']);
    }

    public function test_changing_the_gst_rate_after_issue_does_not_change_the_invoice(): void
    {
        $order = $this->place($this->item(1120), 1);
        $rateAtIssue = $order->fresh()->taxInvoice->snapshot['gst_rate'];

        $this->tenant->update(['gst_rate' => 28]);

        $this->assertEquals($rateAtIssue, $order->fresh()->taxInvoice->snapshot['gst_rate']);
        $this->assertEquals(12.0, $order->fresh()->taxInvoice->snapshot['gst_rate']);
    }

    public function test_pdf_still_streams_from_the_snapshot(): void
    {
        $order = $this->place($this->item(1120), 1);

        $response = $this->actingAs($this->user)->get(route('tenant.orders.tax-invoice.pdf', $order));
        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    public function test_legacy_invoice_without_snapshot_renders_via_live_fallback(): void
    {
        $order = $this->place($this->item(1120), 1);
        // Simulate a pre-migration invoice by clearing its snapshot.
        $order->taxInvoice->update(['snapshot' => null]);

        $response = $this->actingAs($this->user)->get(route('tenant.orders.tax-invoice.pdf', $order));
        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }
}
