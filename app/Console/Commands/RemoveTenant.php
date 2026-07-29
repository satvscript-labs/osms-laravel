<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use App\Services\StoreClosure;
use Illuminate\Console\Command;

/**
 * Permanently remove a store and everything belonging to it.
 *
 * Exists because doing this by hand has gone wrong twice. Every tenant-owned
 * table cascades on delete EXCEPT `users`, which is `nullOnDelete` (deliberate —
 * superadmins have no tenant). So deleting a tenant row does not delete its
 * staff: it strands them with a NULL tenant_id, and their email address then
 * blocks that person from ever signing up again. This command deletes the users
 * explicitly, in the same transaction, and verifies nothing was left behind.
 *
 * Addressed by UUID only. Store names are not unique and MySQL compares them
 * case-insensitively, so a name is not safe to point a destructive command at.
 *
 * P5 — the deletion itself now lives in `StoreClosure`, shared with the panel's
 * closure surface. This command keeps the confirmation ceremony and the report;
 * both doors destroy data through exactly one code path, so a fix to the cascade
 * can never land on one and miss the other.
 */
class RemoveTenant extends Command
{
    protected $signature = 'osms:remove-tenant
                            {--id= : UUID of the store to remove (required)}
                            {--force : Skip the typed confirmation (scripted runs only)}';

    protected $description = 'Permanently delete a store, its users and all its data';

    public function __construct(private readonly StoreClosure $closure)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $id = (string) $this->option('id');

        if ($id === '') {
            $this->error('--id is required. Find it with: php artisan osms:check-tenants');

            return self::FAILURE;
        }

        $tenant = Tenant::find($id);

        if (! $tenant) {
            $this->error("No store with ID {$id}.");

            return self::FAILURE;
        }

        $users = User::withoutGlobalScopes()->where('tenant_id', $tenant->id)->get();
        $counts = $this->closure->inventory($tenant->id);

        $this->newLine();
        $this->line('<options=bold>About to permanently delete</>');
        $this->line('  store    ' . $tenant->store_name);
        $this->line('  id       ' . $tenant->id);
        $this->line('  created  ' . $tenant->created_at->format('d M Y H:i'));

        $this->newLine();
        $this->line('  users (' . $users->count() . ') — deleted explicitly, since users.tenant_id is nullOnDelete:');
        foreach ($users as $u) {
            $this->line(sprintf('    • %-36s %s', $u->email, $u->role));
        }
        if ($users->isEmpty()) {
            $this->line('    (none)');
        }

        $rows = array_sum($counts);
        $this->newLine();
        $this->line('  data (' . number_format($rows) . ' rows) — removed by database cascade:');
        foreach (array_filter($counts) as $table => $n) {
            $this->line(sprintf('    • %-24s %s', $table, number_format($n)));
        }
        if ($rows === 0) {
            $this->line('    (none — this store is empty)');
        }

        $this->newLine();
        $this->error('THIS CANNOT BE UNDONE. Take a backup first (scripts/backup-db.sh).');

        if (! $this->option('force')) {
            $typed = (string) $this->ask('Type the store name exactly to confirm');

            if ($typed !== $tenant->store_name) {
                $this->info('Names did not match — nothing was deleted.');

                return self::FAILURE;
            }
        }

        $id = $tenant->id;
        $name = $tenant->store_name;

        // --force here means "skip the typed confirmation", which for a store
        // that was never formally closed also means skipping the retention
        // window. That is the CLI's job, not the panel's.
        $this->closure->purge($tenant, 'osms:remove-tenant', force: true);

        return $this->report($id, $name);
    }

    /** Prove the cascade actually fired and left nothing addressable behind. */
    private function report(string $tenantId, string $name): int
    {
        $result = $this->closure->verify($tenantId);

        $this->newLine();

        if ($result['clean']) {
            $this->info("\"{$name}\" and everything belonging to it is gone. Nothing was left behind.");

            return self::SUCCESS;
        }

        $this->error('Deletion did not fully clean up:');
        if ($result['stranded_users'] > 0) {
            $this->line("  {$result['stranded_users']} user(s) still reference this tenant");
        }
        foreach ($result['leftovers'] as $table => $n) {
            $this->line("  {$table}: {$n} row(s) remain");
        }

        return self::FAILURE;
    }
}
