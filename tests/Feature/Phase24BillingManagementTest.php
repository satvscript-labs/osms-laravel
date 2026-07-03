<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Bunch 3 — ST-Billing (S4): subscribe (single plan) / cancel / invoices +
 * webhook idempotency + charge→invoice.
 */
class Phase24BillingManagementTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['store_name' => 'Billing Optical']);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'store_admin']);
        $this->subscription = Subscription::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)->first();
        $this->subscription->update(['razorpay_subscription_id' => 'sub_ABC']);
    }

    private function postWebhook(array $body, ?string $eventId = 'evt_1'): TestResponse
    {
        config(['services.razorpay.webhook_secret' => 'whsec_test']);
        $payload = json_encode($body);
        $sig = hash_hmac('sha256', $payload, 'whsec_test');

        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_X-Razorpay-Signature' => $sig];
        if ($eventId) {
            $headers['HTTP_X-Razorpay-Event-Id'] = $eventId;
        }

        return $this->call('POST', route('webhooks.razorpay'), [], [], [], $headers, $payload);
    }

    private function chargePayload(string $paymentId = 'pay_1'): array
    {
        return [
            'event' => 'subscription.charged',
            'payload' => [
                'subscription' => ['entity' => ['id' => 'sub_ABC', 'current_end' => now()->addMonth()->timestamp]],
                'payment' => ['entity' => [
                    'id' => $paymentId, 'amount' => 49900, 'currency' => 'INR', 'created_at' => now()->timestamp,
                ]],
            ],
        ];
    }

    // ---- webhook: charge → invoice + activation ---------------------------

    public function test_charge_webhook_creates_invoice_and_activates(): void
    {
        $this->postWebhook($this->chargePayload())->assertOk();

        $this->assertDatabaseCount('subscription_invoices', 1);
        $invoice = SubscriptionInvoice::withoutGlobalScopes()->first();
        $this->assertSame('499.00', (string) $invoice->amount);
        $this->assertSame($this->tenant->id, $invoice->tenant_id);
        $this->assertSame('active', $this->subscription->fresh()->status);
    }

    public function test_duplicate_event_id_is_ignored(): void
    {
        $this->postWebhook($this->chargePayload(), 'evt_dup')->assertOk();
        $this->postWebhook($this->chargePayload(), 'evt_dup')->assertOk(); // same event id

        $this->assertDatabaseCount('subscription_invoices', 1);
    }

    public function test_same_payment_not_invoiced_twice_across_events(): void
    {
        // Different event ids, same payment id → still one invoice (payment-id guard).
        $this->postWebhook($this->chargePayload('pay_same'), 'evt_a')->assertOk();
        $this->postWebhook($this->chargePayload('pay_same'), 'evt_b')->assertOk();

        $this->assertDatabaseCount('subscription_invoices', 1);
    }

    public function test_bad_signature_is_rejected(): void
    {
        config(['services.razorpay.webhook_secret' => 'whsec_test']);
        $this->postJson(route('webhooks.razorpay'), $this->chargePayload(), [
            'X-Razorpay-Signature' => 'wrong',
        ])->assertStatus(400);
    }

    // ---- cancel ----------------------------------------------------------

    public function test_cancel_marks_pending_and_keeps_access(): void
    {
        $this->subscription->update(['status' => 'active']);

        $this->mock(BillingService::class, function ($m) {
            $m->shouldReceive('isConfigured')->andReturn(true);
            $m->shouldReceive('cancelSubscription')->once();
        });

        $this->actingAs($this->user)->post(route('tenant.billing.cancel'))
            ->assertRedirect()->assertSessionHas('status');

        $this->assertTrue($this->subscription->fresh()->cancel_at_period_end);
        // Still active until period end → workspace remains reachable.
        $this->actingAs($this->user)->get(route('tenant.dashboard'))->assertOk();
    }

    public function test_cancel_rejected_without_active_subscription(): void
    {
        // Default subscription is trialing, not active.
        $this->actingAs($this->user)->post(route('tenant.billing.cancel'))
            ->assertRedirect()->assertSessionHas('error');

        $this->assertFalse($this->subscription->fresh()->cancel_at_period_end);
    }

    // ---- subscribe: trial conversion is allowed --------------------------

    public function test_trialing_store_can_subscribe(): void
    {
        $this->mock(BillingService::class, function ($m) {
            $m->shouldReceive('isConfigured')->andReturn(true);
            $m->shouldReceive('createSubscription')
                ->with(\Mockery::any(), 'basic', 'yearly')
                ->once()
                ->andReturn(['subscription_id' => 'sub_NEW', 'short_url' => null]);
            $m->shouldReceive('publicKey')->andReturn('rzp_test_x');
        });

        $this->actingAs($this->user)->post(route('tenant.billing.subscribe'), ['tier' => 'basic', 'interval' => 'yearly'])
            ->assertOk()->assertSee('Pay with Razorpay');

        $this->assertSame('sub_NEW', $this->subscription->fresh()->razorpay_subscription_id);
    }

    // ---- invoices --------------------------------------------------------

    private function makeInvoice(string $tenantId, string $paymentId): SubscriptionInvoice
    {
        return SubscriptionInvoice::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId,
            'razorpay_payment_id' => $paymentId,
            'amount' => 499.00,
            'currency' => 'INR',
            'status' => 'paid',
            'paid_at' => now(),
        ]);
    }

    public function test_invoice_pdf_downloads(): void
    {
        $invoice = $this->makeInvoice($this->tenant->id, 'pay_pdf');

        $res = $this->actingAs($this->user)->get(route('tenant.billing.invoices.pdf', $invoice));
        $res->assertOk();
        $this->assertStringContainsString('pdf', strtolower($res->headers->get('content-type')));
    }

    public function test_invoice_pdf_is_tenant_isolated(): void
    {
        $otherTenant = Tenant::create(['store_name' => 'Other Optical']);
        $otherInvoice = $this->makeInvoice($otherTenant->id, 'pay_other');

        $this->actingAs($this->user)
            ->get(route('tenant.billing.invoices.pdf', $otherInvoice))
            ->assertNotFound();
    }
}
