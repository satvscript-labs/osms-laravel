<?php

namespace App\Console\Commands\Concerns;

use App\Support\Legacy\LegacySource;

/**
 * Shared `--from-db` / `--dir` handling for the Sahaj migration commands, so both
 * of them resolve their input the same way and report it the same way.
 */
trait ReadsLegacySource
{
    /**
     * Resolve where legacy rows should be read from, or null if unusable
     * (the reason is printed).
     *
     * `--from-db` reads the old system directly and needs no files at all.
     * Otherwise it falls back to dump files, which stays supported because a
     * dump is a fixed snapshot: it is what you want when re-running a migration
     * that was already verified against exactly those bytes.
     */
    private function resolveLegacySource(): ?LegacySource
    {
        $source = $this->option('from-db')
            ? LegacySource::database()
            : LegacySource::files(
                $this->option('dir')
                    ?: base_path('_artifacts/FirstCustomerFiles/u174003801_sahaj_optical_sql')
            );

        $this->info('Reading legacy data from: ' . $source->describe());

        if ($problem = $source->check()) {
            $this->error($problem);

            return null;
        }

        return $source;
    }
}
