<?php

namespace App\Services;

use RuntimeException;

/**
 * E1.7 — a subscription could not be priced because no plan ROW exists for it.
 *
 * Prices are data (`plans`), not config. Before E1 the resolver quietly priced
 * such a subscription from `config('billing.plans')`, and a tier with no config
 * entry at all (Sahaj's legacy "enterprise") came out as Rs 0 — a wrong price
 * that looks like a right one. Outside the test runner it now refuses, loudly:
 * run `php artisan db:seed --class=PlanSeeder` or bind the subscription to a plan.
 */
class PlanMissing extends RuntimeException {}
