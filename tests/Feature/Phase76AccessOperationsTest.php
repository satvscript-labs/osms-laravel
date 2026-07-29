<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AdminAuditLog;
use App\Models\Customer;
use App\Models\PlatformSetting;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CredentialIssuer;
use App\Services\StoreClosure;
use App\Services\StoreProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * P5 — access & operations.
 *
 * Matrix rows 1–2 (operator provisioning), 14 (credentials) and 16 (closure),
 * plus read-only "view as store".
 *
 * The security properties here are the ones that must be PROVEN rather than
 * intended: a read-only session that can write, a credential in a log, or a
 * delete that outruns its retention window are each a one-line mistake with no
 * visible symptom until it matters.
 */
class Phase76AccessOperationsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlanSeeder::class);
        $this->admin = User::factory()->create(['role' => 'superadmin', 'tenant_id' => null]);
    }

    private function asOperator(): self
    {
        $this->actingAs($this->admin)->withSession(['auth.password_confirmed_at' => time()]);

        return $this;
    }

    /** @return array{0: Tenant, 1: User} */
    private function store(string $name = 'Sahaj Optical'): array
    {
        $owner = User::factory()->create(['tenant_id' => null, 'role' => 'store_admin', 'name' => 'Rushi']);
        $tenant = app(StoreProvisioner::class)->provision($owner, ['store_name' => $name]);

        return [$tenant, $owner->fresh()];
    }

    /** Patient rows for a store, without a factory (Customer has none). */
    private function seedCustomers(Tenant $tenant, int $n, string $name = 'Patient'): \Illuminate\Support\Collection
    {
        return collect(range(1, $n))->map(fn ($i) => Customer::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => $n === 1 ? $name : "{$name} {$i}",
            'phone' => '90000000' . str_pad((string) $i, 2, '0', STR_PAD_LEFT),
            'data_consent_at' => now(),
        ]));
    }

    /*
    |--------------------------------------------------------------------------
    | Rows 1–2 · Operator provisioning
    |--------------------------------------------------------------------------
    */

    public function test_operator_provisions_a_customer_store_and_login_in_one_step(): void
    {
        $response = $this->asOperator()->post(route('superadmin.accounts.store'), [
            'owner_name' => 'Rushi Patel',
            'owner_email' => 'rushi@example.com',
            'store_name' => 'Sahaj Optical',
            'plan_code' => 'basic',
            'trial_days' => 21,
        ]);

        $owner = User::where('email', 'rushi@example.com')->first();
        $this->assertNotNull($owner, 'the owner must exist and be able to sign in');
        $this->assertSame('store_admin', $owner->role);

        $tenant = Tenant::where('store_name', 'Sahaj Optical')->first();
        $this->assertNotNull($tenant);
        $this->assertSame($tenant->id, $owner->tenant_id);

        // 06 §6 — the customer is the PERSON. The account is named from them.
        $this->assertNotNull($tenant->account_id);
        $this->assertSame('Rushi Patel', $tenant->account->name);

        $response->assertRedirect(route('superadmin.accounts.show', $tenant->account_id));
    }

    public function test_the_password_is_shown_exactly_once_and_never_stored_or_logged(): void
    {
        $this->asOperator()->post(route('superadmin.accounts.store'), [
            'owner_name' => 'Rushi Patel',
            'owner_email' => 'rushi@example.com',
            'store_name' => 'Sahaj Optical',
        ])->assertSessionHas('credential');

        $password = session('credential')['password'];
        $owner = User::where('email', 'rushi@example.com')->first();

        // It is a real, working credential…
        $this->assertTrue(Hash::check($password, $owner->password));

        // …and it exists nowhere else. A secret in an audit row is a secret
        // retained forever by the surface whose job is to be readable.
        $audit = AdminAuditLog::where('action', 'store.provisioned')->firstOrFail();
        $this->assertStringNotContainsString($password, json_encode($audit->meta));
        $this->assertStringNotContainsString($password, $audit->description);
    }

    public function test_provisioning_refuses_an_email_already_in_use_and_creates_nothing(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);
        $before = Tenant::count();

        $this->asOperator()->post(route('superadmin.accounts.store'), [
            'owner_name' => 'Someone Else',
            'owner_email' => 'taken@example.com',
            'store_name' => 'Second Shop',
        ])->assertSessionHas('error');

        // The failure mode that matters: a store nobody can sign in to.
        $this->assertSame($before, Tenant::count());
    }

    public function test_a_second_branch_joins_the_payers_existing_clock(): void
    {
        [$tenant] = $this->store();
        $account = $tenant->account;
        $clock = $account->subscription;

        $this->asOperator()->post(route('superadmin.accounts.store'), [
            'owner_name' => 'Branch Manager',
            'owner_email' => 'branch@example.com',
            'store_name' => 'Sahaj Optical — Adajan',
            'account_id' => $account->id,
        ]);

        $this->assertSame(2, $account->stores()->count());

        // One customer, one clock — however many shops (the P2 invariant).
        $this->assertSame(1, Subscription::withoutGlobalScopes()->where('account_id', $account->id)->count());
        $this->assertEquals(
            $clock->current_period_end->toDateString(),
            $account->fresh()->subscription->current_period_end->toDateString(),
            'adding a branch must never move the renewal date',
        );
    }

    public function test_operator_trial_length_is_honoured_including_zero(): void
    {
        $this->asOperator()->post(route('superadmin.accounts.store'), [
            'owner_name' => 'Paid Upfront',
            'owner_email' => 'paid@example.com',
            'store_name' => 'Cash Optical',
            'trial_days' => 0,
        ]);

        $tenant = Tenant::where('store_name', 'Cash Optical')->firstOrFail();
        $subscription = Subscription::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();

        // 0 is a real choice, not "unset" — someone who paid on the spot should
        // not also get a fortnight free.
        $this->assertTrue($subscription->current_period_end->isToday());
    }

    /*
    |--------------------------------------------------------------------------
    | Row 14 · Credentials
    |--------------------------------------------------------------------------
    */

    public function test_reissuing_a_password_replaces_it_and_signs_out_remembered_devices(): void
    {
        [$tenant, $owner] = $this->store();
        $oldHash = $owner->password;
        $oldToken = 'a-remembered-device-token';
        $owner->forceFill(['remember_token' => $oldToken])->save();

        $this->asOperator()
            ->post(route('superadmin.accounts.credential', [$tenant->account_id, $owner]), [
                'reason' => 'locked out — reset email bounced',
            ])
            ->assertSessionHas('credential');

        $owner->refresh();
        $this->assertNotSame($oldHash, $owner->password);
        $this->assertTrue(Hash::check(session('credential')['password'], $owner->password));

        // Handing out a new password while the old browser stays signed in is
        // not a reset.
        $this->assertNotSame($oldToken, $owner->remember_token);
    }

    public function test_the_reissued_secret_never_reaches_the_audit_log(): void
    {
        [$tenant, $owner] = $this->store();

        $password = app(CredentialIssuer::class)->reissue($owner, 'support call');

        $audit = AdminAuditLog::where('action', 'user.credential_reissued')->firstOrFail();
        $this->assertStringNotContainsString($password, json_encode($audit->meta) . $audit->description);
        $this->assertSame('support call', $audit->meta['reason']);
    }

    public function test_credentials_cannot_be_reissued_across_customers(): void
    {
        [$mine] = $this->store('Mine');
        [$theirs, $theirOwner] = $this->store('Theirs');

        // A valid user id from another account, posted at this account's URL.
        $this->asOperator()
            ->post(route('superadmin.accounts.credential', [$mine->account_id, $theirOwner]), [
                'reason' => 'trying it on',
            ])
            ->assertNotFound();
    }

    public function test_an_operators_own_password_is_not_reissuable_from_the_panel(): void
    {
        [$tenant] = $this->store();
        $peer = User::factory()->create(['role' => 'superadmin', 'tenant_id' => $tenant->id]);

        // 404 (not of this account) or 403 (is an operator) — either refusal is
        // correct; what must never happen is a new password.
        $response = $this->asOperator()
            ->post(route('superadmin.accounts.credential', [$tenant->account_id, $peer]), ['reason' => 'x']);

        $this->assertContains($response->status(), [403, 404]);
        $this->assertDatabaseMissing('admin_audit_logs', ['action' => 'user.credential_reissued']);
    }

    /*
    |--------------------------------------------------------------------------
    | View as store — read-only, time-boxed, audited both ways
    |--------------------------------------------------------------------------
    */

    public function test_impersonation_sees_the_store_and_is_audited_on_entry(): void
    {
        [$tenant, $owner] = $this->store();
        $this->seedCustomers($tenant, 3);

        $this->asOperator()
            ->post(route('superadmin.accounts.impersonate', [$tenant->account_id, $owner]), [
                'reason' => 'support call — their list looks empty',
            ])
            ->assertRedirect(route('tenant.dashboard'));

        $this->assertAuthenticatedAs($owner);

        $entry = AdminAuditLog::where('action', 'impersonation.started')->firstOrFail();
        $this->assertSame($this->admin->id, $entry->admin_user_id);
        $this->assertSame('support call — their list looks empty', $entry->meta['reason']);
        $this->assertTrue($entry->meta['read_only']);
    }

    public function test_an_impersonated_session_cannot_write_anything(): void
    {
        [$tenant, $owner] = $this->store();
        $customer = $this->seedCustomers($tenant, 1, 'Original')->first();

        $this->asOperator()->post(route('superadmin.accounts.impersonate', [$tenant->account_id, $owner]), [
            'reason' => 'support',
        ]);

        // Reading is the whole point, and must still work — and every page it
        // reaches must be unmistakably marked, or the operator forgets whose
        // screen they are on.
        $this->get(route('tenant.customers.index'))
            ->assertOk()
            ->assertSee('impersonation-band', false)
            ->assertSee('read-only', false);

        // Writing is refused on the VERB, so routes nobody thought about are
        // covered too.
        $this->put(route('tenant.customers.update', $customer), ['name' => 'Changed'])
            ->assertForbidden();
        $this->delete(route('tenant.customers.destroy', $customer))->assertForbidden();

        $this->assertSame('Original', $customer->fresh()->name);
    }

    public function test_leaving_restores_the_operator_and_audits_the_exit(): void
    {
        [$tenant, $owner] = $this->store();

        $this->asOperator()->post(route('superadmin.accounts.impersonate', [$tenant->account_id, $owner]), [
            'reason' => 'support',
        ]);

        $this->post(route('impersonation.stop'))->assertRedirect(route('superadmin.dashboard'));

        $this->assertAuthenticatedAs($this->admin);

        // An entry-only trail tells you somebody went in but never that they
        // came out — which is exactly the question asked afterwards.
        $exit = AdminAuditLog::where('action', 'impersonation.ended')->firstOrFail();
        $this->assertSame($this->admin->id, $exit->admin_user_id, 'the exit belongs to the operator, not the customer');
        $this->assertSame('operator', $exit->meta['ended_by']);
        $this->assertNotNull($exit->meta['duration_seconds']);
    }

    public function test_the_session_expires_on_its_own_and_returns_the_operator(): void
    {
        [$tenant, $owner] = $this->store();

        $this->asOperator()->post(route('superadmin.accounts.impersonate', [$tenant->account_id, $owner]), [
            'reason' => 'support',
        ]);

        $this->travel(config('saas.impersonation_minutes') + 1)->minutes();

        $this->get(route('tenant.dashboard'))->assertRedirect(route('superadmin.dashboard'));

        $this->assertAuthenticatedAs($this->admin);
        $this->assertSame('expired', AdminAuditLog::where('action', 'impersonation.ended')->firstOrFail()->meta['ended_by']);
    }

    public function test_an_operator_can_never_be_impersonated(): void
    {
        [$tenant] = $this->store();
        $peer = User::factory()->create(['role' => 'superadmin', 'tenant_id' => $tenant->id]);

        $response = $this->asOperator()
            ->post(route('superadmin.accounts.impersonate', [$tenant->account_id, $peer]), ['reason' => 'x']);

        $this->assertContains($response->status(), [302, 403, 404]);
        $this->assertAuthenticatedAs($this->admin);
        $this->assertDatabaseMissing('admin_audit_logs', ['action' => 'impersonation.started']);
    }

    public function test_impersonation_cannot_be_started_by_a_store_admin(): void
    {
        [$tenant, $owner] = $this->store();

        $this->actingAs($owner)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('superadmin.accounts.impersonate', [$tenant->account_id, $owner]), ['reason' => 'x'])
            ->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | Row 16 · Closure
    |--------------------------------------------------------------------------
    */

    public function test_closing_a_store_locks_it_without_destroying_anything(): void
    {
        [$tenant, $owner] = $this->store();
        $this->seedCustomers($tenant, 4);

        $this->asOperator()
            ->post(route('superadmin.accounts.store.close', [$tenant->account_id, $tenant]), [
                'reason' => 'shop sold',
            ])
            ->assertSessionHas('status');

        $tenant->refresh();
        $this->assertTrue($tenant->isClosed());
        $this->assertFalse($tenant->hasActiveAccess());
        $this->assertNotNull($tenant->purge_after);

        // The point of closing rather than deleting: everything is still there.
        $this->assertSame(4, Customer::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());

        // And the owner is locked out — of the workspace AND of the pay page,
        // because taking money for a relationship you ended is worse than a
        // dead end.
        $this->actingAs($owner)->get(route('tenant.dashboard'))->assertRedirect(route('tenant.locked'));
        $this->actingAs($owner)->get(route('tenant.billing.index'))->assertRedirect(route('tenant.locked'));
    }

    public function test_closing_one_branch_leaves_the_customers_subscription_alone(): void
    {
        [$first] = $this->store('Main');
        $account = $first->account;
        $second = app(StoreProvisioner::class)->provision(
            User::factory()->create(['tenant_id' => null, 'role' => 'store_admin']),
            ['store_name' => 'Branch'],
            $account,
        );

        app(StoreClosure::class)->close($second, 'branch closed');

        // Closing a shop must never stop the money for the others.
        $this->assertSame('trialing', $account->fresh()->subscription->status);
        $this->assertTrue($first->fresh()->hasActiveAccess());
    }

    public function test_reopening_inside_the_window_restores_everything(): void
    {
        [$tenant, $owner] = $this->store();
        app(StoreClosure::class)->close($tenant, 'mistake');

        $this->asOperator()
            ->post(route('superadmin.accounts.store.reopen', [$tenant->account_id, $tenant]), [
                'reason' => 'they changed their mind',
            ]);

        $tenant->refresh();
        $this->assertFalse($tenant->isClosed());
        $this->assertNull($tenant->purge_after);
        $this->assertTrue($tenant->hasActiveAccess());
        $this->actingAs($owner)->get(route('tenant.dashboard'))->assertOk();
    }

    public function test_data_cannot_be_destroyed_before_the_retention_window_elapses(): void
    {
        [$tenant] = $this->store();
        app(StoreClosure::class)->close($tenant, 'closing');

        $this->asOperator()
            ->delete(route('superadmin.accounts.store.purge', [$tenant->account_id, $tenant]), [
                'confirm_name' => $tenant->store_name,
                'reason' => 'impatient',
            ])
            ->assertSessionHas('error');

        // The window IS the safety mechanism — it must not be clickable-past.
        $this->assertDatabaseHas('tenants', ['id' => $tenant->id]);
    }

    public function test_a_live_store_cannot_be_purged_at_all(): void
    {
        [$tenant] = $this->store();

        $this->asOperator()
            ->delete(route('superadmin.accounts.store.purge', [$tenant->account_id, $tenant]), [
                'confirm_name' => $tenant->store_name,
                'reason' => 'skipping the close step',
            ])
            ->assertSessionHas('error');

        $this->assertDatabaseHas('tenants', ['id' => $tenant->id]);
    }

    public function test_purging_requires_the_store_name_typed_exactly(): void
    {
        [$tenant] = $this->store();
        app(StoreClosure::class)->close($tenant, 'closing');
        $tenant->forceFill(['purge_after' => now()->subDay()])->save();

        $this->asOperator()
            ->delete(route('superadmin.accounts.store.purge', [$tenant->account_id, $tenant]), [
                'confirm_name' => mb_strtolower($tenant->store_name),   // near miss
                'reason' => 'done with them',
            ])
            ->assertSessionHas('error');

        $this->assertDatabaseHas('tenants', ['id' => $tenant->id]);
    }

    public function test_purging_after_the_window_removes_everything_including_the_users(): void
    {
        [$tenant, $owner] = $this->store();
        $this->seedCustomers($tenant, 3);
        $staff = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'staff']);

        app(StoreClosure::class)->close($tenant, 'shop closed');
        $tenant->forceFill(['purge_after' => now()->subDay()])->save();

        $this->asOperator()
            ->delete(route('superadmin.accounts.store.purge', [$tenant->account_id, $tenant]), [
                'confirm_name' => $tenant->store_name,
                'reason' => 'window elapsed',
            ])
            ->assertSessionHas('status');

        $this->assertDatabaseMissing('tenants', ['id' => $tenant->id]);
        $this->assertSame(0, Customer::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());

        // The production incident this logic came from: users are nullOnDelete,
        // so a plain cascade STRANDS them — and their email then blocks that
        // person from ever signing up again.
        $this->assertDatabaseMissing('users', ['id' => $owner->id]);
        $this->assertDatabaseMissing('users', ['id' => $staff->id]);

        $audit = AdminAuditLog::where('action', 'store.purged')->firstOrFail();
        $this->assertTrue($audit->meta['verified_clean']);
    }

    public function test_closure_actions_cannot_reach_across_customers(): void
    {
        [$mine] = $this->store('Mine');
        [$theirs] = $this->store('Theirs');

        $this->asOperator()
            ->post(route('superadmin.accounts.store.close', [$mine->account_id, $theirs]), ['reason' => 'x'])
            ->assertNotFound();

        $this->assertFalse($theirs->fresh()->isClosed());
    }

    /*
    |--------------------------------------------------------------------------
    | Ops health — honest signals only
    |--------------------------------------------------------------------------
    */

    public function test_the_scheduler_reports_unknown_before_its_first_heartbeat(): void
    {
        $checks = collect(app(\App\Support\PlatformHealth::class)->checks())->keyBy('key');

        // A check that cannot tell "healthy" from "never ran" is worse than none.
        $this->assertSame('Unknown', $checks['scheduler']['value']);
        $this->assertSame('neutral', $checks['scheduler']['tone']);
    }

    public function test_the_scheduler_reports_stalled_when_the_heartbeat_goes_stale(): void
    {
        PlatformSetting::set(PlatformSetting::SCHEDULER_HEARTBEAT, now()->subHour()->toIso8601String());

        $checks = collect(app(\App\Support\PlatformHealth::class)->checks())->keyBy('key');

        $this->assertSame('Stalled', $checks['scheduler']['value']);
        $this->assertSame('red', $checks['scheduler']['tone']);
    }

    /*
    |--------------------------------------------------------------------------
    | The surfaces render — every P5 state, including the destructive ones
    |--------------------------------------------------------------------------
    */

    public function test_the_provisioning_form_renders_in_both_modes(): void
    {
        [$tenant] = $this->store();

        $this->asOperator()->get(route('superadmin.accounts.create'))
            ->assertOk()
            ->assertSee('Set up a customer');

        $this->asOperator()->get(route('superadmin.accounts.create', ['account' => $tenant->account_id]))
            ->assertOk()
            ->assertSee('existing subscription', false);
    }

    public function test_the_customer_360_renders_the_access_and_closure_surfaces(): void
    {
        [$tenant, $owner] = $this->store();

        $this->asOperator()->get(route('superadmin.accounts.show', $tenant->account_id))
            ->assertOk()
            ->assertSee($owner->email)      // Access tab
            ->assertSee('Close');           // closure lever

        // And again once closed, when the purge modal and its row count appear.
        app(StoreClosure::class)->close($tenant, 'closing');
        $tenant->forceFill(['purge_after' => now()->subDay()])->save();

        $this->asOperator()->get(route('superadmin.accounts.show', $tenant->account_id))
            ->assertOk()
            ->assertSee('Delete permanently')
            ->assertSee('rows', false);
    }

    public function test_the_platform_surface_renders_the_health_checks(): void
    {
        $this->asOperator()->get(route('superadmin.platform.index'))
            ->assertOk()
            ->assertSee('Database backup')
            ->assertSee('Scheduler');
    }

    /*
    |--------------------------------------------------------------------------
    | The self-serve lane is untouched (owner constraint C1)
    |--------------------------------------------------------------------------
    */

    public function test_self_signup_provisioning_is_unchanged_by_the_operator_door(): void
    {
        $user = User::factory()->create(['tenant_id' => null, 'role' => 'store_admin']);

        $this->actingAs($user)->post(route('onboarding.store'), [
            'store_name' => 'Self Serve Optical',
        ])->assertRedirect();

        $tenant = $user->fresh()->tenant;
        $this->assertNotNull($tenant);
        $this->assertNotNull($tenant->account_id, 'a self-signing customer must still get an account');
        $this->assertNotNull($tenant->account->subscription, 'and a trial clock');
        $this->assertSame('active', $tenant->store_status);
        $this->assertTrue($tenant->hasActiveAccess());
    }

    public function test_an_ordinary_session_is_never_treated_as_impersonation(): void
    {
        [$tenant, $owner] = $this->store();
        $customer = $this->seedCustomers($tenant, 1, 'Original')->first();

        // The read-only guard runs on EVERY request. It must be inert unless an
        // impersonation is actually in progress.
        $this->actingAs($owner)
            ->put(route('tenant.customers.update', $customer), [
                'name' => 'Renamed',
                'phone' => $customer->phone,
                'data_consent' => 1,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Renamed', $customer->fresh()->name);
    }
}
