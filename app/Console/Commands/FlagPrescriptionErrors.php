<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesTargetTenant;
use App\Models\Customer;
use App\Models\EyeRecord;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Mark customers whose prescriptions cannot be dispensed as written.
 *
 * Cylinder and axis are a pair: the cylinder says how much astigmatism there
 * is, the axis says which way it lies. One without the other cannot be ground
 * into a lens. Entry now enforces that, but records imported from the old
 * system predate the rule, so they sit in the data unnoticed until someone
 * tries to use one.
 *
 * Rather than guess at the missing half — a wrong axis is a wrong lens — the
 * customer's name is marked so the shop can find them and correct them against
 * the paper record.
 *
 * RECONCILES, it does not just tag: every run re-checks every customer and
 * REMOVES the marker from anyone whose records are now clean. So it is safe to
 * re-run, and it is how the marker eventually disappears.
 */
class FlagPrescriptionErrors extends Command
{
    use ResolvesTargetTenant;

    protected $signature = 'osms:flag-prescription-errors
                            {--tenant= : Store name}
                            {--tenant-id= : Store UUID — unambiguous, preferred in production}
                            {--commit : Actually write (default is a dry run)}
                            {--force : Skip confirmation prompts}';

    protected $description = 'Mark (and unmark) customers whose prescriptions have a cylinder without an axis, or vice versa';

    public const MARKER = '[Eye Record Error]';

    public function handle(): int
    {
        $tenant = $this->resolveTenant();
        if (! $tenant) {
            return self::FAILURE;
        }

        [$toAdd, $toRemove, $problems] = $this->reconcile($tenant->id);

        $this->render($tenant->store_name, $toAdd, $toRemove, $problems);

        if (! $this->option('commit')) {
            $this->newLine();
            $this->warn('DRY RUN — nothing was written. Re-run with --commit to apply.');

            return self::SUCCESS;
        }

        if ($toAdd === [] && $toRemove === []) {
            $this->info('Nothing to change.');

            return self::SUCCESS;
        }

        if (! $this->option('force')
            && ! $this->confirm("Apply to \"{$tenant->store_name}\"?", false)) {
            $this->info('Aborted — nothing written.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($toAdd, $toRemove) {
            foreach ($toAdd as $customer) {
                $customer->forceFill(['name' => trim($customer->name) . ' ' . self::MARKER])->save();
            }
            foreach ($toRemove as $customer) {
                $customer->forceFill(['name' => self::strip($customer->name)])->save();
            }
        });

        $this->newLine();
        $this->info(sprintf(
            'Marked %d customer(s); cleared the marker from %d.',
            count($toAdd),
            count($toRemove),
        ));

        return self::SUCCESS;
    }

    /**
     * @return array{0:list<Customer>,1:list<Customer>,2:list<array<string,string>>}
     */
    private function reconcile(string $tenantId): array
    {
        $problems = [];
        $offending = [];

        EyeRecord::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->with('customer')
            ->chunkById(500, function ($records) use (&$problems, &$offending) {
                foreach ($records as $record) {
                    foreach (['od' => 'Right', 'os' => 'Left'] as $eye => $label) {
                        if (! $fault = self::faultFor($record, $eye)) {
                            continue;
                        }

                        $offending[$record->customer_id] = true;
                        $problems[] = [
                            'customer' => $record->customer?->name ?? '(deleted)',
                            'eye' => $label,
                            'fault' => $fault,
                            'seen' => $record->created_at->format('d M Y'),
                        ];
                    }
                }
            });

        $toAdd = [];
        $toRemove = [];

        Customer::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->chunkById(500, function ($customers) use ($offending, &$toAdd, &$toRemove) {
                foreach ($customers as $customer) {
                    $marked = str_contains($customer->name, self::MARKER);
                    $faulty = isset($offending[$customer->id]);

                    if ($faulty && ! $marked) {
                        $toAdd[] = $customer;
                    } elseif (! $faulty && $marked) {
                        $toRemove[] = $customer;
                    }
                }
            });

        return [$toAdd, $toRemove, $problems];
    }

    /** The reason this eye cannot be dispensed, or null when it is fine. */
    public static function faultFor(EyeRecord $record, string $eye): ?string
    {
        $cyl = $record->{"{$eye}_cyl"};
        $axis = $record->{"{$eye}_axis"};

        // A zero cylinder means no astigmatism, so no axis is expected — and an
        // axis of 0 is not a reading (the range is 1..180).
        $hasCyl = $cyl !== null && (float) $cyl != 0.0;
        $hasAxis = $axis !== null && (int) $axis !== 0;

        if ($hasCyl && ! $hasAxis) {
            return sprintf('CYL %+.2f but no axis', (float) $cyl);
        }
        if (! $hasCyl && $hasAxis) {
            return sprintf('AXIS %d° but no cylinder', (int) $axis);
        }

        return null;
    }

    public static function strip(string $name): string
    {
        return trim(str_replace(self::MARKER, '', $name));
    }

    /**
     * @param  list<Customer>  $toAdd
     * @param  list<Customer>  $toRemove
     * @param  list<array<string,string>>  $problems
     */
    private function render(string $store, array $toAdd, array $toRemove, array $problems): void
    {
        $this->newLine();
        $this->line('<options=bold>Target</> ' . $store);

        $this->newLine();
        $this->line('<options=bold>Prescriptions that cannot be dispensed as written</>');
        $this->line(sprintf('  %-44s %s', 'faults found', number_format(count($problems))));

        $missingAxis = count(array_filter($problems, fn ($p) => str_starts_with($p['fault'], 'CYL')));
        $this->line(sprintf('  %-44s %s', '  cylinder with no axis (clinically incomplete)', number_format($missingAxis)));
        $this->line(sprintf('  %-44s %s', '  axis with no cylinder (usually a leftover)', number_format(count($problems) - $missingAxis)));

        $this->newLine();
        $this->line('<options=bold>Customer names</>');
        $this->line(sprintf('  %-44s %s', 'marker added', number_format(count($toAdd))));
        $this->line(sprintf('  %-44s %s', 'marker removed (now clean)', number_format(count($toRemove))));

        if ($problems !== []) {
            $this->newLine();
            $this->line('<options=bold>Detail</>');
            foreach ($problems as $p) {
                $this->line(sprintf('  %-34s %-5s %-32s seen %s',
                    mb_strimwidth($p['customer'], 0, 33, '…'), $p['eye'], $p['fault'], $p['seen']));
            }
        }
    }
}
