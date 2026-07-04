<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\EyeRecord;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Session 3 — FT-RxPrivacy (5.1): the prescription is confidential. It is
 * shown to staff on the order screen (marked "Staff only" + no-print) but must
 * never appear on the customer-facing PDF receipt.
 */
class Phase22RxPrivacyTest extends TestCase
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

    private function orderWithRx(): Order
    {
        $customer = Customer::create(['tenant_id' => $this->tenant->id, 'name' => 'Rahul', 'phone' => '+91 9000011111']);
        $rx = EyeRecord::create([
            'tenant_id' => $this->tenant->id, 'customer_id' => $customer->id, 'recorded_by' => $this->user->id,
            'od_sph' => -3.25, 'os_sph' => -2.75, 'pd' => 62,
        ]);
        $item = Inventory::create([
            'tenant_id' => $this->tenant->id, 'sku' => 'SKU-X', 'barcode' => '111122223333',
            'item_type' => 'frame', 'brand' => 'Ray-Ban', 'model_name' => 'Aviator',
            'cost_price' => 50, 'selling_price' => 250, 'stock_qty' => 5, 'min_alert_qty' => 2,
        ]);
        $order = Order::create([
            'tenant_id' => $this->tenant->id, 'customer_id' => $customer->id, 'eye_record_id' => $rx->id,
            'status' => 'pending', 'subtotal' => 250, 'total_amount' => 250, 'advance_paid' => 0,
        ]);
        $order->items()->create(['inventory_id' => $item->id, 'quantity' => 1, 'unit_price' => 250, 'list_price' => 250]);

        return $order;
    }

    public function test_pdf_receipt_omits_the_prescription(): void
    {
        $order = $this->orderWithRx();
        $order->load(['customer', 'eyeRecord', 'items.inventory:id,sku,brand,model_name']);

        $html = view('tenant.orders.receipt-pdf', ['order' => $order, 'tenant' => $this->tenant])->render();

        $this->assertStringNotContainsString('Prescription', $html);
        $this->assertStringNotContainsString('-3.25', $html); // OD SPH must not leak
        $this->assertStringContainsString('Rahul', $html);    // customer still shown
    }

    public function test_order_screen_shows_rx_to_staff_but_marks_it_not_printed(): void
    {
        $order = $this->orderWithRx();

        $this->actingAs($this->user)->get(route('tenant.orders.show', $order))
            ->assertOk()
            ->assertSee('Prescription')     // staff can see it on screen
            ->assertSee('Staff only')       // clearly marked confidential
            ->assertSee('no-print', false); // and excluded from the printed receipt
    }
}
