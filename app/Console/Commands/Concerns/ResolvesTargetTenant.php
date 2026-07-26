<?php

namespace App\Console\Commands\Concerns;

use App\Models\Tenant;

/**
 * Picks the store a one-off migration command writes into.
 *
 * `tenants.store_name` is NOT unique, and MySQL's default collation is
 * case-insensitive — so a production database holding both "Sahaj Optical" and
 * "SAHAJ OPTICAL" would let a plain `where(...)->first()` silently choose one of
 * them at random and import thousands of rows into the wrong store. This refuses
 * to guess: ambiguity is a hard error, and `--tenant-id` is always available as
 * the unambiguous way through.
 */
trait ResolvesTargetTenant
{
    protected function resolveTenant(): ?Tenant
    {
        if ($id = $this->option('tenant-id')) {
            $tenant = Tenant::find($id);

            if (! $tenant) {
                $this->error("No store with ID {$id}.");
                $this->listTenants();

                return null;
            }

            $this->line('Target store: <options=bold>' . $tenant->store_name . '</> (' . $tenant->id . ')');

            return $tenant;
        }

        $name = trim((string) $this->option('tenant'));
        $matches = Tenant::where('store_name', $name)->orderBy('created_at')->get();

        if ($matches->isEmpty()) {
            $this->error("No store named \"{$name}\".");
            $this->listTenants();

            return null;
        }

        if ($matches->count() > 1) {
            $this->error("\"{$name}\" matches {$matches->count()} stores — refusing to guess which one you meant.");
            $this->newLine();
            foreach ($matches as $m) {
                $this->line(sprintf('  %s  %-28s created %s', $m->id, $m->store_name, $m->created_at->format('d M Y')));
            }
            $this->newLine();
            $this->warn('Re-run with --tenant-id=<the id you want>.');

            return null;
        }

        $tenant = $matches->first();
        $this->line('Target store: <options=bold>' . $tenant->store_name . '</> (' . $tenant->id . ')');

        return $tenant;
    }

    private function listTenants(): void
    {
        $all = Tenant::orderBy('created_at')->get();

        if ($all->isEmpty()) {
            $this->line('  (this database has no stores at all)');

            return;
        }

        $this->newLine();
        $this->line('Stores in this database:');
        foreach ($all as $t) {
            $this->line(sprintf('  %s  %-28s created %s', $t->id, $t->store_name, $t->created_at->format('d M Y')));
        }
    }
}
