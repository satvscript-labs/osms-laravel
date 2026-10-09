<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\StoreClosure;
use App\Services\StoreProvisioner;
use App\Services\SubscriptionLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * feat-billing P.1 - P.4: fixes that must land BEFORE the Super Admin deploys.
 *
 * The binding condition (D-9 / rule 8): production now holds a real customer's
 * data, and nothing in this round may lose it. Every test here asserts what is
 * still THERE afterwards, not which column changed.
 *
 *   P.1  Money outlives a store.  Purging a store must never delete the
 *        account's subscription or its payment history (FB-22).
 *   P.2  One trial per account, durably (`accounts.trial_used_at`).
 *   P.3  The legacy raw subscription editor is gone (FB-21).
 */
class Phase83PreDeployFixesTest extends TestCase
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

    private function store(string $name, ?Account $account = null): Tenant
    {
        $owner = User::factory()->create(['tenant_id' => null, 'role' => 'store_admin']);

        return app(StoreProvisioner::class)->provision($owner, ['store_name' => $name], $account);
    }

    /** An account with a paid-up subscription and one cash renewal on the ledger. */
    private function payingAccount(string $name = 'HQ'): Tenant
    {
        $hq = $this->store($name);
        $sub = $hq->account->subscription;
        $sub->forceFill([
            'status' => 'active', 'interval' => 'monthly',
            'current_period_end' => Carbon::today(config('billing.timezone'))->addDays(20),
        ])->save();

        app(SubscriptionLifecycle::class)->commit($sub->fresh(), 'renew', ['amount' => 499, 'method' => 'cash']);

        return $hq->fresh();
    }

    /** Close a store and age its retention window so the panel's own purge path is open. */
    private function closeAndAge(Tenant $tenant): Tenant
    {
        app(StoreClosure::class)->close($tenant->fresh(), 'test');
        Tenant::withoutGlobalScopes()->whereKey($tenant->id)->update(['purge_after' => now()->subDay()]);

        return $tenant->fresh();
    }

    private function moneyRows(Account $account): array
    {
        return [
            'subscriptions' => Subscription::withoutGlobalScopes()->where('account_id', $account->id)->count(),
            'ledger' => SubscriptionInvoice::withoutGlobalScopes()->where('account_id', $account->id)->count(),
        ];
    }

    // ------------------------------------------------------------------
    // P.1 - FB-22: money outlives a store
    // ------------------------------------------------------------------

    public function test_purging_the_original_branch_keeps_the_accounts_clock_and_money(): void
    {
        $hq = $this->payingAccount('HQ');
        $account = $hq->account;
        $branch2 = $this->store('Branch 2', $account);

        $before = $this->moneyRows($account);
        $this->assertGreaterThanOrEqual(1, $before['ledger'], 'fixture should have a payment on the ledger');
        $this->assertSame('OK', $branch2->fresh()->accessDenialReason() ?? 'OK');

        app(StoreClosure::class)->purge($this->closeAndAge($hq), 'test');

        $this->assertSame($before, $this->moneyRows($account), 'no billing row may be deleted by purging a store');
        $this->assertSame('OK', $branch2->fresh()->accessDenialReason() ?? 'OK',
            'the surviving, paid-up branch must keep working');
    }

    public function test_purging_the_only_store_still_keeps_the_money(): void
    {
        $solo = $this->payingAccount('Solo');
        $account = $solo->account;
        $before = $this->moneyRows($account);

        app(StoreClosure::class)->purge($this->closeAndAge($solo), 'test');

        $this->assertSame($before, $this->moneyRows($account), 'a customer who leaves keeps their payment history');
    }

    public function test_the_kept_rows_lose_only_their_store_link(): void
    {
        $hq = $this->payingAccount('HQ');
        $account = $hq->account;
        $this->store('Branch 2', $account);

        app(StoreClosure::class)->purge($this->closeAndAge($hq), 'test');

        $sub = Subscription::withoutGlobalScopes()->where('account_id', $account->id)->first();
        $this->assertNull($sub->tenant_id, 'the clock now belongs to the account alone');
        $this->assertNotNull($sub->current_period_end, 'and its dates are untouched');

        SubscriptionInvoice::withoutGlobalScopes()->where('account_id', $account->id)->get()
            ->each(fn ($row) => $this->assertNotNull($row->receipt_no, 'a receipt keeps its number'));
    }

    public function test_the_operators_row_count_no_longer_includes_money(): void
    {
        $hq = $this->payingAccount('HQ');

        $counts = app(StoreClosure::class)->inventory($hq->id);

        $this->assertArrayNotHasKey('subscriptions', $counts);
        $this->assertArrayNotHasKey('subscription_invoices', $counts);
    }

    public function test_the_audit_entry_records_what_was_kept_as_well_as_what_was_destroyed(): void
    {
        $hq = $this->payingAccount('HQ');
        $this->store('Branch 2', $hq->account);

        app(StoreClosure::class)->purge($this->closeAndAge($hq), 'test');

        $entry = \App\Models\AdminAuditLog::where('action', 'store.purged')->latest()->first();
        $kept = $entry->meta['billing_rows_kept'] ?? null;

        $this->assertNotNull($kept, 'the audit trail must say money was deliberately kept');
        $this->assertSame(1, $kept['subscriptions']);
        $this->assertGreaterThanOrEqual(1, $kept['subscription_invoices']);
    }

    public function test_the_panels_own_purge_route_keeps_money_and_says_so(): void
    {
        $hq = $this->payingAccount('HQ');
        $account = $hq->account;
        $this->store('Branch 2', $account);
        $hq = $this->closeAndAge($hq);
        $before = $this->moneyRows($account);

        $this->withSession(['auth.password_confirmed_at' => time()])
            ->actingAs($this->admin)
            ->delete(route('superadmin.accounts.store.purge', [$account, $hq]), [
                'confirm_name' => 'HQ', 'reason' => 'test',
            ])
            ->assertRedirect()
            ->assertSessionHas('status', fn ($m) => str_contains($m, 'payment history were kept'));

        $this->assertSame($before, $this->moneyRows($account));
        $this->assertNull(Tenant::withoutGlobalScopes()->find($hq->id), 'and the store really is gone');
    }

    // ------------------------------------------------------------------
    // P.1 - S-6: no backup, no purge
    // ------------------------------------------------------------------

    /** @var list<string> directories this test made, removed again in tearDown() */
    private array $madeDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->madeDirs as $dir) {
            foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }

        parent::tearDown();
    }

    /** Point the app at a backup directory whose contents the test controls. */
    private function backupDir(array $files = []): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'osms-p1-' . uniqid();
        mkdir($dir, 0777, true);
        $this->madeDirs[] = $dir;

        foreach ($files as $name => [$bytes, $ageHours]) {
            $path = $dir . DIRECTORY_SEPARATOR . $name;
            file_put_contents($path, str_repeat('x', $bytes));
            touch($path, time() - (int) ($ageHours * 3600));
        }

        config(['saas.backup_dir' => $dir]);

        return $dir;
    }

    private function purgeIsRefusedWith(string $needle): Tenant
    {
        $solo = $this->payingAccount('Solo');
        $solo = $this->closeAndAge($solo);

        try {
            app(StoreClosure::class)->purge($solo, 'test');
            $this->fail('the purge should have been refused');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString($needle, $e->getMessage());
        }

        $this->assertNotNull(Tenant::withoutGlobalScopes()->find($solo->id), 'refused means NOTHING was deleted');
        $this->assertSame(1, User::withoutGlobalScopes()->where('tenant_id', $solo->id)->count(), 'including its login');

        return $solo;
    }

    public function test_a_purge_is_refused_when_there_is_no_backup_at_all(): void
    {
        $this->backupDir();                       // an empty directory

        $this->purgeIsRefusedWith('No database backup');
    }

    public function test_a_purge_is_refused_when_the_backup_directory_does_not_exist(): void
    {
        config(['saas.backup_dir' => sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'osms-does-not-exist-' . uniqid()]);

        $this->purgeIsRefusedWith('no backup directory');
    }

    public function test_a_purge_is_refused_when_the_newest_backup_is_stale(): void
    {
        $this->backupDir(['osms_old.sql.gz' => [5000, 40]]);   // 40h old; the limit is 26h

        $this->purgeIsRefusedWith('hours old');
    }

    public function test_a_purge_is_refused_when_the_newest_backup_is_a_truncated_dump(): void
    {
        $this->backupDir(['osms_tiny.sql.gz' => [10, 1]]);

        $this->purgeIsRefusedWith('truncated');
    }

    public function test_the_newest_backup_counts_not_an_old_one_beside_a_fresh_one(): void
    {
        $this->backupDir([
            'osms_old.sql.gz' => [5000, 200],
            'osms_new.sql.gz' => [5000, 2],
        ]);

        $solo = $this->closeAndAge($this->payingAccount('Solo'));

        app(StoreClosure::class)->purge($solo, 'test');

        $this->assertNull(Tenant::withoutGlobalScopes()->find($solo->id));
    }

    public function test_the_clis_force_path_is_guarded_too(): void
    {
        $this->backupDir();
        $solo = $this->payingAccount('Solo');

        $this->expectException(\InvalidArgumentException::class);

        // --force skips the retention window, never the backup.
        app(StoreClosure::class)->purge($solo, 'test', force: true);
    }

    public function test_the_panel_shows_the_refusal_instead_of_deleting(): void
    {
        $this->backupDir();
        $solo = $this->payingAccount('Solo');
        $account = $solo->account;
        $solo = $this->closeAndAge($solo);

        $this->withSession(['auth.password_confirmed_at' => time()])
            ->actingAs($this->admin)
            ->delete(route('superadmin.accounts.store.purge', [$account, $solo]), [
                'confirm_name' => 'Solo', 'reason' => 'test',
            ])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'recent backup has to exist'));

        $this->assertNotNull(Tenant::withoutGlobalScopes()->find($solo->id));
    }

    public function test_the_documented_dev_escape_hatch_works_and_only_when_asked_for(): void
    {
        $this->backupDir();                               // no backup...
        config(['saas.purge_requires_backup' => false]);  // ...and the dev flag is off
        $solo = $this->closeAndAge($this->payingAccount('Solo'));

        app(StoreClosure::class)->purge($solo, 'test');

        $this->assertNull(Tenant::withoutGlobalScopes()->find($solo->id));
    }

    public function test_the_backup_requirement_defaults_to_on(): void
    {
        // The shipped default, read from the config file itself rather than the
        // test environment: production must be protected unless somebody opts out.
        $defaults = require config_path('saas.php');

        $this->assertTrue($defaults['purge_requires_backup']);
    }

    // ------------------------------------------------------------------
    // P.1 - S-7: defence in depth. Even if the foreign keys regress, money survives.
    // ------------------------------------------------------------------

    public function test_if_a_cascade_ever_returns_the_purge_rolls_back_instead_of_destroying_money(): void
    {
        $hq = $this->payingAccount('HQ');
        $account = $hq->account;
        $before = $this->moneyRows($account);
        $hq = $this->closeAndAge($hq);

        // Simulate the regression: put the old ON DELETE CASCADE back.
        (require database_path('migrations/2026_10_09_000001_money_survives_store_deletion.php'))->down();

        try {
            app(StoreClosure::class)->purge($hq, 'test');
            $this->fail('the in-transaction guard should have refused');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Billing records must outlive a store', $e->getMessage());
        }

        $this->assertSame($before, $this->moneyRows($account), 'no billing row lost');
        $this->assertNotNull(Tenant::withoutGlobalScopes()->find($hq->id), 'the whole purge was rolled back');
        $this->assertSame(1, User::withoutGlobalScopes()->where('tenant_id', $hq->id)->count(), 'users included');
    }

    // ------------------------------------------------------------------
    // P.1 - S-4: the migration loses nothing, in either direction
    // ------------------------------------------------------------------

    public function test_the_migration_keeps_every_row_through_down_and_up(): void
    {
        $hq = $this->payingAccount('HQ');
        $before = $this->moneyRows($hq->account);
        $migration = require database_path('migrations/2026_10_09_000001_money_survives_store_deletion.php');

        $migration->down();
        $this->assertSame($before, $this->moneyRows($hq->account), 'down() must not lose rows');

        $migration->up();
        $this->assertSame($before, $this->moneyRows($hq->account), 'up() must not lose rows');

        $migration->up();   // a second run after a partial failure must be harmless
        $this->assertSame($before, $this->moneyRows($hq->account), 're-running up() must not lose rows');
    }

    public function test_the_ledgers_uniqueness_guarantees_survive_the_migration(): void
    {
        // The unique indexes are what make webhook retries idempotent and receipt
        // numbers unique. A table rebuild must not drop them.
        (require database_path('migrations/2026_10_09_000001_money_survives_store_deletion.php'))->down();
        (require database_path('migrations/2026_10_09_000001_money_survives_store_deletion.php'))->up();

        $unique = collect(\Illuminate\Support\Facades\Schema::getIndexes('subscription_invoices'))
            ->filter(fn ($i) => $i['unique'])->map(fn ($i) => implode(',', $i['columns']))->all();

        $this->assertContains('razorpay_payment_id', $unique);
        $this->assertContains('receipt_no', $unique);
    }

    // ------------------------------------------------------------------
    // P.2 - one trial per account, recorded on the account
    // ------------------------------------------------------------------

    public function test_a_new_accounts_trial_is_recorded_on_the_account(): void
    {
        $store = $this->store('Alpha');

        $this->assertNotNull($store->account->fresh()->trial_used_at);
        $this->assertSame('trialing', $store->account->subscription->status);
    }

    public function test_a_second_branch_neither_starts_a_trial_nor_moves_the_date(): void
    {
        $hq = $this->store('HQ');
        $stamp = $hq->account->fresh()->trial_used_at;
        $end = $hq->account->subscription->current_period_end->toDateString();

        $this->store('Branch 2', $hq->account);

        $this->assertEquals($stamp, $hq->account->fresh()->trial_used_at, 'set once, never overwritten');
        $this->assertSame(1, Subscription::withoutGlobalScopes()->where('account_id', $hq->account_id)->count());
        $this->assertSame($end, $hq->account->fresh()->subscription->current_period_end->toDateString());
    }

    public function test_a_store_added_to_an_account_that_already_used_its_trial_gets_no_second_one(): void
    {
        $hq = $this->store('HQ');
        $account = $hq->account;

        // The subscription row is gone (what FB-22 used to do to it).
        Subscription::withoutGlobalScopes()->where('account_id', $account->id)->delete();

        $second = $this->store('Second', $account->fresh());

        $sub = Subscription::withoutGlobalScopes()->where('account_id', $account->id)->first();
        $this->assertNotNull($sub, 'a subscription exists again so the store is never in limbo');
        $this->assertNotSame('trialing', $sub->status, 'but it is NOT a fresh free trial');
        $this->assertSame('locked', $sub->accessState(), 'it is locked until an operator decides');
        $this->assertNotNull($second->fresh()->accessDenialReason());
    }

    public function test_the_migration_backfills_only_empty_values_and_never_overwrites(): void
    {
        $kept = $this->store('Kept')->account;
        $empty = $this->store('Empty')->account;

        $sentinel = Carbon::parse('2025-01-02 03:04:05');
        Account::whereKey($kept->id)->update(['trial_used_at' => $sentinel]);
        Account::whereKey($empty->id)->update(['trial_used_at' => null]);

        (require database_path('migrations/2026_10_09_000002_add_trial_used_at_to_accounts.php'))->up();

        $this->assertEquals($sentinel, $kept->fresh()->trial_used_at, 'an existing value is never overwritten');
        $this->assertEquals(
            $empty->subscription->created_at->format('Y-m-d H:i:s'),
            $empty->fresh()->trial_used_at->format('Y-m-d H:i:s'),
            'an empty one is filled from the account\'s first subscription row',
        );
    }

    // ------------------------------------------------------------------
    // P.3 - the raw subscription editor is gone (and only that)
    // ------------------------------------------------------------------

    public function test_the_raw_subscription_editor_no_longer_exists(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('superadmin.subscription.update'));
        $this->assertFalse(
            method_exists(\App\Http\Controllers\Superadmin\SubscriptionController::class, 'update'),
            'no controller method may assign status / interval / period end directly (billing rule 1)',
        );

        $store = $this->store('Alpha');

        // The old URL answers as "not found / not allowed", never as an editor.
        $status = $this->withSession(['auth.password_confirmed_at' => time()])
            ->patch("/superadmin/stores/{$store->id}/subscription", [
                'status' => 'active', 'tier' => 'basic', 'reason' => 'x',
            ])
            ->getStatusCode();

        $this->assertContains($status, [404, 405], 'the old URL must not behave as an editor');
        $this->assertSame('trialing', $store->account->subscription->fresh()->status, 'and nothing changed');
    }

    public function test_the_legacy_store_page_has_lost_the_raw_form_but_keeps_the_safe_actions(): void
    {
        $store = $this->store('Alpha');

        $html = $this->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('superadmin.tenants.show', $store))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('edit raw subscription', $html);
        $this->assertStringContainsString(route('superadmin.subscription.extend-trial', $store), $html);
        $this->assertStringContainsString(route('superadmin.subscription.activate', $store), $html);
        $this->assertStringContainsString(route('superadmin.subscription.cancel', $store), $html);
    }

    public function test_the_three_remaining_legacy_actions_still_run_through_the_gate_and_demand_a_reason(): void
    {
        $store = $this->store('Alpha');
        $sub = $store->account->subscription;
        $call = fn (string $route, array $data) => $this->withSession(['auth.password_confirmed_at' => time()])
            ->post(route($route, $store), $data);

        // A reason is required: refused, and nothing moves.
        $before = $sub->fresh()->current_period_end->toDateString();
        $call('superadmin.subscription.extend-trial', ['days' => 10])->assertSessionHasErrors('reason');
        $this->assertSame($before, $sub->fresh()->current_period_end->toDateString());

        // With a reason it works, and is sticky and audited like any lifecycle action.
        $call('superadmin.subscription.extend-trial', ['days' => 10, 'reason' => 'goodwill'])->assertSessionHas('status');
        $fresh = $sub->fresh();
        $this->assertTrue($fresh->hasActiveOverride(), 'a human decision must outlive the robot');
        $this->assertTrue($fresh->current_period_end->gt(Carbon::parse($before)));
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'subscription.extend']);
    }

    public function test_the_backfill_command_sets_it_for_the_accounts_it_creates(): void
    {
        // A legacy store: no account yet (what production has today).
        $legacy = Tenant::create(['store_name' => 'Legacy Optical']);
        $this->assertNull($legacy->account_id);

        \Illuminate\Support\Facades\Artisan::call('osms:backfill-accounts', ['--commit' => true]);

        $legacy = $legacy->fresh();
        $this->assertNotNull($legacy->account_id);
        $this->assertNotNull($legacy->account->trial_used_at, 'the command stamps the account it creates');
    }
}
