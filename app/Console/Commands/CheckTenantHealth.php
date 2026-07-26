<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\EyeRecord;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only health check over the tenant/user graph.
 *
 * Written for the Sahaj Optical cutover, where a signup stalled halfway and the
 * half-created user row was deleted by hand in phpMyAdmin. Manual surgery on a
 * live database is exactly the situation where you want a machine to confirm
 * what's actually there rather than trusting a scroll through a table.
 *
 * Touches nothing — safe to run on production at any time.
 */
class CheckTenantHealth extends Command
{
    protected $signature = 'osms:check-tenants';

    protected $description = 'Read-only audit of stores, users and orphaned rows (safe on production)';

    private int $problems = 0;

    public function handle(): int
    {
        // Shown up front so an "UNVERIFIED" account below reads as informational
        // rather than alarming — it only locks anyone out if this is on.
        $requiresVerification = (bool) config('saas.require_email_verification');
        $this->line('Email verification enforced: ' . ($requiresVerification
            ? '<fg=yellow>YES — an unverified user cannot reach the dashboard</>'
            : 'no (SAAS_REQUIRE_EMAIL_VERIFICATION is off)'));
        $this->newLine();

        $this->line('<options=bold>Stores</>');
        $tenants = Tenant::orderBy('created_at')->get();

        if ($tenants->isEmpty()) {
            $this->line('  (none)');
        }

        foreach ($tenants as $t) {
            $users = User::withoutGlobalScopes()->where('tenant_id', $t->id)->get();
            $sub = $t->subscription;

            $this->newLine();
            $this->line("  <options=bold>{$t->store_name}</>");
            $this->line('    id            ' . $t->id);
            $this->line('    created       ' . $t->created_at->format('d M Y H:i'));
            $this->line('    address       ' . ($t->address ? mb_substr($t->address, 0, 60) . '…' : '<fg=yellow>(blank)</>'));
            $this->line('    GST / tax id  ' . ($t->tax_id ?: '(blank)'));
            $this->line('    subscription  ' . ($sub
                ? $sub->status . ' / ' . $sub->tier . ' until ' . $sub->current_period_end?->format('d M Y')
                : '<fg=red>MISSING — the trial hook did not run</>'));

            if (! $sub) {
                $this->problems++;
            }

            $this->line('    users         ' . $users->count());
            foreach ($users as $u) {
                $this->line(sprintf('      • %-34s %-12s %s', $u->email, $u->role,
                    $u->email_verified_at ? 'verified' : '<fg=yellow>UNVERIFIED</>'));
            }

            if ($users->isEmpty()) {
                $this->line('      <fg=red>no users — nobody can log into this store</>');
                $this->problems++;
            }

            $this->line('    data          ' . implode('  ', [
                'customers ' . Customer::withoutGlobalScopes()->where('tenant_id', $t->id)->count(),
                'eye records ' . EyeRecord::withoutGlobalScopes()->where('tenant_id', $t->id)->count(),
                'orders ' . Order::withoutGlobalScopes()->where('tenant_id', $t->id)->count(),
                'payments ' . Payment::withoutGlobalScopes()->where('tenant_id', $t->id)->count(),
            ]));
        }

        $this->checkDuplicateNames($tenants);
        $this->checkStalledSignups();
        $this->checkOrphanedRows($tenants);

        $this->newLine();
        if ($this->problems === 0) {
            $this->info('No problems found.');

            return self::SUCCESS;
        }

        $this->error("{$this->problems} problem(s) found — see above.");

        return self::FAILURE;
    }

    /** Two stores with the same name make --tenant ambiguous at import time. */
    private function checkDuplicateNames($tenants): void
    {
        $this->newLine();
        $this->line('<options=bold>Duplicate store names</>');

        $dupes = $tenants->groupBy(fn ($t) => mb_strtolower(trim($t->store_name)))->filter(fn ($g) => $g->count() > 1);

        if ($dupes->isEmpty()) {
            $this->line('  none');

            return;
        }

        foreach ($dupes as $name => $group) {
            $this->line("  <fg=yellow>\"{$name}\" appears {$group->count()} times:</>");
            foreach ($group as $t) {
                $this->line('    ' . $t->id . '  ' . $t->store_name . '  created ' . $t->created_at->format('d M Y'));
            }
            $this->problems++;
        }

        $this->warn('  Imports must use --tenant-id while this is true.');
    }

    /**
     * Signup creates the user; onboarding creates the store and links it. A user
     * stuck between the two has no store, and their email blocks a retry — the
     * exact failure Rushi hit.
     */
    private function checkStalledSignups(): void
    {
        $this->newLine();
        $this->line('<options=bold>Stalled signups (user created, store never finished)</>');

        $stalled = User::withoutGlobalScopes()
            ->whereNull('tenant_id')
            ->where('role', '!=', 'superadmin')
            ->get();

        if ($stalled->isEmpty()) {
            $this->line('  none');

            return;
        }

        foreach ($stalled as $u) {
            $this->line(sprintf('  <fg=yellow>%s</>  role %s  registered %s',
                $u->email, $u->role, $u->created_at->format('d M Y H:i')));
            $this->problems++;
        }

        $this->warn('  These accounts cannot log in past onboarding and their email blocks a re-signup.');
    }

    /** Rows whose tenant_id points at a store that no longer exists. */
    private function checkOrphanedRows($tenants): void
    {
        $this->newLine();
        $this->line('<options=bold>Orphaned rows (tenant_id points at a deleted store)</>');

        $ids = $tenants->pluck('id')->all();
        $clean = true;

        foreach (['customers', 'eye_records', 'orders', 'payments', 'inventory', 'order_items', 'users'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'tenant_id')) {
                continue;
            }

            $count = DB::table($table)->whereNotNull('tenant_id')
                ->when($ids !== [], fn ($q) => $q->whereNotIn('tenant_id', $ids))
                ->count();

            if ($count > 0) {
                $this->line("  <fg=red>{$table}: {$count} orphaned row(s)</>");
                $this->problems++;
                $clean = false;
            }
        }

        if ($clean) {
            $this->line('  none');
        }
    }
}
