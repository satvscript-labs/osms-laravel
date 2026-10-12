<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AdminAuditLog;
use App\Models\PlatformSetting;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\StoreProvisioner;
use App\Services\SubscriptionLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * P4 — automation wiring.
 *
 * Supervised mode (matrix row 15), explicit dunning suppression, and the
 * automated lane writing the SAME ledger rows as the manual one.
 *
 * The binding constraint throughout is the owner's C1: **self-serve must never
 * be degraded.** Every test here that touches checkout asserts it still works
 * unless an operator deliberately closed it.
 */
class Phase75AutomationWiringTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlanSeeder::class);
        $this->admin = User::factory()->create(['role' => 'superadmin', 'tenant_id' => null]);
    }

    /** @return array{0: Tenant, 1: User} store, its owner */
    private function store(string $name = 'Sahaj Optical'): array
    {
        $owner = User::factory()->create(['tenant_id' => null, 'role' => 'store_admin', 'name' => 'Rushi']);
        $tenant = app(StoreProvisioner::class)->provision($owner, ['store_name' => $name]);

        return [$tenant, $owner->fresh()];
    }

    private function asOperator(): self
    {
        $this->actingAs($this->admin)->withSession(['auth.password_confirmed_at' => time()]);

        return $this;
    }

    private function webhook(array $payload)
    {
        config(['services.razorpay.webhook_secret' => 'whsec_test']);
        $body = json_encode($payload);

        return $this->call('POST', route('webhooks.razorpay'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-Razorpay-Signature' => hash_hmac('sha256', $body, 'whsec_test'),
            'HTTP_X-Razorpay-Event-Id' => 'evt_' . uniqid('', true),
        ], $body);
    }

    private function chargePayload(string $subId, string $paymentId, int $paise = 49900): array
    {
        return [
            'event' => 'subscription.charged',
            'payload' => [
                'subscription' => ['entity' => ['id' => $subId, 'current_end' => now()->addDays(30)->timestamp]],
                'payment' => ['entity' => [
                    'id' => $paymentId, 'amount' => $paise, 'currency' => 'INR',
                    'created_at' => now()->timestamp,
                ]],
            ],
        ];
    }

    // ================================================================
    // One ledger, one shape — the automated lane included
    // ================================================================

    public function test_a_gateway_payment_gets_a_receipt_number_like_every_other_payment(): void
    {
        [$tenant] = $this->store();
        $tenant->account->subscription->update(['razorpay_subscription_id' => 'sub_LIVE']);

        $this->webhook($this->chargePayload('sub_LIVE', 'pay_1'))->assertOk();

        $row = SubscriptionInvoice::withoutGlobalScopes()->first();

        // Before P4 the webhook wrote the row directly and skipped this, so a
        // customer who paid ONLINE got a receipt with no number while a cash
        // payer got one. "One ledger" has to mean identical rows.
        $this->assertNotNull($row->receipt_no);
        $this->assertStringStartsWith('OSMS-', $row->receipt_no);
        $this->assertSame('razorpay', $row->method);
        $this->assertSame('webhook', $row->source);
        $this->assertNull($row->recorded_by, 'Nobody typed this; the gateway told us.');
        $this->assertSame($tenant->account_id, $row->account_id);
    }

    public function test_a_retried_webhook_never_records_or_numbers_twice(): void
    {
        [$tenant] = $this->store();
        $tenant->account->subscription->update(['razorpay_subscription_id' => 'sub_LIVE']);

        // Razorpay retries. Same payment id, different event id each time.
        $this->webhook($this->chargePayload('sub_LIVE', 'pay_SAME'))->assertOk();
        $this->webhook($this->chargePayload('sub_LIVE', 'pay_SAME'))->assertOk();

        $this->assertSame(1, SubscriptionInvoice::withoutGlobalScopes()->count());
    }

    public function test_manual_and_gateway_receipts_share_one_sequence(): void
    {
        [$tenant] = $this->store();
        $sub = $tenant->account->subscription;
        $sub->update(['razorpay_subscription_id' => 'sub_LIVE']);

        app(\App\Services\PaymentRecorder::class)->record($sub, 4999, 'cash');
        $this->webhook($this->chargePayload('sub_LIVE', 'pay_1'))->assertOk();
        app(\App\Services\PaymentRecorder::class)->record($sub->fresh(), 4999, 'upi');

        $numbers = SubscriptionInvoice::withoutGlobalScopes()->orderBy('receipt_no')->pluck('receipt_no')->all();

        // One business, one receipt book — regardless of which lane wrote it.
        $this->assertSame(['OSMS-' . now()->year . '-0001', 'OSMS-' . now()->year . '-0002', 'OSMS-' . now()->year . '-0003'], $numbers);
    }

    // ================================================================
    // Supervised mode — matrix row 15
    // ================================================================

    public function test_self_serve_checkout_works_by_default(): void
    {
        // Constraint C1. Nothing in P4 may change this without an operator
        // deliberately turning it off.
        [$tenant, $owner] = $this->store();

        $this->assertFalse($tenant->account->isSupervised());

        $this->actingAs($owner)->get(route('tenant.billing.index'))
            ->assertOk()
            ->assertSee('billing/subscribe', false);
    }

    public function test_supervising_one_customer_closes_their_checkout_only(): void
    {
        [$supervised, $ownerA] = $this->store('Supervised Optical');
        [$normal, $ownerB] = $this->store('Normal Optical');

        $this->asOperator()->patch(route('superadmin.accounts.supervised', $supervised->account), [
            'supervised' => 1, 'reason' => 'pays by bank transfer each year',
        ])->assertSessionHas('status');

        // Theirs is closed…
        $this->actingAs($ownerA)->post(route('tenant.billing.subscribe'), [
            'tier' => 'basic', 'interval' => 'monthly',
        ])->assertSessionHas('error');

        // …and everyone else is untouched.
        $this->assertFalse($normal->account->fresh()->isSupervised());
    }

    public function test_the_platform_switch_supervises_everyone_at_once(): void
    {
        [$tenant, $owner] = $this->store();

        $this->asOperator()->patch(route('superadmin.platform.supervised-mode'), [
            'enabled' => 1, 'reason' => 'gateway outage — taking payments by hand',
        ])->assertSessionHas('status');

        $this->assertTrue(PlatformSetting::supervisedGlobally());
        $this->assertTrue($tenant->account->fresh()->isSupervised(), 'The global switch covers accounts with no flag of their own.');

        $this->actingAs($owner)->post(route('tenant.billing.subscribe'), [
            'tier' => 'basic', 'interval' => 'monthly',
        ])->assertSessionHas('error');
    }

    public function test_supervision_is_enforced_server_side_not_merely_hidden(): void
    {
        [$tenant, $owner] = $this->store();
        $tenant->account->update(['supervised' => true, 'supervised_reason' => 'managed']);

        // Posting directly, as though the button were still on the page. A
        // hidden control is a suggestion; the server is the rule. (Same lesson
        // as the shared-phone consent gap.)
        foreach ([
            ['tenant.billing.subscribe', ['tier' => 'basic', 'interval' => 'monthly']],
            ['tenant.billing.change-interval', ['interval' => 'yearly']],
            ['tenant.billing.cancel', []],
        ] as [$route, $payload]) {
            $this->actingAs($owner)->post(route($route), $payload)->assertSessionHas('error');
        }

        $this->assertNull($tenant->account->subscription->fresh()->razorpay_subscription_id);
    }

    public function test_turning_supervision_off_reopens_checkout(): void
    {
        [$tenant, $owner] = $this->store();

        $this->asOperator()->patch(route('superadmin.platform.supervised-mode'), [
            'enabled' => 1, 'reason' => 'outage',
        ]);
        $this->asOperator()->patch(route('superadmin.platform.supervised-mode'), [
            'enabled' => 0, 'reason' => 'resolved',
        ]);

        $this->assertFalse(PlatformSetting::supervisedGlobally());
        $this->assertFalse($tenant->account->fresh()->isSupervised());
    }

    public function test_supervision_changes_are_audited_with_a_reason(): void
    {
        [$tenant] = $this->store();

        $this->asOperator()->patch(route('superadmin.platform.supervised-mode'), ['enabled' => 1])
            ->assertSessionHasErrors('reason');

        $this->asOperator()->patch(route('superadmin.platform.supervised-mode'), [
            'enabled' => 1, 'reason' => 'gateway outage',
        ]);

        $log = AdminAuditLog::where('action', 'platform.supervised_mode')->latest()->first();
        $this->assertNotNull($log);
        $this->assertSame('gateway outage', $log->meta['reason']);
        $this->assertFalse($log->meta['before']);
        $this->assertTrue($log->meta['after']);
    }

    public function test_supervision_never_affects_access_or_data(): void
    {
        [$tenant, $owner] = $this->store();
        $tenant->account->subscription->update(['status' => 'active', 'current_period_end' => now()->addYear()]);
        $tenant->account->update(['supervised' => true]);

        // Closing the checkout is a BILLING decision, not an access one.
        $this->assertTrue($tenant->fresh()->hasActiveAccess());
        $this->actingAs($owner)->get(route('tenant.dashboard'))->assertOk();
    }

    // ================================================================
    // Dunning suppression — playbook §5.2 rule 5
    // ================================================================

    public function test_an_operator_decision_stops_the_trial_chasing_emails(): void
    {
        Mail::fake();

        [$tenant] = $this->store();
        $sub = $tenant->account->subscription;

        // A trial with exactly 3 days left — normally a reminder day.
        $sub->update(['status' => 'trialing', 'current_period_end' => now()->addDays(3)]);

        // The operator has just decided something about this customer.
        $this->actingAs($this->admin);
        app(SubscriptionLifecycle::class)->commit($sub->fresh(), 'extend', [
            'days' => 30, 'reason' => 'migration help',
        ]);

        $this->artisan('subscriptions:reconcile')->assertSuccessful();

        // Chasing someone you have just made a decision about is the most
        // visible way a panel can look like it is not in control.
        Mail::assertNothingQueued();
    }

    public function test_the_reminder_still_fires_when_nobody_has_overridden(): void
    {
        Mail::fake();

        [$tenant] = $this->store();

        // NB: the reminder fires on `trialDaysLeft()`, which counts CALENDAR
        // days in the billing timezone. `now()->addDays(3)` in UTC can land on
        // a different IST day and silently give 2, so build the date in the
        // billing timezone rather than the test's.
        $tz = config('billing.timezone');
        $tenant->account->subscription->update([
            'status' => 'trialing',
            'current_period_end' => \Illuminate\Support\Carbon::today($tz)->addDays(3),
        ]);

        $this->assertSame(3, $tenant->account->subscription->fresh()->trialDaysLeft(), 'Sanity: it is a reminder day.');

        $this->artisan('subscriptions:reconcile')->assertSuccessful();

        // Suppression must be surgical: untouched customers are still chased.
        Mail::assertQueued(\App\Mail\TrialStatusMail::class);
    }

    // ================================================================
    // The Platform surface
    // ================================================================

    public function test_the_platform_surface_renders_and_lists_supervised_customers(): void
    {
        [$tenant] = $this->store();
        $tenant->account->update(['supervised' => true, 'supervised_reason' => 'bank transfer yearly']);

        $this->asOperator()->get(route('superadmin.platform.index'))
            ->assertOk()
            ->assertSee('Supervised mode')
            ->assertSee('Rushi')
            ->assertSee('bank transfer yearly');
    }

    public function test_platform_routes_are_refused_for_non_operators(): void
    {
        [$tenant, $owner] = $this->store();

        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])
            ->patch(route('superadmin.platform.supervised-mode'), ['enabled' => 1, 'reason' => 'x'])
            ->assertForbidden();

        $this->assertFalse(PlatformSetting::supervisedGlobally());
    }
}
