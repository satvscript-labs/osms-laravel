<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P0 / BUG-P01 — an operator's manual decision must survive the next Razorpay webhook.
 *
 * Playbook 02_SUPERADMIN_PANEL §3.3 calls this "the single most commonly missed
 * correctness bug in operator panels". It was present: a hand-granted 12-month comp
 * collapsed to 30 days the moment the still-live mandate charged.
 *
 * The first test here is the exact reproduction — it FAILED before the fix.
 */
class Phase68OperatorOverrideTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superadmin = User::factory()->create(['role' => 'superadmin', 'tenant_id' => null]);
        $this->tenant = Tenant::create(['store_name' => 'Sticky Optical']);

        // A store on a live Razorpay mandate — the only situation in which the
        // webhook can reach this subscription at all.
        $this->tenant->subscription->update([
            'razorpay_subscription_id' => 'sub_LIVE123',
            'status' => 'active',
            'interval' => 'monthly',
        ]);
    }

    private function asAdmin(): self
    {
        $this->actingAs($this->superadmin)->withSession(['auth.password_confirmed_at' => time()]);

        return $this;
    }

    /** Fire a signed Razorpay webhook, as the gateway would. */
    private function webhook(string $event, array $overrides = []): \Illuminate\Testing\TestResponse
    {
        config(['services.razorpay.webhook_secret' => 'whsec_test']);

        $payload = array_replace_recursive([
            'event' => $event,
            'payload' => [
                'subscription' => ['entity' => [
                    'id' => 'sub_LIVE123',
                    'current_end' => now()->addDays(30)->timestamp,
                ]],
                'payment' => ['entity' => [
                    'id' => 'pay_' . uniqid(),
                    'amount' => 49900,
                    'currency' => 'INR',
                    'created_at' => now()->timestamp,
                ]],
            ],
        ], $overrides);

        $body = json_encode($payload);

        return $this->call('POST', route('webhooks.razorpay'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-Razorpay-Signature' => hash_hmac('sha256', $body, 'whsec_test'),
            'HTTP_X-Razorpay-Event-Id' => 'evt_' . uniqid(),
        ], $body);
    }

    private function sub(): \App\Models\Subscription
    {
        return $this->tenant->subscription()->withoutGlobalScopes()->first();
    }

    // ---- THE REGRESSION -------------------------------------------------

    public function test_a_comp_survives_the_next_charge_webhook(): void
    {
        $this->asAdmin()->post(route('superadmin.subscription.activate', $this->tenant), [
            'months' => 12, 'interval' => 'yearly',
        ])->assertRedirect();

        $granted = $this->sub()->current_period_end->toDateString();

        $this->webhook('subscription.charged')->assertOk();

        $this->assertSame(
            $granted,
            $this->sub()->current_period_end->toDateString(),
            'The operator\'s 12-month grant was overwritten by the webhook (BUG-P01).',
        );
        $this->assertSame('active', $this->sub()->status);
    }

    public function test_a_trial_extension_survives_the_webhook(): void
    {
        $this->asAdmin()->post(route('superadmin.subscription.extend-trial', $this->tenant), [
            'days' => 60,
        ])->assertRedirect();

        $granted = $this->sub()->current_period_end->toDateString();

        $this->webhook('subscription.charged')->assertOk();

        $this->assertSame($granted, $this->sub()->current_period_end->toDateString());
        $this->assertSame('trialing', $this->sub()->status, 'The webhook flipped a granted trial to active.');
    }

    public function test_an_operator_cancellation_is_indefinite_and_cannot_be_undone_by_a_webhook(): void
    {
        $this->asAdmin()->post(route('superadmin.subscription.cancel', $this->tenant))->assertRedirect();

        $this->assertSame('canceled', $this->sub()->status);
        $this->assertNull($this->sub()->override_until, 'A cancellation override must be indefinite.');

        // Razorpay settles the final charge and reports the sub as active.
        $this->webhook('subscription.charged')->assertOk();

        $this->assertSame(
            'canceled',
            $this->sub()->status,
            'A charge webhook resurrected a store the operator had cancelled.',
        );
    }

    // ---- THE MONEY MUST STILL BE RECORDED -------------------------------

    public function test_a_suppressed_webhook_still_records_the_payment(): void
    {
        $this->asAdmin()->post(route('superadmin.subscription.activate', $this->tenant), [
            'months' => 12, 'interval' => 'yearly',
        ])->assertRedirect();

        $this->webhook('subscription.charged', [
            'payload' => ['payment' => ['entity' => ['id' => 'pay_REAL_MONEY', 'amount' => 499000]]],
        ])->assertOk();

        // Entitlement was suppressed, but ₹4,990 genuinely changed hands.
        $this->assertDatabaseHas('subscription_invoices', [
            'razorpay_payment_id' => 'pay_REAL_MONEY',
            'tenant_id' => $this->tenant->id,
        ]);
        $this->assertSame(
            4990.0,
            (float) SubscriptionInvoice::withoutGlobalScopes()
                ->where('razorpay_payment_id', 'pay_REAL_MONEY')->value('amount'),
        );
    }

    public function test_a_suppressed_webhook_leaves_an_audit_trail(): void
    {
        $this->asAdmin()->post(route('superadmin.subscription.activate', $this->tenant), [
            'months' => 6, 'interval' => 'monthly',
        ])->assertRedirect();

        $this->webhook('subscription.charged')->assertOk();

        $log = AdminAuditLog::where('action', 'subscription.webhook_suppressed')->latest()->first();

        $this->assertNotNull($log, 'A suppressed webhook must be visible to the operator.');
        $this->assertSame($this->tenant->id, $log->tenant_id);
        $this->assertSame('comp', $log->meta['override']['kind']);
    }

    // ---- AUTOMATION STILL WORKS WHEN NOBODY HAS OVERRIDDEN --------------

    public function test_the_webhook_still_governs_a_subscription_with_no_override(): void
    {
        $expected = now()->addDays(30)->toDateString();

        $this->webhook('subscription.charged')->assertOk();

        $this->assertSame('active', $this->sub()->status);
        $this->assertSame($expected, $this->sub()->current_period_end->toDateString());
        $this->assertNull($this->sub()->override_kind, 'An untouched subscription must carry no override.');
    }

    public function test_the_webhook_governs_again_once_the_override_window_passes(): void
    {
        $this->asAdmin()->post(route('superadmin.subscription.extend-trial', $this->tenant), [
            'days' => 5,
        ])->assertRedirect();

        // NB: extendTrial extends from the LATER of today or the existing period end,
        // so 5 days on a fresh 14-day trial lands ~20 days out, not 5. Travel to the
        // day AFTER whatever window was actually granted, rather than guessing.
        $this->assertTrue($this->sub()->hasActiveOverride(), 'Sanity: the grant should be in force.');

        $this->travelTo($this->sub()->override_until->copy()->addDay()->startOfDay());

        $this->assertFalse($this->sub()->hasActiveOverride(), 'Sanity: the grant should have lapsed.');

        $this->webhook('subscription.charged')->assertOk();

        $this->assertSame(
            'active',
            $this->sub()->status,
            'An expired override must hand control back to the automated lane.',
        );
    }
}
