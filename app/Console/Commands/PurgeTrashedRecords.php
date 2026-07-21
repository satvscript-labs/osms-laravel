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
    protected $signature = 'model:purge-trashed {--days=30 : Retention window in days}';

    protected $description = 'Permanently delete customers/inventory archived beyond the retention window';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoff = now()->subDays($days);

        $purged = 0;

        // Customers are safe to purge — archiving is blocked while they have orders.
        $purged += Customer::withoutGlobalScopes()
            ->onlyTrashed()
            ->where('deleted_at', '<=', $cutoff)
            ->forceDelete();

        // DATA-07 — soft-deleted eye records past the retention window.
        $purged += EyeRecord::withoutGlobalScopes()
            ->onlyTrashed()
            ->where('deleted_at', '<=', $cutoff)
            ->forceDelete();

        // DATA-03 — never purge an item still referenced by an order; hard-deleting
        // it would rewrite historical receipts to "Custom item". It stays archived.
        $purged += Inventory::withoutGlobalScopes()
            ->onlyTrashed()
            ->where('deleted_at', '<=', $cutoff)
            ->whereDoesntHave('orderItems')
            ->forceDelete();

        $this->info("Purged {$purged} record(s) archived before {$cutoff->toDateString()}.");

        return self::SUCCESS;
    }
}
