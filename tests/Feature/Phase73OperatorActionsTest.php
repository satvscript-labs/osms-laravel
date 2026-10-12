<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PaymentRecorder;
use App\Services\StoreProvisioner;
use App\Services\SubscriptionLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P3 — the operator action surface (dual-lane matrix, 03 §4).
 *
 * The contract every action must satisfy, proven rather than assumed:
 *   preview == commit · reason required · sticky against the webhook ·
 *   audited with before→after · service-routed · never silently destructive.
 */
class Phase73OperatorActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlanSeeder::class);

        $this->admin = User::factory()->create(['role' => 'superadmin', 'tenant_id' => null]);

        $owner = User::factory()->create(['tenant_id' => null, 'role' => 'store_admin', 'name' => 'Rushi Dharsandiya']);
        $this->tenant = app(StoreProvisioner::class)->provision($owner, ['store_name' => 'Sahaj Optical']);
    }

    private function account()
    {
        return $this->tenant->account->fresh();
    }

    private function sub(): Subscription
    {
        return $this->account()->subscription;
    }

    /** Act as the operator with a fresh password confirmation. */
    private function asOperator(): self
    {
        $this->actingAs($this->admin)->withSession(['auth.password_confirmed_at' => time()]);

        return $this;
    }

    private function act(array $payload)
    {
        return $this->asOperator()->post(route('superadmin.accounts.action', $this->account()), $payload);
    }

    // ================================================================
    // The contract
    // ================================================================

    public function test_preview_and_commit_produce_the_same_outcome(): void
    {
        // The heart of "quote before charge": the preview genuinely runs the
        // action and rolls it back, so it cannot drift from the commit.
        $lifecycle = app(SubscriptionLifecycle::class);
        $sub = $this->sub();

        $preview = $lifecycle->preview($sub, 'extend', ['days' => 30, 'reason' => 'setup delay']);

        // Nothing committed.
        $this->assertSame(
            $sub->current_period_end->toDateString(),
            $this->sub()->current_period_end->toDateString(),
            'A preview must not change anything.',
        );

        $this->actingAs($this->admin);
        $lifecycle->commit($this->sub(), 'extend', ['days' => 30, 'reason' => 'setup delay']);

        $this->assertSame(
            $preview['after']['current_period_end'],
            $this->sub()->current_period_end->toDateString(),
            'What the operator was shown is not what happened.',
        );
        $this->assertSame($preview['after']['status'], $this->sub()->status);
    }

    public function test_the_preview_endpoint_reports_the_change_without_committing(): void
    {
        $before = $this->sub()->current_period_end->toDateString();

        $res = $this->asOperator()->postJson(route('superadmin.accounts.preview', $this->account()), [
            'action' => 'comp', 'months' => 3, 'reason' => 'goodwill',
        ]);

        $res->assertOk()
            ->assertJsonPath('changes.status.to', 'active')
            ->assertJsonPath('ledger_rows_added', 1); // a comp is a ₹0 row

        $this->assertSame($before, $this->sub()->current_period_end->toDateString());
        $this->assertSame(0, SubscriptionInvoice::withoutGlobalScopes()->count());
    }

    public function test_a_preview_reports_a_rejection_instead_of_throwing(): void
    {
        $res = $this->asOperator()->postJson(route('superadmin.accounts.preview', $this->account()), [
            'action' => 'comp', 'months' => 3, // no reason
        ]);

        $res->assertOk();
        $this->assertStringContainsString('reason is required', $res->json('error'));
    }

    /** @dataProvider discretionaryActions */
    public function test_discretionary_actions_refuse_to_commit_without_a_reason(string $action, array $extra): void
    {
        $this->act(['action' => $action] + $extra)
            ->assertSessionHas('error');

        // Nothing moved.
        $this->assertNull($this->sub()->override_kind);
    }

    public static function discretionaryActions(): array
    {
        return [
            'extend' => ['extend', ['days' => 10]],
            'comp' => ['comp', ['months' => 2]],
            'suspend' => ['suspend', []],
            'cancel' => ['cancel', []],
            'force expire' => ['force_expire', []],
            'set price' => ['set_price', ['price' => 3500]],
        ];
    }

    public function test_every_committed_action_is_audited_with_before_and_after(): void
    {
        $this->act(['action' => 'extend', 'days' => 20, 'reason' => 'onboarding help'])
            ->assertSessionHas('status');

        $log = AdminAuditLog::where('action', 'subscription.extend')->latest()->first();

        $this->assertNotNull($log);
        $this->assertSame($this->admin->email, $log->admin_email);
        $this->assertSame('onboarding help', $log->meta['reason']);
        $this->assertNotSame(
            $log->meta['before']['current_period_end'],
            $log->meta['after']['current_period_end'],
            'The audit entry must record what actually changed.',
        );
    }

    // ================================================================
    // The matrix rows
    // ================================================================

    public function test_row3_extend_never_shortens_existing_coverage(): void
    {
        $this->sub()->update(['current_period_end' => now()->addMonths(6)]);
        $farFuture = $this->sub()->current_period_end->toDateString();

        $this->act(['action' => 'extend', 'days' => 7, 'reason' => 'goodwill'])->assertSessionHas('status');

        // Extends from the END, not from today — a 7-day extension on a
        // 6-month subscription must not collapse it to next week.
        $this->assertTrue($this->sub()->current_period_end->gt($farFuture));
    }

    public function test_row6_comp_writes_a_zero_rupee_ledger_row_and_is_sticky(): void
    {
        $this->act(['action' => 'comp', 'months' => 12, 'reason' => 'launch partner'])
            ->assertSessionHas('status');

        $sub = $this->sub();
        $this->assertSame('active', $sub->status);
        $this->assertSame('comp', $sub->override_kind);

        $row = SubscriptionInvoice::withoutGlobalScopes()->where('method', 'comp')->first();
        $this->assertNotNull($row, 'A grant must be a ₹0 record, never an absent one.');
        $this->assertSame('0.00', $row->amount);
        $this->assertSame('launch partner', $row->reason);
    }

    public function test_row5_renew_records_money_and_moves_the_clock_together(): void
    {
        $this->act([
            'action' => 'renew', 'amount' => 3500, 'method' => 'cash',
            'interval' => 'yearly', 'reference' => 'till-42',
        ])->assertSessionHas('status');

        $sub = $this->sub();
        $this->assertSame('active', $sub->status);
        $this->assertSame('yearly', $sub->interval);
        $this->assertSame('manual_renewal', $sub->override_kind);

        $row = SubscriptionInvoice::withoutGlobalScopes()->where('method', 'cash')->first();
        $this->assertSame('3500.00', $row->amount);
        $this->assertSame('till-42', $row->reference);
        // The line knows what coverage it bought — needed for co-termination later.
        $this->assertNotNull($row->period_start);
        $this->assertNotNull($row->period_end);
    }

    public function test_row7_negotiated_price_overrides_list_and_can_be_cleared(): void
    {
        $this->act(['action' => 'set_price', 'price' => 3500, 'reason' => 'first customer rate'])
            ->assertSessionHas('status');

        $this->assertSame('3500.00', $this->sub()->negotiated_price);
        $this->assertSame($this->admin->id, $this->sub()->negotiated_by);

        $this->act(['action' => 'clear_price', 'reason' => 'standard rate now'])
            ->assertSessionHas('status');

        $this->assertNull($this->sub()->negotiated_price);
    }

    public function test_row10_suspend_preserves_the_paid_through_date(): void
    {
        $this->sub()->update(['status' => 'active', 'current_period_end' => now()->addYear()]);
        $paidThrough = $this->sub()->current_period_end->toDateString();

        $this->act(['action' => 'suspend', 'reason' => 'non-payment'])->assertSessionHas('status');

        // BUG-P06 — the old panel's only lever was cancel, which destroyed this.
        $this->assertSame($paidThrough, $this->sub()->current_period_end->toDateString());
        $this->assertSame('suspension', $this->sub()->override_kind);
        $this->assertFalse($this->tenant->fresh()->hasActiveAccess());

        // …and reactivating restores them, losing nothing.
        $this->act(['action' => 'reactivate', 'reason' => 'they paid'])->assertSessionHas('status');

        $this->assertSame('active', $this->sub()->status);
        $this->assertSame($paidThrough, $this->sub()->current_period_end->toDateString());
        $this->assertTrue($this->tenant->fresh()->hasActiveAccess());
    }

    public function test_row12_cancel_defaults_to_letting_them_keep_what_they_paid_for(): void
    {
        $this->sub()->update(['status' => 'active', 'current_period_end' => now()->addMonths(8)]);

        $this->act(['action' => 'cancel', 'reason' => 'moving to a competitor', 'at_period_end' => true])
            ->assertSessionHas('status');

        // Decision C3 — no refunds, so access runs to the end of the paid period.
        $this->assertTrue((bool) $this->sub()->cancel_at_period_end);
        $this->assertTrue($this->tenant->fresh()->hasActiveAccess());
    }

    public function test_row9_force_expire_ends_access_immediately(): void
    {
        $this->sub()->update(['status' => 'active', 'current_period_end' => now()->addYear()]);

        $this->act(['action' => 'force_expire', 'reason' => 'fraud'])->assertSessionHas('status');

        $this->assertSame('canceled', $this->sub()->status);
        $this->assertFalse($this->tenant->fresh()->hasActiveAccess());
    }

    public function test_row8_mark_paid_records_the_money_and_stops_the_chasing(): void
    {
        $this->sub()->update(['status' => 'past_due']);

        $this->act(['action' => 'mark_paid', 'amount' => 4999, 'method' => 'upi', 'reason' => 'paid by UPI, webhook missed it'])
            ->assertSessionHas('status');

        $this->assertSame('active', $this->sub()->status);
        $this->assertSame('manual_renewal', $this->sub()->override_kind);
        $this->assertSame('4999.00', SubscriptionInvoice::withoutGlobalScopes()->where('method', 'upi')->first()->amount);
    }

    public function test_row4_record_payment_logs_money_without_touching_the_clock(): void
    {
        $before = $this->sub()->current_period_end->toDateString();

        $this->asOperator()->post(route('superadmin.accounts.payment', $this->account()), [
            'amount' => 1500, 'method' => 'cash', 'reference' => 'part payment',
        ])->assertSessionHas('status');

        // Deliberately separate from Renew: recording money and moving the
        // renewal date are different intents.
        $this->assertSame($before, $this->sub()->current_period_end->toDateString());
        $this->assertSame('1500.00', SubscriptionInvoice::withoutGlobalScopes()->first()->amount);
    }

    public function test_row11_reversal_keeps_the_row_but_stops_it_counting(): void
    {
        $row = app(PaymentRecorder::class)->record($this->sub(), 4999, 'cash');

        $this->asOperator()->post(route('superadmin.accounts.payment.reverse', [$this->account(), $row]), [
            'reason' => 'entered twice',
        ])->assertSessionHas('status');

        $row->refresh();
        $this->assertNotNull($row->reversed_at);
        $this->assertSame('entered twice', $row->reversal_reason);
        // Still there — a reversal is a state, never a delete.
        $this->assertSame(1, SubscriptionInvoice::withoutGlobalScopes()->count());

        $this->asOperator()->get(route('superadmin.billing.index'))
            ->assertOk()
            ->assertViewHas('totals', fn ($t) => $t['collected'] === 0.0);
    }

    public function test_a_reversal_cannot_cross_accounts(): void
    {
        $otherOwner = User::factory()->create(['tenant_id' => null, 'role' => 'store_admin']);
        $other = app(StoreProvisioner::class)->provision($otherOwner, ['store_name' => 'Other Optical']);
        $theirRow = app(PaymentRecorder::class)->record($other->account->subscription, 999, 'cash');

        // A valid invoice id from a DIFFERENT account must not be reversible
        // through this account's URL.
        $this->asOperator()
            ->post(route('superadmin.accounts.payment.reverse', [$this->account(), $theirRow]), ['reason' => 'x'])
            ->assertNotFound();

        $this->assertNull($theirRow->fresh()->reversed_at);
    }

    public function test_per_store_suspension_does_not_touch_the_account(): void
    {
        $this->sub()->update(['status' => 'active', 'current_period_end' => now()->addYear()]);

        $this->asOperator()->patch(
            route('superadmin.accounts.store.status', [$this->account(), $this->tenant]),
            ['store_status' => 'suspended', 'reason' => 'branch closed for refit'],
        )->assertSessionHas('status');

        $this->assertSame('suspended', $this->tenant->fresh()->store_status);
        // The money is untouched — two independent levers (06 §5).
        $this->assertSame('active', $this->sub()->status);
        $this->assertNull($this->sub()->override_kind);
    }

    // ================================================================
    // Stickiness — the P0 guarantee must survive every P3 action
    // ================================================================

    /** @dataProvider stickyActions */
    public function test_operator_decisions_survive_the_next_webhook(string $action, array $payload): void
    {
        $this->sub()->update(['razorpay_subscription_id' => 'sub_LIVE', 'status' => 'active']);

        $this->act(['action' => $action] + $payload)->assertSessionHas('status');

        $expected = $this->sub()->current_period_end?->toDateString();
        $expectedStatus = $this->sub()->status;

        // Razorpay charges the old mandate and reports a much shorter period.
        config(['services.razorpay.webhook_secret' => 'whsec_test']);
        $body = json_encode([
            'event' => 'subscription.charged',
            'payload' => [
                'subscription' => ['entity' => ['id' => 'sub_LIVE', 'current_end' => now()->addDays(30)->timestamp]],
                'payment' => ['entity' => ['id' => 'pay_' . uniqid(), 'amount' => 49900, 'currency' => 'INR', 'created_at' => now()->timestamp]],
            ],
        ]);

        $this->call('POST', route('webhooks.razorpay'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-Razorpay-Signature' => hash_hmac('sha256', $body, 'whsec_test'),
            'HTTP_X-Razorpay-Event-Id' => 'evt_' . uniqid(),
        ], $body)->assertOk();

        $this->assertSame($expected, $this->sub()->current_period_end?->toDateString(), "[{$action}] was clobbered by the webhook.");
        $this->assertSame($expectedStatus, $this->sub()->status);

        // …but the money that genuinely changed hands is still recorded.
        $this->assertTrue(
            SubscriptionInvoice::withoutGlobalScopes()->where('method', 'razorpay')->exists(),
            'Suppressing entitlement must never drop the payment record.',
        );
    }

    public static function stickyActions(): array
    {
        return [
            'comp' => ['comp', ['months' => 12, 'reason' => 'launch partner']],
            'extend' => ['extend', ['days' => 90, 'reason' => 'migration help']],
            'suspend' => ['suspend', ['reason' => 'non-payment']],
            'manual renewal' => ['renew', ['amount' => 3500, 'method' => 'cash', 'interval' => 'yearly']],
        ];
    }

    // ================================================================
    // Authorization
    // ================================================================

    public function test_action_routes_require_a_recent_password_confirmation(): void
    {
        // No password confirmation in session — money actions must re-challenge.
        $this->actingAs($this->admin)
            ->post(route('superadmin.accounts.action', $this->account()), ['action' => 'comp', 'months' => 1, 'reason' => 'x'])
            ->assertRedirect(route('password.confirm'));

        $this->assertNull($this->sub()->override_kind);
    }

    public function test_a_store_admin_cannot_reach_any_action_route(): void
    {
        $staff = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'store_admin']);

        $this->actingAs($staff)->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('superadmin.accounts.action', $this->account()), ['action' => 'comp', 'months' => 60, 'reason' => 'free stuff'])
            ->assertForbidden();

        $this->assertNull($this->sub()->override_kind);
        $this->assertSame(0, SubscriptionInvoice::withoutGlobalScopes()->count());
    }

    public function test_the_360_offers_every_action_within_reach(): void
    {
        $this->sub()->update(['status' => 'past_due']);

        $res = $this->asOperator()->get(route('superadmin.accounts.show', $this->account()));

        $res->assertOk();
        foreach ([
            'm-payment', 'm-renew', 'm-extend', 'm-comp', 'm-price', 'm-interval',
            'm-markpaid', 'm-waive', 'm-suspend', 'm-cancel', 'm-expire', 'm-notes',
        ] as $modal) {
            $res->assertSee($modal);
        }
    }
}
