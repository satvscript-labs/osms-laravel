<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\PaymentRecorder;
use App\Services\StoreProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ISS-02 — what a reversed payment looks like to the CUSTOMER.
 *
 * The operator's view was already right (struck through, red pill). The
 * customer's was not: a payment taken back still read as a green "paid", and
 * its receipt still downloaded as a normal, valid-looking document — one they
 * could file, or produce in a dispute.
 *
 * Decisions (owner, 2026-08-14): **R-b** stamp the receipt rather than
 * withholding it, and **N-b** tell them on the billing page.
 */
class Phase80ReversedPaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlanSeeder::class);
        $this->admin = User::factory()->create(['role' => 'superadmin', 'tenant_id' => null]);
    }

    /** @return array{0: Tenant, 1: User} */
    private function paidStore(): array
    {
        $owner = User::factory()->create(['tenant_id' => null, 'role' => 'store_admin']);
        $tenant = app(StoreProvisioner::class)->provision($owner, ['store_name' => 'Delta Optical']);

        $tenant->account->subscription->forceFill([
            'status' => 'active',
            'interval' => 'monthly',
            'current_period_end' => now()->addDays(300),
        ])->save();

        return [$tenant->fresh(), $owner->fresh()];
    }

    private function reversedPayment(Tenant $tenant, float $amount = 4999)
    {
        $this->actingAs($this->admin);
        $invoice = app(PaymentRecorder::class)
            ->record($tenant->account->subscription, $amount, 'cash');
        app(PaymentRecorder::class)->reverse($invoice, 'keyed twice by mistake');

        return $invoice->fresh();
    }

    /*
    |--------------------------------------------------------------------------
    | The row
    |--------------------------------------------------------------------------
    */

    public function test_a_reversed_payment_never_reads_as_paid(): void
    {
        [$tenant, $owner] = $this->paidStore();
        $this->reversedPayment($tenant);

        $response = $this->actingAs($owner)->get(route('tenant.billing.index'))->assertOk();

        $response->assertSee('Reversed')
            ->assertSee('text-decoration-line-through', false)
            // The exact defect: the raw DB status rendered in a green badge.
            // (`text-bg-success` alone is too broad to assert on — the
            // subscription's own status pill is legitimately green here.)
            ->assertDontSee('<span class="badge text-bg-success">paid</span>', false);
    }

    public function test_a_reversed_payment_is_still_shown_rather_than_hidden(): void
    {
        [$tenant, $owner] = $this->paidStore();
        $this->reversedPayment($tenant, 4999);

        // They saw the payment. Making it silently vanish would be worse than
        // showing it as a green "paid" — at least that is visible enough to
        // query.
        $this->actingAs($owner)->get(route('tenant.billing.index'))
            ->assertOk()
            ->assertSee('4,999.00');
    }

    public function test_a_normal_payment_still_reads_as_paid(): void
    {
        [$tenant, $owner] = $this->paidStore();

        $this->actingAs($this->admin);
        app(PaymentRecorder::class)->record($tenant->account->subscription, 499, 'cash');

        $this->actingAs($owner)->get(route('tenant.billing.index'))
            ->assertOk()
            ->assertSee('Paid')
            ->assertDontSee('Reversed');
    }

    /*
    |--------------------------------------------------------------------------
    | The banner (N-b)
    |--------------------------------------------------------------------------
    */

    public function test_the_customer_is_told_that_a_payment_was_reversed(): void
    {
        [$tenant, $owner] = $this->paidStore();
        $this->reversedPayment($tenant);

        $this->actingAs($owner)->get(route('tenant.billing.index'))
            ->assertOk()
            ->assertSee('A payment was reversed')
            // Reversal deliberately does not touch entitlement, so say so
            // rather than leaving them to worry.
            ->assertSee('Your access is unaffected');
    }

    public function test_an_old_reversal_is_not_still_announced(): void
    {
        [$tenant, $owner] = $this->paidStore();
        $invoice = $this->reversedPayment($tenant);
        $invoice->forceFill(['reversed_at' => now()->subMonths(6)])->save();

        // A notice, not a permanent scar. The history row below stays marked
        // forever, which is where an old reversal belongs.
        $this->actingAs($owner)->get(route('tenant.billing.index'))
            ->assertOk()
            ->assertDontSee('A payment was reversed')
            ->assertSee('Reversed');
    }

    public function test_the_operators_internal_reversal_reason_is_not_shown(): void
    {
        [$tenant, $owner] = $this->paidStore();
        $this->actingAs($this->admin);
        $invoice = app(PaymentRecorder::class)
            ->record($tenant->account->subscription, 4999, 'cash');
        app(PaymentRecorder::class)->reverse($invoice, 'customer disputed, possible fraud');

        // Same rule as the lock screen: reasons are written for the audit
        // trail, not addressed to the customer.
        $this->actingAs($owner)->get(route('tenant.billing.index'))
            ->assertOk()
            ->assertDontSee('possible fraud');
    }

    /*
    |--------------------------------------------------------------------------
    | The receipt (R-b — stamped, not withheld)
    |--------------------------------------------------------------------------
    */

    public function test_the_receipt_still_downloads_but_says_it_is_cancelled(): void
    {
        [$tenant, $owner] = $this->paidStore();
        $invoice = $this->reversedPayment($tenant);

        $response = $this->actingAs($owner)
            ->get(route('tenant.billing.invoices.pdf', $invoice))
            ->assertOk();

        // Withholding it would not un-file the copy they already saved; a
        // superseding document would. The filename carries it too, because a
        // folder of receipts is scanned by name, not opened one by one.
        $this->assertStringContainsString(
            'REVERSED',
            $response->headers->get('content-disposition'),
        );
    }

    public function test_the_reversed_receipt_renders_the_void_notice(): void
    {
        [$tenant, $owner] = $this->paidStore();
        $invoice = $this->reversedPayment($tenant);

        // Render the template directly — asserting on compiled PDF bytes tests
        // dompdf, not us.
        $html = view('tenant.billing.invoice-pdf', [
            'invoice' => $invoice,
            'tenant' => $tenant,
        ])->render();

        $this->assertStringContainsString('REVERSED', $html);
        $this->assertStringContainsString('This receipt has been cancelled', $html);
        $this->assertStringContainsString('no longer valid as proof of payment', $html);
        $this->assertStringContainsString('Total reversed', $html);
    }

    public function test_an_unreversed_receipt_carries_no_stamp(): void
    {
        [$tenant] = $this->paidStore();
        $this->actingAs($this->admin);
        $invoice = app(PaymentRecorder::class)
            ->record($tenant->account->subscription, 499, 'cash');

        $html = view('tenant.billing.invoice-pdf', [
            'invoice' => $invoice,
            'tenant' => $tenant,
        ])->render();

        $this->assertStringNotContainsString('REVERSED', $html);
        $this->assertStringContainsString('Total paid', $html);
    }

    /*
    |--------------------------------------------------------------------------
    | The operator's warning
    |--------------------------------------------------------------------------
    */

    public function test_the_operator_is_warned_when_a_reversal_touches_live_access(): void
    {
        [$tenant] = $this->paidStore();
        $subscription = $tenant->account->subscription;

        $this->actingAs($this->admin);
        app(PaymentRecorder::class)->record($subscription, 4999, 'cash', [
            'period_start' => now()->subDays(5),
            'period_end' => now()->addDays(300),   // this is what funds them
        ]);

        $this->actingAs($this->admin)->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('superadmin.accounts.show', $tenant->account_id))
            ->assertOk()
            ->assertSee('what currently covers their access', false)
            ->assertSee('leaves that access in place', false);
    }

    public function test_no_warning_for_a_payment_that_covers_nothing_current(): void
    {
        [$tenant] = $this->paidStore();

        $this->actingAs($this->admin);
        app(PaymentRecorder::class)->record($tenant->account->subscription, 4999, 'cash', [
            'period_start' => now()->subYear(),
            'period_end' => now()->subMonths(6),   // long expired
        ]);

        $this->actingAs($this->admin)->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('superadmin.accounts.show', $tenant->account_id))
            ->assertOk()
            ->assertDontSee('what currently covers their access', false);
    }

    /*
    |--------------------------------------------------------------------------
    | Unchanged by design
    |--------------------------------------------------------------------------
    */

    public function test_reversing_still_does_not_touch_their_access(): void
    {
        [$tenant, $owner] = $this->paidStore();
        $this->reversedPayment($tenant);

        // "I typed the wrong amount" and "they were refunded, cut them off" are
        // different intents; conflating them would surprise the operator.
        $this->assertSame('active', $tenant->fresh()->governingSubscription()->accessState());
        $this->actingAs($owner)->get(route('tenant.dashboard'))->assertOk();
    }
}
