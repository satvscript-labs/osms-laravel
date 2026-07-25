<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * The default seeder for `php artisan migrate --seed`.
 *
 * Deliberately minimal and PRODUCTION-SAFE: it only ensures the platform
 * superadmin exists (seeded from the environment — see SuperadminSeeder). It
 * creates NO demo store or sample data, so running it against production can
 * never inject fake customers/orders.
 *
 * For a fully-populated demo store, run ProdDemoSeeder explicitly:
 *   php artisan db:seed --class=Database\\Seeders\\ProdDemoSeeder
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(SuperadminSeeder::class);
    }
}
