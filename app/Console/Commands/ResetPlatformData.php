<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Pre-launch cleanup: wipes every tenant (cascades to all tenant-owned data:
 * customers, orders, inventory, subscriptions, payments, stock movements,
 * staff invitations) and every non-superadmin user, leaving only the
 * platform superadmin account(s) intact. `admin_audit_logs` is untouched by
 * design (tenant_id there has no FK — the audit trail survives).
 */
class ResetPlatformData extends Command
{
    protected $signature = 'osms:reset-platform-data {--dry-run : Show what would be deleted without deleting anything}';

    protected $description = 'Delete all tenants and non-superadmin users, keeping only superadmin accounts';

    public function handle(): int
    {
        $tenants = Tenant::all(['id', 'store_name']);
        $superadmins = User::where('role', 'superadmin')->get(['id', 'name', 'email']);
        $usersToDelete = User::where('role', '!=', 'superadmin')->get(['id', 'name', 'email', 'role']);

        $this->info('=== Superadmin accounts that will be KEPT ===');
        if ($superadmins->isEmpty()) {
            $this->error('No superadmin accounts found! Aborting — create one first with osms:make-superadmin.');
            return self::FAILURE;
        }
        $this->table(['Name', 'Email'], $superadmins->map(fn ($u) => [$u->name, $u->email])->all());

        $this->newLine();
        $this->warn("=== Tenants (stores) that will be DELETED: {$tenants->count()} ===");
        if ($tenants->isNotEmpty()) {
            $this->table(['Store name'], $tenants->map(fn ($t) => [$t->store_name])->all());
        }

        $this->newLine();
        $this->warn("=== Non-superadmin users that will be DELETED: {$usersToDelete->count()} ===");
        if ($usersToDelete->isNotEmpty()) {
            $this->table(['Name', 'Email', 'Role'], $usersToDelete->map(fn ($u) => [$u->name, $u->email, $u->role])->all());
        }

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->info('Dry run only — nothing was deleted.');
            return self::SUCCESS;
        }

        if ($tenants->isEmpty() && $usersToDelete->isEmpty()) {
            $this->info('Nothing to delete.');
            return self::SUCCESS;
        }

        $this->newLine();
        $this->error('This is IRREVERSIBLE. Make sure you have a database backup before continuing.');
        $confirmation = $this->ask('Type "DELETE ALL TENANT DATA" (exactly) to proceed');

        if ($confirmation !== 'DELETE ALL TENANT DATA') {
            $this->info('Confirmation phrase did not match. Aborted — nothing was deleted.');
            return self::FAILURE;
        }

        DB::transaction(function () use ($tenants, $usersToDelete) {
            foreach ($tenants as $tenant) {
                $tenant->delete();
            }
            foreach ($usersToDelete as $user) {
                $user->delete();
            }
        });

        $this->newLine();
        $this->info("Deleted {$tenants->count()} tenant(s) and {$usersToDelete->count()} non-superadmin user(s).");
        $this->info('Superadmin account(s) preserved. Platform data reset complete.');

        return self::SUCCESS;
    }
}
