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
            'months' => 12, 'interval' => 'yearly', 'reason' => 'audit: legacy path test',
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
            'days' => 60, 'reason' => 'audit: legacy path test',
        ])->assertRedirect();

        $granted = $this->sub()->current_period_end->toDateString();

        $this->webhook('subscription.charged')->assertOk();

        $this->assertSame($granted, $this->sub()->current_period_end->toDateString());

        // This store was `active` before the extension (see setUp), and it stays
        // `active`. The legacy screen used to force `trialing` on every extend —
        // which silently reclassified a PAYING customer as a trial and, because
        // MRR only counts `active`, erased them from revenue. Extending time
        // must not change what someone is. (AUD-07 routed this through the
        // lifecycle service, which fixed it.)
        $this->assertSame('active', $this->sub()->status, 'The webhook overrode the operator’s extension.');
    }

    public function test_extending_a_genuine_trial_leaves_it_a_trial(): void
    {
        $this->tenant->subscription->update(['status' => 'trialing']);

        $this->asAdmin()->post(route('superadmin.subscription.extend-trial', $this->tenant), [
            'days' => 30, 'reason' => 'setup help',
        ])->assertRedirect();

        $this->assertSame('trialing', $this->sub()->status);
        $this->assertTrue($this->sub()->current_period_end->gt(now()->addDays(20)));
    }

    public function test_an_operator_cancellation_is_indefinite_and_cannot_be_undone_by_a_webhook(): void
    {
        $this->asAdmin()->post(route('superadmin.subscription.cancel', $this->tenant), ['reason' => 'audit: legacy path test'])->assertRedirect();

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

    public function test_the_money_in_a_legacy_charge_is_always_kept(): void
    {
        $this->asAdmin()->post(route('superadmin.subscription.activate', $this->tenant), [
            'months' => 12, 'interval' => 'yearly', 'reason' => 'audit: legacy path test',
        ])->assertRedirect();

        $this->webhook('subscription.charged', [
            'payload' => ['payment' => ['entity' => ['id' => 'pay_REAL_MONEY', 'amount' => 499000]]],
        ])->assertOk();

        // E3-E — ₹4,990 genuinely changed hands: it is KEPT, as unapplied money the
        // operator applies or refunds — never silently, never as an access change.
        $kept = \App\Models\UnappliedPayment::where('gateway_payment_id', 'pay_REAL_MONEY')->sole();
        $this->assertSame(4990.0, (float) $kept->amount);
        $this->assertSame('open', $kept->status);
        $this->assertSame(0, SubscriptionInvoice::withoutGlobalScopes()->where('razorpay_payment_id', 'pay_REAL_MONEY')->count());
    }

    public function test_a_legacy_webhook_leaves_an_audit_trail(): void
    {
        $this->webhook('subscription.activated')->assertOk();
        $this->webhook('subscription.charged')->assertOk();

        $ignored = AdminAuditLog::where('action', 'subscription.gateway_event_ignored')->sole();
        $this->assertStringContainsString('nothing about access changed', $ignored->description);
        $this->assertSame('Gateway (Razorpay)', $ignored->actorLabel());
        $this->assertSame(1, AdminAuditLog::where('action', 'payment.unapplied')->count(), 'the charged money is on the record');
    }

    // ---- E3-E: THE RETIRED LANE NEVER GOVERNS ACCESS --------------------

    public function test_the_retired_webhook_lane_never_governs_even_with_no_override(): void
    {
        $before = [$this->sub()->status, $this->sub()->current_period_end->toDateString()];

        $this->webhook('subscription.charged')->assertOk();
        $this->webhook('subscription.cancelled')->assertOk();

        $this->assertSame($before, [$this->sub()->status, $this->sub()->current_period_end->toDateString()]);
        $this->assertNull($this->sub()->override_kind, 'An untouched subscription must carry no override.');
    }

    public function test_the_webhook_governs_again_once_the_override_window_passes(): void
    {
        $this->asAdmin()->post(route('superadmin.subscription.extend-trial', $this->tenant), [
            'days' => 5, 'reason' => 'audit: legacy path test',
        ])->assertRedirect();

        // NB: extendTrial extends from the LATER of today or the existing period end,
        // so 5 days on a fresh 14-day trial lands ~20 days out, not 5. Travel to the
        // day AFTER whatever window was actually granted, rather than guessing.
        $this->assertTrue($this->sub()->hasActiveOverride(), 'Sanity: the grant should be in force.');

        $this->travelTo($this->sub()->override_until->copy()->addDay()->startOfDay());

        $this->assertFalse($this->sub()->hasActiveOverride(), 'Sanity: the grant should have lapsed.');

        $this->webhook('subscription.charged')->assertOk();

        // E3-E — there is no automated lane to hand control back to: the retired
        // webhook changes nothing, override or not.
        $this->assertSame('active', $this->sub()->status);
    }
}
