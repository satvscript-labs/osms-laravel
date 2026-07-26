<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesTargetTenant;
use App\Exports\Legacy\SahajMigrationReport;
use App\Models\Customer;
use App\Models\EyeRecord;
use App\Support\Legacy\SahajLegacyImporter;
use App\Support\Legacy\SqlDumpParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

/**
 * One-off migration of Sahaj Optical's legacy system into OSMS.
 *
 * Imports customer profiles and their eye prescriptions only — no orders,
 * payments or stock (confirmed scope). Safe by default: without --commit it
 * writes nothing and only produces the review workbook.
 *
 * See _artifacts/FirstCustomerFiles/MIGRATION_PLAN.md for how the identity rules
 * were derived from the real data.
 */
class ImportSahajLegacy extends Command
{
    use ResolvesTargetTenant;

    protected $signature = 'osms:import-sahaj-legacy
                            {--dir= : Folder holding the legacy .sql dumps}
                            {--tenant=Sahaj Optical : Store name to import into}
                            {--tenant-id= : Store UUID — unambiguous, preferred in production}
                            {--report= : Where to write the .xlsx review workbook}
                            {--no-report : Skip the workbook (shared hosting may cap memory below what it needs)}
                            {--commit : Actually write to the database (default is a dry run)}
                            {--force : Skip confirmation prompts (for scripted/local runs)}';

    protected $description = 'Import Sahaj Optical legacy customers + eye records (dry run unless --commit)';

    /** Placeholder numbers use a 0-prefixed block no real Indian mobile can occupy. */
    private const PLACEHOLDER_PREFIX = '+91 0';


