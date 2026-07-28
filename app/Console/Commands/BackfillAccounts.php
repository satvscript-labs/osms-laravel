<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * P1 / REQ-12 — backfill: exactly one account per existing tenant.
 *
 * One account per store preserves today's meaning EXACTLY — every current
 * store is a single-store account. Idempotent (skips tenants that already
 * have one), dry-run by default, transactional on commit; the same pattern
 * proven on the Sahaj legacy migration.
 *
 * ⚠ The account is named from the OWNING USER, never from the store:
 * "Sahaj Optical" is the shop; "Rushi" is the customer. Deriving the account
 * name from store_name would bake the exact confusion the account model
 * exists to remove into the data on day one (06 §6). A store with no
 * store_admin gets a VISIBLY flagged name for manual correction — flagged
 * beats guessed.
 */
class BackfillAccounts extends Command
{
    protected $signature = 'osms:backfill-accounts
        {--commit : Apply the changes (default is a dry run that writes nothing)}';

    protected $description = 'Create one account per existing tenant and point subscriptions + ledger rows at it (P1/REQ-12).';

    public function handle(): int
    {
        $commit = (bool) $this->option('commit');
        $basicPlanId = Plan::query()->where('code', 'basic')->value('id');

        // Runs unauthenticated → bypass tenant scopes everywhere.
        $tenants = Tenant::query()->whereNull('account_id')->get();
        $already = Tenant::query()->whereNotNull('account_id')->count();

        if ($tenants->isEmpty()) {
            $this->info("Nothing to do — every tenant already has an account ({$already} done).");

            return self::SUCCESS;
        }

        $plan = [];
        foreach ($tenants as $tenant) {
            $owner = User::query()
                ->where('tenant_id', $tenant->id)
                ->where('role', 'store_admin')
                ->orderBy('id')
                ->first();

            $subscription = Subscription::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)->first();

            $invoiceCount = SubscriptionInvoice::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)->whereNull('account_id')->count();

            $plan[] = [
                'tenant' => $tenant,
                'owner' => $owner,
                'subscription' => $subscription,
                'invoices' => $invoiceCount,
                'account_name' => $owner
                    ? $owner->name
                    : "{$tenant->store_name} [owner unknown — name me]",
                'flagged' => $owner === null,
            ];
        }

        $this->table(
            ['Store', 'Account will be named', 'Billing email', 'Sub?', 'Ledger rows', 'Flag'],
            collect($plan)->map(fn ($p) => [
                $p['tenant']->store_name,
                $p['account_name'],
                $p['owner']?->email ?? '—',
                $p['subscription'] ? $p['subscription']->status : '⚠ none',
                $p['invoices'],
                $p['flagged'] ? '⚠ no store_admin' : '',
            ])->all(),
        );

        $flagged = collect($plan)->where('flagged', true)->count();
        $this->line(sprintf(
            '%d tenant(s) to backfill, %d already done, %d flagged for manual naming.',
            count($plan), $already, $flagged,
        ));

        if (! $commit) {
            $this->warn('DRY RUN — nothing written. Re-run with --commit to apply.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($plan, $basicPlanId) {
            foreach ($plan as $p) {
                /** @var Tenant $tenant */
                $tenant = $p['tenant'];

                $account = Account::create([
                    'name' => $p['account_name'],
                    'billing_email' => $p['owner']?->email,
                    // Presentational label, synced from the real (derived) state.
                    'status' => $p['subscription']?->status ?? 'trialing',
                    'internal_notes' => $tenant->internal_notes, // moves up (06 §4.2)
                    'owner_user_id' => $p['owner']?->id,
                ]);

                $tenant->forceFill(['account_id' => $account->id])->save();

                $p['subscription']?->forceFill([
                    'account_id' => $account->id,
                    // Bind the loose tier enum to its plan row while we're here.
                    'plan_id' => $p['subscription']->plan_id ?? $basicPlanId,
                ])->save();

                SubscriptionInvoice::withoutGlobalScopes()
                    ->where('tenant_id', $tenant->id)
                    ->whereNull('account_id')
                    ->update(['account_id' => $account->id]);
            }
        });

        // Post-commit verification — the same discipline as the Sahaj import:
        // never report success without re-counting from the database.
        $orphanTenants = Tenant::query()->whereNull('account_id')->count();
        $orphanSubs = Subscription::withoutGlobalScopes()->whereNull('account_id')->count();
        $orphanInvoices = SubscriptionInvoice::withoutGlobalScopes()->whereNull('account_id')->count();

        if ($orphanTenants + $orphanSubs + $orphanInvoices > 0) {
            $this->error("Verification FAILED — orphans remain: tenants={$orphanTenants}, subscriptions={$orphanSubs}, invoices={$orphanInvoices}.");

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Done. %d account(s) created; every tenant, subscription and ledger row is account-bound.%s',
            count($plan),
            $flagged ? " ⚠ {$flagged} account(s) need manual naming — search for '[owner unknown'." : '',
        ));

        return self::SUCCESS;
    }
}
