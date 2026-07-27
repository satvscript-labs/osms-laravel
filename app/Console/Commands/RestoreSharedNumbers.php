<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReadsLegacySource;
use App\Console\Commands\Concerns\ResolvesTargetTenant;
use App\Models\Customer;
use App\Support\Legacy\SahajLegacyImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * SHARE-01 phase 5 — give back the real phone numbers the old schema refused.
 *
 * `UNIQUE (tenant_id, phone)` meant that when several people shared one handset,
 * only one of them could hold the number. The migration gave the most recent
 * visitor the real number and everyone else a fabricated `+91 0…` placeholder,
 * because dropping them would have lost real people. Now that a number can be
 * shared, those placeholders are pure loss and can be undone.
 *
 * Two distinct groups, treated differently:
 *
 *   • lost a contest — a real number IS on record for them, they simply could not
 *     hold it. Restore it. They are then correctly shown as sharing a handset.
 *   • never had one — no number was ever recorded. Their placeholder is replaced
 *     with NULL (now that `phone` is nullable) rather than a fake number, and they
 *     keep their "[Action needed]" marker because they genuinely still need one.
 *
 * The mapping is not stored anywhere: it is recomputed by re-running the same
 * analysis over the same source dumps, so this command needs them. Matching is by
 * the exact name the import wrote, and anything ambiguous is skipped and reported
 * rather than guessed at — a wrong number is worse than a missing one.
 */
class RestoreSharedNumbers extends Command
{
    use ReadsLegacySource;
    use ResolvesTargetTenant;

    protected $signature = 'osms:restore-shared-numbers
                            {--dir= : Folder holding the legacy .sql dumps}
                            {--from-db : Read the old system directly instead of dump files}
                            {--tenant= : Store name to restore into}
                            {--tenant-id= : Store UUID (unambiguous — prefer this)}
                            {--commit : Actually write (default is a dry run)}
                            {--force : Skip confirmation prompts}';

    protected $description = 'Restore the real phone numbers replaced by placeholders during the Sahaj import';

    private const PLACEHOLDER_PREFIX = '+91 0';

