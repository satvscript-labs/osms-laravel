<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\EyeRecord;
use App\Models\Inventory;
use Illuminate\Console\Command;

/**
 * FG-Delete — hard-delete customers and inventory that have been archived
 * (soft-deleted) beyond the 30-day retention window. Runs daily via the
 * scheduler (see routes/console.php). Queries bypass the tenant scope so the
 * purge covers every store; the retention window is the only filter.
 */
class PurgeTrashedRecords extends Command
{
    protected $signature = 'model:purge-trashed
        {--days=30 : Retention window in days}
        {--dry-run : Count what WOULD be deleted, and delete nothing}';

    protected $description = 'Permanently delete customers/inventory archived beyond the retention window';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoff = now()->subDays($days);

        // E0 / S-8 — a look-before-you-leap for the first-ever scheduled run. If the
        // scheduler has never been able to launch this command (FB-01), the backlog is
        // every record archived for over $days days; the owner should see it before it goes.
        $dry = (bool) $this->option('dry-run');

        // Customers are safe to purge — archiving is blocked while they have orders.
        $customers = Customer::withoutGlobalScopes()
            ->onlyTrashed()
            ->where('deleted_at', '<=', $cutoff);

        // DATA-07 — soft-deleted eye records past the retention window.
        $eyeRecords = EyeRecord::withoutGlobalScopes()
            ->onlyTrashed()
            ->where('deleted_at', '<=', $cutoff);

        // DATA-03 — never purge an item still referenced by an order; hard-deleting
        // it would rewrite historical receipts to "Custom item". It stays archived.
        $inventory = Inventory::withoutGlobalScopes()
            ->onlyTrashed()
            ->where('deleted_at', '<=', $cutoff)
            ->whereDoesntHave('orderItems');

        if ($dry) {
            $counts = [
                'customers' => $customers->count(),
                'eye records' => $eyeRecords->count(),
                'inventory items' => $inventory->count(),
            ];

            $this->info('Dry run - nothing deleted. Would permanently delete, archived before '
                . $cutoff->toDateString() . ': '
                . collect($counts)->map(fn ($n, $what) => "{$n} {$what}")->implode(', ')
                . '.');

            return self::SUCCESS;
        }

        $purged = $customers->forceDelete() + $eyeRecords->forceDelete() + $inventory->forceDelete();

        $this->info("Purged {$purged} record(s) archived before {$cutoff->toDateString()}.");

        return self::SUCCESS;
    }
}