    public function handle(): int
    {
        // PhpSpreadsheet holds every sheet in memory, and this workbook carries
        // ~8,000 rows across seven tabs. A one-off CLI migration can afford the
        // headroom that a web request could not.
        ini_set('memory_limit', '1G');

        $dir = rtrim($this->option('dir')
            ?: base_path('_artifacts/FirstCustomerFiles/u174003801_sahaj_optical_sql'), '/\\');

        $commit = (bool) $this->option('commit');

        $this->info('Reading legacy SQL dumps from: ' . $dir);

        try {
            $eyeRows = SqlDumpParser::parseTable($dir . '/u174003801_sahaj_optical_table_eyerecourd.sql', 'eyerecourd');
            $estimateRows = SqlDumpParser::parseTable($dir . '/u174003801_sahaj_optical_table_estimatebook.sql', 'estimatebook');
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $importer = (new SahajLegacyImporter($eyeRows, $estimateRows))->analyse();
        $this->renderSummary($importer);

        // The workbook is deterministic — same input files, same code, same
        // output — so on a memory-capped host it can be skipped here and
        // generated on a workstation instead. It is built BEFORE any writing,
        // so running out of memory would otherwise abort the whole import.
        if ($this->option('no-report')) {
            $this->newLine();
            $this->comment('Workbook skipped (--no-report).');
        } else {
            $this->newLine();
            $this->info('Review workbook: ' . $this->writeReport($importer, $commit));
        }

        if (! $commit) {
            $this->newLine();
            $this->warn('DRY RUN — nothing was written. Re-run with --commit to import.');

            return self::SUCCESS;
        }

        return $this->import($importer);
    }

    private function renderSummary(SahajLegacyImporter $importer): void
    {
        $s = $importer->stats;

        $this->newLine();
        $this->line('<options=bold>Source</>');
        $this->line(sprintf('  %-42s %s', 'eyerecourd rows', number_format($s['source_eye_rows'])));
        $this->line(sprintf('  %-42s %s', 'estimatebook rows', number_format($s['source_estimate_rows'])));
        $this->line(sprintf('  %-42s %s', 'excluded (not customers)', number_format($s['excluded_rows'])));
        $this->line(sprintf('  %-42s %s', 'dropped (no phone AND no eye test)', number_format($s['low_priority_dropped'])));

        $this->newLine();
        $this->line('<options=bold>Will create</>');
        $this->line(sprintf('  %-42s %s', 'customer profiles', number_format($s['profiles_to_import'])));
        $this->line(sprintf('  %-42s %s', '  with a real phone', number_format($s['with_real_phone'])));
        $this->line(sprintf('  %-42s %s', '  with a placeholder phone', number_format($s['with_placeholder_phone'])));
        $this->line(sprintf('  %-42s %s', 'eye prescriptions', number_format($s['eye_records_to_import'])));
        $this->line(sprintf('  %-42s %s', '  patients (have at least one eye test)', number_format($s['patients'])));

        $this->newLine();
        $this->line('<options=bold>Name markers</>');
        $this->line(sprintf('  %-42s %s', SahajLegacyImporter::MARKER_ACTION, number_format($s['needs_action'])));
        $this->line(sprintf('  %-42s %s', '    patients missing their own number', number_format($s['patients_needing_a_number'])));
        $this->line(sprintf('  %-42s %s', '    odd name but a real number attached', number_format($s['kept_despite_odd_name'])));
        $this->line(sprintf('  %-42s %s', SahajLegacyImporter::MARKER_SHARED, number_format($s['shared_number'])));
        $this->line(sprintf('  %-42s %s', '    (non-patients on a relative\'s number)', ''));

        $this->newLine();
        $this->line('<options=bold>Flagged in the workbook</>');
        $this->line(sprintf('  %-42s %s', 'sharing a phone number', number_format($s['flagged_for_review'])));
        $this->line(sprintf('  %-42s %s', 'merged from name variants', number_format($s['merged_name_variants'])));
        $this->line(sprintf('  %-42s %s', 'phone auto-picked (newest visit)', number_format($s['phone_auto_picked'])));
    }

    private function writeReport(SahajLegacyImporter $importer, bool $commit): string
    {
        $path = $this->option('report') ?: base_path(
            '_artifacts/FirstCustomerFiles/Sahaj_Migration_Report_' . now()->format('Y-m-d_Hi') . '.xlsx'
        );

        @mkdir(dirname($path), 0775, true);

        // Write straight to the requested absolute path. Excel::store() routes
        // through a configured disk and leaves us guessing where the file landed;
        // raw() hands back the bytes so the destination is unambiguous.
        file_put_contents(
            $path,
            Excel::raw(new SahajMigrationReport($importer, $commit), \Maatwebsite\Excel\Excel::XLSX)
        );

        return $path;
    }

    private function import(SahajLegacyImporter $importer): int
    {
        $tenant = $this->resolveTenant();

        if (! $tenant) {
            return self::FAILURE;
        }

        $tenantName = $tenant->store_name;
        $force = (bool) $this->option('force');

        $existing = Customer::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count();
        if ($existing > 0) {
            $this->warn("\"{$tenantName}\" already has {$existing} customer(s).");
            if (! $force && ! $this->confirm('Import anyway? Legacy rows will be ADDED alongside them.', false)) {
                return self::FAILURE;
            }
        }

        $this->newLine();
        $this->warn('Writing to the database now.');
        if (! $force && ! $this->confirm("Import into \"{$tenantName}\"?", false)) {
            $this->info('Aborted — nothing written.');

            return self::FAILURE;
        }

        $customers = 0;
        $records = 0;
        $bar = $this->output->createProgressBar(count($importer->profiles));

        // One transaction for the whole import: a failure halfway through must
        // roll back completely rather than leave a half-migrated store behind.
        DB::transaction(function () use ($importer, $tenant, &$customers, &$records, $bar) {
            $placeholder = 0;

            foreach ($importer->profiles as $profile) {
                $phone = $profile['phone'] ?? self::PLACEHOLDER_PREFIX . str_pad((string) ++$placeholder, 9, '0', STR_PAD_LEFT);

                $customer = Customer::withoutGlobalScopes()->create([
                    'tenant_id' => $tenant->id,
                    'name' => self::displayName($profile['name'], $profile['marker'] ?? null),
                    'phone' => $phone,
                    // Legacy data carries no consent record, and these people
                    // never opted in — leaving both unset keeps the app's own
                    // privacy guards (no WhatsApp, "consent not recorded" badge)
                    // accurate rather than silently claiming consent we don't have.
                    'data_consent_at' => null,
                    'whatsapp_opt_in' => false,
                ]);
                $customers++;

                foreach ($profile['eye_records'] as $er) {
                    $date = $er['date'];
                    unset($er['date']);

                    $record = EyeRecord::withoutGlobalScopes()->create($er + [
                        'tenant_id' => $tenant->id,
                        'customer_id' => $customer->id,
                        'notes' => 'Imported from the previous system.',
                    ]);

                    // Preserve the original visit date; created_at is what the
                    // customer timeline and "last visit" ordering read.
                    if ($date) {
                        $record->forceFill(['created_at' => $date, 'updated_at' => $date])->saveQuietly();
                    }
                    $records++;
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->info("Imported {$customers} customer(s) and {$records} eye record(s) into \"{$tenantName}\".");

        return self::SUCCESS;
    }

    /**
     * Legacy names are stored SHOUTING; Title Case reads better in the UI.
     *
     * A marker ("[Action needed]" / "[Shared number]") is appended as a visible
     * suffix so these profiles surface in the customer list and in a plain text
     * search, without needing a new column on the table.
     */
    public static function displayName(string $name, ?string $marker = null): string
    {
        $display = mb_convert_case(mb_strtolower($name), MB_CASE_TITLE, 'UTF-8');

        return $marker ? $display . ' ' . $marker : $display;
    }
}
