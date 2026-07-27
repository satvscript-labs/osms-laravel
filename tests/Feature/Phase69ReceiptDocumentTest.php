<?php

namespace Tests\Feature;

use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P0 / BUG-P02 — the subscription payment document must not fabricate tax.
 *
 * SatvScript is not GST-registered (below threshold), yet every PDF rendered an
 * 18%-inclusive CGST/SGST split beneath a blank `GSTIN:` line — asserting ₹762
 * of tax collected by an entity not registered to collect it, on a document a
 * customer might submit for input tax credit.
 *
 * Also covers BUG-P10: a labelled-but-empty legal field must never render.
 */
class Phase69ReceiptDocumentTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;
    private SubscriptionInvoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['store_name' => 'Sahaj Optical']);
        $this->admin = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role' => 'store_admin',
        ]);

        $this->invoice = SubscriptionInvoice::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'razorpay_payment_id' => 'pay_TEST1',
            'amount' => 4999.00,
            'currency' => 'INR',
            'status' => 'paid',
            'paid_at' => now(),
        ]);
    }

    /** Render the document to raw HTML so its content can be asserted on. */
    private function renderPdfHtml(): string
    {
        return view('tenant.billing.invoice-pdf', [
            'invoice' => $this->invoice,
            'tenant' => $this->tenant,
        ])->render();
    }

    // ---- NOT GST-REGISTERED (today's reality) ---------------------------

    public function test_not_registered_renders_a_payment_receipt_with_no_tax(): void
    {
        config(['saas.gst_registered' => false, 'saas.gst_number' => '']);

        $html = $this->renderPdfHtml();

        $this->assertStringContainsString('PAYMENT RECEIPT', $html);
        $this->assertStringNotContainsString('TAX INVOICE', $html);

        // The whole point: no fabricated tax, anywhere.
        $this->assertStringNotContainsString('CGST', $html);
        $this->assertStringNotContainsString('SGST', $html);
        $this->assertStringNotContainsString('Taxable value', $html);
        $this->assertStringNotContainsString('GST-inclusive', $html);

        // One honest total, matching what was actually paid.
        $this->assertStringContainsString('Total paid', $html);
        $this->assertStringContainsString('4,999.00', $html);
    }

    public function test_no_gstin_line_is_rendered_when_not_registered(): void
    {
        config(['saas.gst_registered' => false, 'saas.gst_number' => '']);

        $html = $this->renderPdfHtml();

        // BUG-P02/P10 — a blank "GSTIN:" is worse than none at all.
        $this->assertStringNotContainsString('GSTIN:', $html);
    }

    public function test_an_empty_address_does_not_render_an_empty_line(): void
    {
        config([
            'saas.gst_registered' => false,
            'saas.contact_address' => '',
            'saas.legal_entity' => 'SatvScript',
        ]);

        $html = $this->renderPdfHtml();

        // The document must still read as complete: entity present, no orphan blanks.
        $this->assertStringContainsString('SatvScript', $html);
        $this->assertStringNotContainsString('<div class="muted"></div>', $html);
    }

    public function test_the_owner_supplied_note_about_not_being_a_tax_invoice_is_absent(): void
    {
        config(['saas.gst_registered' => false]);

        $html = $this->renderPdfHtml();

        // Owner's decision: the absence of a GSTIN and tax lines already says it.
        $this->assertStringNotContainsString('not a tax invoice', strtolower($html));
    }

    public function test_the_download_is_named_a_receipt_when_not_registered(): void
    {
        config(['saas.gst_registered' => false]);

        $res = $this->actingAs($this->admin)
            ->get(route('tenant.billing.invoices.pdf', $this->invoice));

        $res->assertOk();
        $this->assertStringContainsString('OSMS-receipt-', $res->headers->get('content-disposition'));
    }

    // ---- ONCE REGISTERED (the flag is the only switch) ------------------

    public function test_registering_restores_a_compliant_tax_invoice(): void
    {
        config([
            'saas.gst_registered' => true,
            'saas.gst_number' => '24ABCDE1234F1Z5',
            'billing.gst_rate' => 18,
        ]);

        $html = $this->renderPdfHtml();

        $this->assertStringContainsString('TAX INVOICE', $html);
        $this->assertStringContainsString('GSTIN: 24ABCDE1234F1Z5', $html);
        $this->assertStringContainsString('CGST', $html);
        $this->assertStringContainsString('SGST', $html);

        // 4999 inclusive of 18% => base 4236.44, each half 381.28.
        $this->assertStringContainsString('4,236.44', $html);
        $this->assertStringContainsString('381.28', $html);
    }

    public function test_the_download_is_named_an_invoice_once_registered(): void
    {
        config(['saas.gst_registered' => true, 'saas.gst_number' => '24ABCDE1234F1Z5']);

        $res = $this->actingAs($this->admin)
            ->get(route('tenant.billing.invoices.pdf', $this->invoice));

        $res->assertOk();
        $this->assertStringContainsString('OSMS-invoice-', $res->headers->get('content-disposition'));
    }

    // ---- BUG-P10 · the public contact page ------------------------------

    public function test_the_contact_page_hides_gstin_when_not_registered(): void
    {
        config(['saas.gst_registered' => false, 'saas.gst_number' => '', 'saas.contact_address' => '']);

        $res = $this->get(route('legal.contact'));

        $res->assertOk();
        $res->assertDontSee('GSTIN');
    }

    public function test_the_contact_page_shows_gstin_once_registered(): void
    {
        config(['saas.gst_registered' => true, 'saas.gst_number' => '24ABCDE1234F1Z5']);

        $this->get(route('legal.contact'))
            ->assertOk()
            ->assertSee('24ABCDE1234F1Z5');
    }
}
