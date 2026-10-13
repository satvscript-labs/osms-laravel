<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CredentialIssuer;
use App\Services\PriceResolver;
use App\Services\Proration;
use App\Services\StoreClosure;
use App\Services\StoreProvisioner;
use App\Services\SubscriptionLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Fixes from Claude's run of the Track A manual script (92), 2026-10-12.
 * Report: _artifacts/QA_TESTING_REPORT_SUPERADMIN_2026-10-12.md
 *
 * Each test asserts what a PERSON can now see or do — the operator at the panel,
 * the shop owner at the counter — because every one of these passed a "did the
 * field change?" check and failed the "what happens to them?" one.
 *
 *   QA-01/02  a lapsed password confirmation never silently drops a change
 *   QA-03     the receipt carries the receipt number the ledger shows
 *   QA-04     a branch pays only for paid cover, never for trial days
 *   QA-05     Renew now is priced like the renewal will be
 *   QA-06     a suspended customer is not labelled or counted as owing
 *   QA-07     a closed store is not invited to subscribe
 *   QA-08     every branch sees the payer's whole ledger, even after a purge
 *   SEC-01    a re-issued password ends the user's other sessions
 *   UI-*      copy and ordering
 */
class Phase83QaRunFixesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlanSeeder::class);
        $this->admin = User::factory()->create(['role' => 'superadmin', 'tenant_id' => null]);
        $this->actingAs($this->admin);
    }

    private function today(): Carbon
    {
        return Carbon::today(config('billing.timezone'));
    }

    private function store(string $name, ?Account $account = null): Tenant
    {
        $owner = User::factory()->create(['tenant_id' => null, 'role' => 'store_admin']);

        return app(StoreProvisioner::class)->provision($owner, ['store_name' => $name], $account);
    }

    private function ownerOf(Tenant $tenant): User
    {
        return User::where('tenant_id', $tenant->id)->where('role', 'store_admin')->firstOrFail();
    }

    /** Active, paid 20 days ahead, then one cash renewal on the ledger. */
    private function payingAccount(string $name = 'HQ'): Tenant
    {
        $hq = $this->store($name);
        $hq->account->subscription->forceFill([
            'status' => 'active', 'interval' => 'monthly',
            'current_period_end' => $this->today()->addDays(20),
        ])->save();

        app(SubscriptionLifecycle::class)->commit($hq->account->subscription->fresh(), 'renew', ['amount' => 499, 'method' => 'cash']);

        return $hq->fresh();
    }

    private function confirmed(): static
    {
        return $this->withSession(['auth.password_confirmed_at' => time()]);
    }

    private function ledgerCount(Account $account): int
    {
        return SubscriptionInvoice::withoutGlobalScopes()->where('account_id', $account->id)->count();
    }

    // ------------------------------------------------------------------
    // QA-01 / QA-02 — password confirmation never swallows a change
    // ------------------------------------------------------------------

    public function test_a_money_action_on_a_lapsed_confirmation_is_refused_out_loud_not_dropped(): void
    {
        $account = $this->payingAccount()->account;
        $page = route('superadmin.accounts.show', $account);
        $before = $this->ledgerCount($account);

        $this->from($page)
            ->post(route('superadmin.accounts.action', $account), ['action' => 'renew', 'amount' => 499, 'method' => 'cash'])
            ->assertRedirect(route('password.confirm'));

        $this->assertSame($before, $this->ledgerCount($account), 'nothing may be written before confirmation');
        $this->assertSame($page, session('url.intended'), 'they go back to the page they were on, not the POST url');

        // The confirm screen says it plainly …
        $this->get(route('password.confirm'))->assertOk()->assertSee('Your change has not been saved');

        // … and so does the page they land back on, in the toast that stays.
        $this->post(route('password.confirm'), ['password' => 'password'])
            ->assertRedirect($page)
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'NOT saved'));

        $this->assertSame($before, $this->ledgerCount($account), 'and still nothing was written behind their back');
    }

    public function test_the_in_page_prompt_never_inherits_a_stale_not_saved_warning(): void
    {
        // AUDIT-01 — a refusal the operator walked away from must not surface after
        // the prompt's OWN change succeeds: they would enter the money twice.
        $this->withSession([
            \App\Http\Middleware\RequirePasswordConfirmation::UNSAVED => time(),
            'url.intended' => route('superadmin.dashboard'),
        ]);

        $this->postJson(route('password.confirm'), ['password' => 'password'])
            ->assertOk()
            ->assertJson(['confirmed' => true]);

        $this->assertNull(session('error'));
        $this->assertFalse(session()->has(\App\Http\Middleware\RequirePasswordConfirmation::UNSAVED));
        $this->assertFalse(session()->has('url.intended'));
    }

    public function test_an_old_abandoned_refusal_is_not_reported_as_the_last_change(): void
    {
        $this->withSession([\App\Http\Middleware\RequirePasswordConfirmation::UNSAVED => time() - 3600]);

        $this->get(route('password.confirm'))->assertDontSee('Your change has not been saved');
        $this->post(route('password.confirm'), ['password' => 'password'])->assertSessionMissing('error');
    }

    public function test_a_store_owner_disabling_two_factor_on_a_lapsed_confirmation_is_told_it_was_not_saved(): void
    {
        // The one store-side route behind password.confirm. No browser guard there,
        // so the server safety net is what the owner meets.
        $owner = $this->ownerOf($this->store('2FA Shop'));
        $page = route('profile.edit');

        $this->actingAs($owner)->from($page)->delete(route('two-factor.disable'))
            ->assertRedirect(route('password.confirm'));
        $this->assertSame($page, session('url.intended'));

        $this->post(route('password.confirm'), ['password' => 'password'])
            ->assertRedirect($page)
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'NOT saved'));
    }

    public function test_an_ordinary_confirmation_carries_no_unsaved_warning(): void
    {
        $this->post(route('password.confirm'), ['password' => 'password'])->assertSessionMissing('error');
    }

    public function test_the_live_preview_reports_a_lapsed_confirmation_instead_of_no_change(): void
    {
        $account = $this->payingAccount()->account;

        $this->postJson(route('superadmin.accounts.preview', $account), ['action' => 'renew'])
            ->assertStatus(423)
            ->assertJson(['password_confirm_required' => true]);

        $this->confirmed()
            ->postJson(route('superadmin.accounts.preview', $account), ['action' => 'renew', 'amount' => 499])
            ->assertOk()
            ->assertJsonPath('changes.current_period_end.to', fn ($v) => $v !== null);
    }

    public function test_the_server_honours_the_configured_confirmation_window(): void
    {
        // The browser guard reads auth.password_timeout; the server must too, or
        // the two disagree about whether a change will be accepted.
        config(['auth.password_timeout' => 60]);
        $account = $this->payingAccount()->account;

        $this->withSession(['auth.password_confirmed_at' => time() - 120])
            ->postJson(route('superadmin.accounts.preview', $account), ['action' => 'renew'])
            ->assertStatus(423);

        $this->withSession(['auth.password_confirmed_at' => time() - 30])
            ->postJson(route('superadmin.accounts.preview', $account), ['action' => 'renew', 'amount' => 499])
            ->assertOk();
    }

    public function test_the_panel_tells_the_browser_how_long_the_confirmation_has_left(): void
    {
        $remaining = function ($response): ?int {
            preg_match('/name="osms-password-confirm"[^>]*?content="(\d+)"/s', $response->getContent(), $m);

            return isset($m[1]) ? (int) $m[1] : null;
        };

        $this->assertSame(0, $remaining($this->get(route('superadmin.dashboard'))), 'stale → 0 seconds left');

        $fresh = $remaining($this->confirmed()->get(route('superadmin.dashboard')));
        $this->assertGreaterThan(10000, $fresh, 'just confirmed → close to the full window');

        // Only the operator gets the guard; a shop owner's pages never carry it.
        $owner = $this->ownerOf($this->store('Plain Shop'));
        $this->actingAs($owner)->get(route('tenant.dashboard'))->assertDontSee('osms-password-confirm', false);
    }

    // ------------------------------------------------------------------
    // QA-03 — the receipt number on the receipt
    // ------------------------------------------------------------------

    public function test_the_receipt_pdf_and_history_carry_the_ledger_receipt_number(): void
    {
        $hq = $this->payingAccount();
        $invoice = SubscriptionInvoice::withoutGlobalScopes()->where('account_id', $hq->account_id)->firstOrFail();
        $this->assertNotEmpty($invoice->receipt_no);

        $html = view('tenant.billing.invoice-pdf', ['invoice' => $invoice, 'tenant' => $hq])->render();
        $this->assertStringContainsString('No: ' . $invoice->receipt_no, $html);
        $this->assertStringNotContainsString(strtoupper(substr($invoice->id, 0, 8)), $html);

        $this->actingAs($this->ownerOf($hq))->get(route('tenant.billing.index'))->assertSee($invoice->receipt_no);
    }

    // ------------------------------------------------------------------
    // QA-04 — a branch pays for paid cover, never for trial days
    // ------------------------------------------------------------------

    public function test_a_branch_added_before_an_early_renewal_starts_pays_one_period_not_more(): void
    {
        // Renewed early, during the trial: covered from the trial's end.
        $hq = $this->store('Early Payer');
        app(SubscriptionLifecycle::class)->commit($hq->account->subscription->fresh(), 'renew', ['amount' => 499, 'method' => 'cash']);
        $sub = $hq->account->subscription->fresh();
        $this->assertTrue($sub->current_period_end->gt($this->today()->addMonth()), 'precondition: cover starts after today');

        $q = app(Proration::class)->forNewBranch($sub);

        $this->assertTrue($q['applicable']);
        $this->assertSame($q['days_in_period'], $q['days_charged'], 'the trial days before the paid period are not charged');
        $this->assertEquals(499.0, $q['amount'], 'one whole period for the branch, as the first store paid — never "45 of 31"');
        $this->assertContains('Charged from', array_column($q['lines'], 'label'), 'and the working says where the charge starts');
    }

    public function test_a_branch_joining_inside_prepaid_cover_is_still_charged_for_it(): void
    {
        // Two periods prepaid from today: the join date sits INSIDE paid cover.
        $hq = $this->store('Prepaid');
        $hq->account->subscription->forceFill([
            'status' => 'active', 'interval' => 'monthly', 'current_period_end' => $this->today(),
        ])->save();
        $life = app(SubscriptionLifecycle::class);
        $life->commit($hq->account->subscription->fresh(), 'renew', ['amount' => 499, 'method' => 'cash']);
        $life->commit($hq->account->subscription->fresh(), 'renew', ['amount' => 499, 'method' => 'cash']);

        $q = app(Proration::class)->forNewBranch($hq->account->subscription->fresh(), $this->today()->addDays(5));

        $this->assertTrue($q['applicable']);
        $this->assertGreaterThan($q['days_in_period'], $q['days_charged'], 'paid cover runs past one period, and is charged');
        $this->assertGreaterThan(499.0, $q['amount']);
        // E2.5 / FB-13 — explained cycle by cycle (a part cycle, then whole ones),
        // not by a note beside a "45 of 31" count.
        $this->assertContains('Whole cycles', array_column($q['lines'], 'label'), 'the long span is explained, not left to look like a slip');
        $this->assertNotContains('Charged from', array_column($q['lines'], 'label'), 'it starts the day it joined');
    }

    public function test_the_part_period_actually_booked_matches_the_working(): void
    {
        $hq = $this->store('Booked');
        app(SubscriptionLifecycle::class)->commit($hq->account->subscription->fresh(), 'renew', ['amount' => 499, 'method' => 'cash']);

        $this->store('Booked — Branch 2', $hq->account->fresh());

        // E2 — the part period is a charge (owed), with the cover it was quoted for.
        $row = \App\Models\BillingCharge::query()
            ->where('account_id', $hq->account_id)->where('kind', 'branch_join')->firstOrFail();

        $this->assertEquals(499.0, (float) $row->amount);

        // AUDIT-02 — the row's cover is the stretch charged: the paid period, not the trial before it.
        $sub = $hq->account->subscription()->first();
        $this->assertSame($sub->current_period_end->copy()->subMonth()->toDateString(), $row->period_start->toDateString());
        $this->assertSame($sub->current_period_end->toDateString(), $row->period_end->toDateString());
    }

    public function test_a_branch_booked_inside_prepaid_cover_records_cover_from_its_join_date(): void
    {
        $hq = $this->store('Prepaid Booked');
        $hq->account->subscription->forceFill([
            'status' => 'active', 'interval' => 'monthly', 'current_period_end' => $this->today(),
        ])->save();
        $life = app(SubscriptionLifecycle::class);
        $life->commit($hq->account->subscription->fresh(), 'renew', ['amount' => 499, 'method' => 'cash']);
        $life->commit($hq->account->subscription->fresh(), 'renew', ['amount' => 499, 'method' => 'cash']);

        $this->store('Prepaid Booked — Branch 2', $hq->account->fresh());

        // E2 — the part period is a charge (owed), with the cover it was quoted for.
        $row = \App\Models\BillingCharge::query()
            ->where('account_id', $hq->account_id)->where('kind', 'branch_join')->firstOrFail();

        // Charged from today across two prepaid periods — and the row says exactly that.
        $this->assertSame($this->today()->toDateString(), $row->period_start->toDateString());
        $this->assertGreaterThan(499.0, (float) $row->amount);
    }

    // ------------------------------------------------------------------
    // QA-05 — Renew now priced like the renewal
    // ------------------------------------------------------------------

    public function test_after_a_branch_closes_renew_is_priced_for_the_branches_left(): void
    {
        $hq = $this->payingAccount();
        $account = $hq->account;
        $b2 = $this->store('Branch 2', $account->fresh());
        app(StoreClosure::class)->close($b2->fresh(), 'test');

        $sub = $account->subscription()->first();
        $prices = app(PriceResolver::class);

        $this->assertEquals(998.0, $prices->effectivePrice($sub), 'this cycle: still two, they paid for it (D4)');
        $this->assertEquals(499.0, $prices->renewalPrice($sub, 'monthly'), 'next cycle: one');
        $this->assertEquals(4999.0, $prices->renewalPrice($sub, 'yearly'));

        // The form is pre-filled with exactly that, per period.
        $this->confirmed()->get(route('superadmin.accounts.show', $account))
            ->assertOk()
            ->assertViewHas('renewPrices', fn ($p) => $p['monthly'] == 499.0 && $p['yearly'] == 4999.0);

        // And a renewal left at the default charges the same number.
        // The NEW row, found by elimination: every row here shares one second.
        $before = SubscriptionInvoice::withoutGlobalScopes()->where('account_id', $account->id)->pluck('id');
        app(SubscriptionLifecycle::class)->commit($sub->fresh(), 'renew', ['amount' => '', 'method' => 'cash']);
        $this->assertEquals(499.0, (float) SubscriptionInvoice::withoutGlobalScopes()
            ->where('account_id', $account->id)->whereNotIn('id', $before)->value('amount'));
    }

    public function test_mark_as_paid_after_a_branch_closes_defaults_to_the_branches_left(): void
    {
        // AUDIT-03 — Mark as paid starts a new cycle like a renewal, so it is
        // priced like one; its default used to be worked out before the re-count.
        $hq = $this->payingAccount();
        $account = $hq->account;
        $b2 = $this->store('Branch 2', $account->fresh());
        app(StoreClosure::class)->close($b2->fresh(), 'test');

        $sub = $account->subscription()->first();
        $sub->forceFill(['status' => 'past_due', 'current_period_end' => $this->today()->subDays(3)])->save();

        app(SubscriptionLifecycle::class)->commit($sub->fresh(), 'mark_paid', ['reason' => 'paid at counter', 'amount' => '']);

        // Picked by its reason, not by "newest": every row here shares one second.
        $row = SubscriptionInvoice::withoutGlobalScopes()->where('account_id', $account->id)
            ->where('reason', 'paid at counter')->firstOrFail();
        $this->assertEquals(499.0, (float) $row->amount);
        $this->assertNull($row->list_amount, 'and it is the full price, not a bargain');
    }

    public function test_expected_in_30_days_does_not_expect_a_closed_branch_again(): void
    {
        $hq = $this->payingAccount();
        $account = $hq->account;
        $b2 = $this->store('Branch 2', $account->fresh());
        app(StoreClosure::class)->close($b2->fresh(), 'test');
        $account->subscription()->first()->forceFill(['current_period_end' => $this->today()->addDays(5)])->save();

        $collection = app(\App\Support\Metrics::class)->collection();

        $this->assertSame(1, $collection['due_soon_count']);
        $this->assertEquals(499.0, $collection['due_soon_amount'], 'one branch renews, not two');
    }

    // ------------------------------------------------------------------
    // QA-06 — suspended is not owed
    // ------------------------------------------------------------------

    public function test_a_suspended_paid_customer_is_neither_labelled_nor_counted_as_owing(): void
    {
        $paused = $this->payingAccount('Paused')->account;
        app(SubscriptionLifecycle::class)->commit($paused->subscription()->first(), 'suspend', ['reason' => 'cheque bounced']);

        // A genuinely overdue customer alongside, so "owed" still works.
        $owing = $this->store('Owing')->account;
        $owing->subscription()->first()->forceFill([
            'status' => 'past_due', 'current_period_end' => $this->today()->subDays(3),
        ])->save();

        $sub = $paused->subscription()->first();
        $this->assertSame('suspended', $sub->displayStatus());
        $this->assertSame('past_due', $owing->subscription()->first()->displayStatus());
        $this->assertSame(1, Subscription::withoutGlobalScopes()->overdue()->count());

        $this->get(route('superadmin.dashboard'))
            ->assertViewHas('stats', fn ($s) => $s['past_due'] === 1 && $s['suspended'] === 1)
            ->assertViewHas('worklist', function ($rows) {
                $byName = collect($rows)->keyBy('account');

                return $byName->contains(fn ($r) => $r['status'] === 'suspended' && $r['why'] === 'Suspended by you')
                    && $byName->contains(fn ($r) => $r['status'] === 'past_due' && $r['why'] === 'Payment overdue');
            })
            ->assertSee('1 suspended by you, not counted');

        // Customers list: labelled Suspended, and not filed under "Lapsed".
        $this->getJson(route('superadmin.accounts.index', ['filter' => 'all']))
            ->assertJsonFragment(['status' => 'suspended']);
        $lapsed = collect($this->getJson(route('superadmin.accounts.index', ['filter' => 'lapsed']))->json('rows'))->pluck('status');
        $this->assertSame(['past_due'], $lapsed->all());

        // The "Overdue" levers are not offered for a customer who owes nothing.
        $this->confirmed()->get(route('superadmin.accounts.show', $paused))
            ->assertSee('Suspended')
            ->assertDontSee('data-bs-target="#m-waive"', false);
        // E2 / D-5 — Mark as paid is now Record a payment; Waive is the overdue lever left.
        $this->confirmed()->get(route('superadmin.accounts.show', $owing))
            ->assertSee('data-bs-target="#m-waive"', false);
    }

    // ------------------------------------------------------------------
    // QA-07 — no "Subscribe now" where there is nothing to subscribe to
    // ------------------------------------------------------------------

    public function test_a_closed_trial_store_is_not_invited_to_subscribe(): void
    {
        $shop = $this->store('Closing Trial');
        $owner = $this->ownerOf($shop);
        app(StoreClosure::class)->close($shop->fresh(), 'owner asked');

        $this->actingAs($owner)->get(route('tenant.locked'))
            ->assertOk()
            ->assertSee('This store has been closed')
            ->assertDontSee('Subscribe now');
    }

    public function test_a_customer_billed_by_hand_is_pointed_at_us_not_at_a_checkout(): void
    {
        $handled = $this->store('By Hand');
        $handled->account->forceFill(['supervised' => true])->save();
        $plain = $this->store('Self Serve');

        $this->actingAs($this->ownerOf($handled))->get(route('tenant.dashboard'))
            ->assertSee('Our team handles your billing')
            ->assertDontSee('Subscribe now');

        $this->actingAs($this->ownerOf($plain))->get(route('tenant.dashboard'))
            ->assertSee('Subscribe now');
    }

    // ------------------------------------------------------------------
    // QA-08 — one payer, one ledger, wherever they sign in
    // ------------------------------------------------------------------

    public function test_every_branch_sees_the_payers_whole_ledger_and_nothing_else(): void
    {
        $other = $this->payingAccount('Someone Else');
        $theirs = SubscriptionInvoice::withoutGlobalScopes()->where('account_id', $other->account_id)->firstOrFail();

        $hq = $this->payingAccount('HQ');
        $branch = $this->store('Branch 2', $hq->account->fresh());
        $paidAtHq = SubscriptionInvoice::withoutGlobalScopes()->where('tenant_id', $hq->id)->firstOrFail();
        $branchOwner = $this->ownerOf($branch);

        $this->actingAs($branchOwner)->get(route('tenant.billing.index'))
            ->assertOk()
            ->assertSee($paidAtHq->receipt_no)
            ->assertDontSee($theirs->receipt_no);
        $this->get(route('tenant.billing.invoices.pdf', $paidAtHq))->assertOk();
        $this->get(route('tenant.billing.invoices.pdf', $theirs))->assertNotFound();

        // After the first store is purged its rows are kept but tagged to no
        // store (FB-22). The branch that is still trading must still see them.
        SubscriptionInvoice::withoutGlobalScopes()->where('tenant_id', $hq->id)->update(['tenant_id' => null]);

        $this->get(route('tenant.billing.index'))->assertSee($paidAtHq->receipt_no)->assertDontSee('No payments yet');
        $this->get(route('tenant.billing.invoices.pdf', $paidAtHq))->assertOk();
    }

    // ------------------------------------------------------------------
    // SEC-01 — a re-issued password ends the other sessions
    // ------------------------------------------------------------------

    public function test_reissuing_a_password_signs_that_user_out_of_sessions_already_open(): void
    {
        $owner = $this->ownerOf($this->store('Leaky'));
        auth()->logout();

        $this->post(route('login'), ['email' => $owner->email, 'password' => 'password'])->assertRedirect();

        // Control: a later request in the same session is still signed in.
        $this->app['auth']->forgetGuards();
        $this->get(route('tenant.dashboard'))->assertOk();

        app(CredentialIssuer::class)->reissue($owner->fresh(), 'someone else knew it');

        $this->app['auth']->forgetGuards();
        $this->get(route('tenant.dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_view_as_store_survives_session_authentication_with_a_real_sign_in(): void
    {
        // AUDIT-04 — AuthenticateSession compares each session's stored password
        // hash with the signed-in user's. "View as" switches the user inside the
        // session; with a real sign-in (not actingAs) every hop must keep working.
        $shop = $this->store('Viewed');
        $owner = $this->ownerOf($shop);
        auth()->logout();

        $this->post(route('login'), ['email' => $this->admin->email, 'password' => 'password'])->assertRedirect();
        $this->app['auth']->forgetGuards();

        $this->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('superadmin.accounts.impersonate', [$shop->account_id, $owner]), ['reason' => 'support'])
            ->assertRedirect();

        foreach ([route('tenant.dashboard'), route('tenant.customers.index')] as $page) {
            $this->app['auth']->forgetGuards();
            $this->get($page)->assertOk()->assertSee('impersonation-band', false);
        }

        $this->app['auth']->forgetGuards();
        $this->post(route('impersonation.stop'))->assertRedirect();

        $this->app['auth']->forgetGuards();
        $this->get(route('superadmin.dashboard'))->assertOk();
        $this->assertAuthenticatedAs($this->admin->fresh());
    }

    public function test_changing_your_own_password_keeps_you_signed_in(): void
    {
        $owner = $this->ownerOf($this->store('Mine'));
        auth()->logout();
        $this->post(route('login'), ['email' => $owner->email, 'password' => 'password']);

        $this->put(route('password.update'), [
            'current_password' => 'password',
            'password' => 'a-new-Passw0rd!',
            'password_confirmation' => 'a-new-Passw0rd!',
        ])->assertSessionHasNoErrors();

        $this->app['auth']->forgetGuards();
        $this->get(route('tenant.dashboard'))->assertOk();
    }

    // ------------------------------------------------------------------
    // UI — copy and order
    // ------------------------------------------------------------------

    public function test_the_owner_never_sees_developer_instructions_about_gateway_keys(): void
    {
        config(['services.razorpay.key' => null, 'services.razorpay.secret' => null]);
        $owner = $this->ownerOf($this->store('No Gateway'));

        $this->actingAs($owner)->get(route('tenant.billing.index'))
            ->assertOk()
            ->assertDontSee('.env')
            ->assertSee(config('saas.support_email'));
    }

    public function test_the_add_store_page_says_what_joining_today_will_charge(): void
    {
        $trial = $this->store('Still Trialling');
        $this->confirmed()->get(route('superadmin.accounts.create', ['account' => $trial->account_id]))
            ->assertOk()
            ->assertSee('No part-period charge today')
            ->assertDontSee('at their next renewal');

        $paying = $this->payingAccount('Paying');
        $this->confirmed()->get(route('superadmin.accounts.create', ['account' => $paying->account_id]))
            // A-12 (E2) — a bill owed until paid, over the span it rides; never "N of M days".
            ->assertSee('Adding it today raises a bill of')
            ->assertSee('It is owed until they pay it')
            ->assertDontSee('on their ledger and receipt');
    }

    public function test_the_newest_entry_of_a_day_reads_first_even_when_entered_by_date_only(): void
    {
        $hq = $this->payingAccount();
        $first = SubscriptionInvoice::withoutGlobalScopes()->where('account_id', $hq->account_id)->firstOrFail();
        $first->forceFill(['paid_at' => $this->today()->setTime(15, 0), 'created_at' => now()->subHour()])->save();

        $this->travel(1)->minutes();
        $latest = app(\App\Services\PaymentRecorder::class)->record(
            $hq->account->subscription()->first(), 300, 'cash', ['paid_at' => $this->today()],
        );

        $this->assertSame($latest->id, SubscriptionInvoice::withoutGlobalScopes()
            ->where('account_id', $hq->account_id)->newestFirst()->value('id'));
    }

    public function test_a_bargained_receipt_reads_down_as_one_sum(): void
    {
        $hq = $this->payingAccount();
        $row = SubscriptionInvoice::withoutGlobalScopes()->where('account_id', $hq->account_id)->firstOrFail();
        $row->forceFill(['amount' => 400, 'list_amount' => 499, 'discount_amount' => 99, 'discount_reason' => 'counter'])->save();

        $html = view('tenant.billing.invoice-pdf', ['invoice' => $row->fresh(), 'tenant' => $hq])->render();

        // Line ₹499, Subtotal ₹499, Discount −₹99, Total ₹400.
        $this->assertSame(2, substr_count($html, '₹ 499.00'));
        $this->assertStringContainsString('− ₹ 99.00', $html);
        $this->assertStringContainsString('₹ 400.00', $html);
    }
}