    public function handle(): int
    {
        $tenant = $this->resolveTenant();
        if (! $tenant) {
            return self::FAILURE;
        }

        $source = $this->resolveLegacySource();
        if (! $source) {
            return self::FAILURE;
        }

        try {
            $importer = (new SahajLegacyImporter($source->eyeRecords(), $source->estimates()))->analyse();
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $plan = $this->buildPlan($importer, $tenant->id);
        $this->render($plan, $tenant->store_name);

        if (! $this->option('commit')) {
            $this->newLine();
            $this->warn('DRY RUN — nothing was written. Re-run with --commit to apply.');

            return self::SUCCESS;
        }

        if ($plan['restore'] === [] && $plan['blank'] === []) {
            $this->info('Nothing to do.');

            return self::SUCCESS;
        }

        if (! $this->option('force')
            && ! $this->confirm("Apply to \"{$tenant->store_name}\"?", false)) {
            $this->info('Aborted — nothing written.');

            return self::FAILURE;
        }

        return $this->apply($plan, $tenant->store_name);
    }

    /**
     * Work out, per customer, what should happen — without touching anything.
     *
     * @return array{restore:list<array{id:string,name:string,clean:string,from:string,to:string}>,
     *               blank:list<array{id:string,name:string,from:string}>,
     *               skipped:list<array{name:string,reason:string}>}
     */
    private function buildPlan(SahajLegacyImporter $importer, string $tenantId): array
    {
        // Every customer still on a fabricated number, keyed by the exact name the
        // import wrote. A name claimed twice is ambiguous and is dropped from the
        // index entirely, so neither row can be matched by accident.
        $byName = [];
        $ambiguous = [];

        $placeholders = Customer::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('phone', 'like', self::PLACEHOLDER_PREFIX . '%')
            ->get(['id', 'name', 'phone']);

        foreach ($placeholders as $customer) {
            if (isset($byName[$customer->name])) {
                $ambiguous[$customer->name] = true;

                continue;
            }
            $byName[$customer->name] = $customer;
        }
        foreach (array_keys($ambiguous) as $name) {
            unset($byName[$name]);
        }

        $restore = [];
        $blank = [];
        $skipped = [];

        foreach ($importer->profiles as $profile) {
            // Only profiles the import could not give their number to.
            if ($profile['phone'] !== null) {
                continue;
            }

            $written = ImportSahajLegacy::displayName($profile['name'], $profile['marker'] ?? null);

            if (isset($ambiguous[$written])) {
                $skipped[] = ['name' => $written, 'reason' => 'Two customers share this exact name — cannot tell them apart'];

                continue;
            }

            $customer = $byName[$written] ?? null;

            if (! $customer) {
                // Either already restored, or renamed/deleted by staff since the
                // import. Either way it is not ours to touch.
                $skipped[] = ['name' => $written, 'reason' => 'No customer on a placeholder number with this exact name'];

                continue;
            }

            if ($profile['had_phone_on_record']) {
                $restore[] = [
                    'id' => $customer->id,
                    'name' => $customer->name,
                    // Markers described a limitation that no longer exists — the
                    // household is now shown natively on the profile and in the
                    // list, so leaving it in the name is duplicate noise.
                    'clean' => ImportSahajLegacy::displayName($profile['name']),
                    'from' => $customer->phone,
                    'to' => $profile['phone_on_record'],
                ];
            } else {
                // No number was ever recorded. A real NULL beats a fake number.
                $blank[] = ['id' => $customer->id, 'name' => $customer->name, 'from' => $customer->phone];
            }
        }

        return ['restore' => $restore, 'blank' => $blank, 'skipped' => $skipped, 'on_placeholders' => $placeholders->count()];
    }

    /** @param array<string,mixed> $plan */
    private function render(array $plan, string $store): void
    {
        $this->newLine();
        $this->line('<options=bold>Target</> ' . $store);

        $touched = count($plan['restore']) + count($plan['blank']);
        $untouched = $plan['on_placeholders'] - $touched - count($plan['skipped']);

        $this->newLine();
        $this->line('<options=bold>Currently on a fabricated number</>');
        $this->line(sprintf('  %-44s %s', 'customers', number_format($plan['on_placeholders'])));

        $this->newLine();
        $this->line('<options=bold>Will change</>');
        $this->line(sprintf('  %-44s %s', 'real number restored (was on record)', number_format(count($plan['restore']))));
        $this->line(sprintf('  %-44s %s', 'fake number cleared to blank (never had one)', number_format(count($plan['blank']))));
        $this->line(sprintf('  %-44s %s', 'skipped (see below)', number_format(count($plan['skipped']))));

        // Anything else on a `+91 0…` number did not come from this import — the
        // historical monthly-collection profiles use their own reserved block, and
        // staff may have added their own since. None of it is ours to rewrite.
        $this->line(sprintf(
            '  <fg=gray>%-44s %s</>',
            'not from the legacy import — left alone',
            number_format(max(0, $untouched)),
        ));

        foreach (array_slice($plan['restore'], 0, 5) as $row) {
            $this->line(sprintf('    %-34s %s → %s', mb_strimwidth($row['name'], 0, 33, '…'), $row['from'], $row['to']));
        }
        if (count($plan['restore']) > 5) {
            $this->line('    … and ' . number_format(count($plan['restore']) - 5) . ' more');
        }

        if ($plan['skipped'] !== []) {
            $this->newLine();
            $this->line('<options=bold>Skipped — left exactly as they are</>');
            $reasons = array_count_values(array_column($plan['skipped'], 'reason'));
            foreach ($reasons as $reason => $n) {
                $this->line(sprintf('  %-44s %s', $reason, number_format($n)));
            }
        }
    }

    /** @param array<string,mixed> $plan */
    private function apply(array $plan, string $store): int
    {
        $bar = $this->output->createProgressBar(count($plan['restore']) + count($plan['blank']));

        DB::transaction(function () use ($plan, $bar) {
            foreach ($plan['restore'] as $row) {
                Customer::withoutGlobalScopes()->whereKey($row['id'])->update([
                    'phone' => $row['to'],
                    'name' => $row['clean'],
                ]);
                $bar->advance();
            }

            foreach ($plan['blank'] as $row) {
                // Marker deliberately kept — they still have no number.
                Customer::withoutGlobalScopes()->whereKey($row['id'])->update(['phone' => null]);
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->info(sprintf(
            'Restored %s number(s) and cleared %s fake one(s) in "%s".',
            number_format(count($plan['restore'])),
            number_format(count($plan['blank'])),
            $store,
        ));

        return self::SUCCESS;
    }
}
