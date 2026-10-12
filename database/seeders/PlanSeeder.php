<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * P1 / REQ-4 — seed the plans table from config/billing.php.
 *
 * Runs as part of the default seeder and is PRODUCTION-SAFE + idempotent:
 * it only fills in prices for plans that don't exist yet. It never overwrites
 * a price the operator has since edited in the panel — the database is the
 * authority once a row exists; config is only the birth certificate.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $sort = 0;

        foreach ((array) config('billing.plans', []) as $code => $plan) {
            Plan::query()->firstOrCreate(
                ['code' => $code],
                [
                    'name' => $plan['name'] ?? ucfirst($code),
                    'monthly_price' => (float) ($plan['monthly_price'] ?? 0),
                    'yearly_price' => (float) ($plan['yearly_price'] ?? 0),
                    'features' => $plan['features'] ?? [],
                    'is_active' => true,
                    'sort_order' => $sort++,
                ],
            );
        }
    }
}
