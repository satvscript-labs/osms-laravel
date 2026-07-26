<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesTargetTenant;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Sahaj Optical's historical monthly collection totals, from the Excel sheet the
 * shop kept by hand (July 2022 → June 2026).
 *
 * This is NOT an order migration. The old system's individual sales are out of
 * scope; all that exists for those years is one cash figure and one UPI figure
 * per month. We synthesise the smallest structure that makes those figures
 * visible to the existing Analytics page — one customer per month holding two
 * delivered orders (cash + UPI), each with a matching payment — so that a
 * date-range search for, say, March 2023 returns March 2023's real numbers.
 *
 * Verified against AnalyticsController: revenue reads `orders.created_at` where
 * `status = delivered`, and the payment-method split reads `payments.created_at`.
 * Both timestamps are therefore forced to the historical date.
 *
 * Safe by default — writes nothing without --commit.
 */
class ImportSahajMonthlyTotals extends Command
{
    use ResolvesTargetTenant;

    protected $signature = 'osms:import-sahaj-monthly
                            {--file= : Path to the monthly totals .xlsx}
                            {--tenant=Sahaj Optical : Store name to import into}
                            {--tenant-id= : Store UUID — unambiguous, preferred in production}
                            {--commit : Actually write to the database (default is a dry run)}
                            {--force : Skip confirmation prompts (for scripted/local runs)}';

    protected $description = 'Import Sahaj Optical historical monthly cash/UPI totals (dry run unless --commit)';

    /**
     * Its own placeholder block, deliberately disjoint from the customer
     * migration's `+91 0` + 9 digits — so the two imports can never collide on
     * the (tenant, phone) unique index, whichever order they run in.
     */
    private const PLACEHOLDER_PREFIX = '+91 09';

    /**
     * The sheet records cash and UPI; `other` exists only where a correction
     * below splits a third channel (RTGS) out of a lumped-together figure.
     */
    private const CHANNELS = ['cash' => 'CASH', 'upi' => 'UPI', 'other' => 'Other'];

    /**
     * Money the sheet records in its TOTAL but has no column to hold, keyed YYYY-MM.
     *
     * The sheet only has CASH and UPI columns, so a payment through any other
     * channel has nowhere to live and shows up as a gap between the components
     * and the stated total. Owner-confirmed; adding it here is what makes those
     * months reconcile exactly.
     *
     * The June 2024 and June 2025 figures that were wrong in the original export
     * have since been corrected in the source file itself, so they need nothing
     * here — the discrepancy check below will shout if that ever regresses.
     */
    private const SUPPLEMENTS = [
        '2025-08' => [
            'other' => 2300.0,
            'note' => 'A 2,300 RTGS payment is inside the stated total but has no column in the '
                . 'sheet — recorded as "Other" so the month reconciles to 2,67,830.',
        ],
    ];

    /** @var list<array<string,mixed>> */
    private array $months = [];

    /** @var list<string> */
    private array $warnings = [];

    public function handle(): int
    {
        $path = $this->option('file')
            ?: base_path('_artifacts/FirstCustomerFiles/SAHAJ MONTHY DATA.xlsx');

        if (! is_file($path)) {
            $this->error("No such file: {$path}");

            return self::FAILURE;
        }

        $this->info('Reading monthly totals from: ' . $path);

        try {
            $this->readSheet($path);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->months === []) {
            $this->error('No usable month rows found.');

            return self::FAILURE;
        }

        $this->renderSummary();

        if (! $this->option('commit')) {
            $this->newLine();
            $this->warn('DRY RUN — nothing was written. Re-run with --commit to import.');

            return self::SUCCESS;
        }

        return $this->import();
    }

    // -------------------------------------------------------------- reading

    private function readSheet(string $path): void
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $rows = $reader->load($path)->getSheet(0)->toArray(null, true, false, false);

        // Header row names the columns; the CASH column is spelt "CASE" in the
        // source file. Resolve by position off the header so a corrected
        // spelling in a future export keeps working.
        $header = array_map(
            static fn ($v) => strtoupper(trim((string) $v)),
            array_shift($rows) ?? []
        );
        $col = static fn (string $name) => array_search($name, $header, true);

        $yearCol = $col('YEAR');
        $monthCol = $col('MONTH');
        $dayCol = $col('DATE');
        $cashCol = $col('CASH') !== false ? $col('CASH') : $col('CASE');
        $upiCol = $col('UPI');
        $totalCol = $col('TOTAL');

        if ($yearCol === false || $monthCol === false || $cashCol === false || $upiCol === false) {
            throw new \RuntimeException(
                'Unexpected columns. Wanted YEAR / MONTH / DATE / CASH (or CASE) / UPI / TOTAL, got: '
                . implode(' | ', $header)
            );
        }

        if ($col('CASH') === false) {
            $this->warnings[] = 'The cash column is headed "CASE" in the sheet — read as CASH.';
        }

        foreach ($rows as $i => $row) {
            $year = (int) ($row[$yearCol] ?? 0);
            $monthName = trim((string) ($row[$monthCol] ?? ''));
            $cash = self::money($row[$cashCol] ?? null);
            $upi = self::money($row[$upiCol] ?? null);

            if ($year < 2000 || $monthName === '') {
                continue;
            }
            // The current, still-running month is present but empty — skip it
            // rather than write a zero-rupee month.
            if ($cash <= 0 && $upi <= 0) {
                $this->warnings[] = sprintf('%s %d has no amounts — skipped.', $monthName, $year);

                continue;
            }

            $month = self::monthNumber($monthName);
            if ($month === null) {
                $this->warnings[] = sprintf('Row %d: unrecognised month "%s" — skipped.', $i + 2, $monthName);

                continue;
            }

            $day = max(1, (int) ($row[$dayCol] ?? 1));
            // Midday, so no timezone shift can push a figure into the previous month.
            $date = Carbon::create($year, $month, min($day, 28), 12, 0, 0);
            $other = 0.0;

            $key = sprintf('%04d-%02d', $year, $month);
            if ($extra = self::SUPPLEMENTS[$key] ?? null) {
                $other = $extra['other'] ?? 0.0;
                $this->warnings[] = sprintf('%s %d — %s', $monthName, $year, $extra['note']);
            }

            // The sheet's own TOTAL is a hand-typed cross-check, not a source of
            // truth. Where it still disagrees we import the components (which
            // drive the analytics split) and say so.
            $stated = self::money($totalCol !== false ? ($row[$totalCol] ?? null) : null);
            $computed = $cash + $upi + $other;
            if ($totalCol !== false && $stated > 0 && abs($stated - $computed) >= 1) {
                $this->warnings[] = sprintf(
                    '%s %d: sheet TOTAL is %s but the components sum to %s — imported the components (difference %s).',
                    $monthName, $year, number_format($stated), number_format($computed),
                    number_format($stated - $computed)
                );
            }

            $this->months[] = [
                'label' => $date->format('F Y'),
                'date' => $date,
                'cash' => $cash,
                'upi' => $upi,
                'other' => $other,
                'total' => $computed,
                'stated_total' => $stated,
            ];
        }
    }

    // ------------------------------------------------------------- reporting

    private function renderSummary(): void
    {
        $cash = array_sum(array_column($this->months, 'cash'));
        $upi = array_sum(array_column($this->months, 'upi'));
        $other = array_sum(array_column($this->months, 'other'));

        $orders = 0;
        foreach ($this->months as $m) {
            foreach (array_keys(self::CHANNELS) as $method) {
                $orders += $m[$method] > 0 ? 1 : 0;
            }
        }

        $first = $this->months[0];
        $last = $this->months[count($this->months) - 1];

        $this->newLine();
        $this->line('<options=bold>Will create</>');
        $this->line(sprintf('  %-38s %s', 'months', number_format(count($this->months))));
        $this->line(sprintf('  %-38s %s', 'customer profiles (one per month)', number_format(count($this->months))));
        $this->line(sprintf('  %-38s %s', 'delivered orders (cash + UPI)', number_format($orders)));
        $this->line(sprintf('  %-38s %s', 'payments', number_format($orders)));

        $this->newLine();
        $this->line('<options=bold>Money</>');
        $this->line(sprintf('  %-38s %s', 'covering', $first['label'] . ' → ' . $last['label']));
        $this->line(sprintf('  %-38s ₹ %s', 'cash', number_format($cash, 2)));
        $this->line(sprintf('  %-38s ₹ %s', 'UPI', number_format($upi, 2)));
        $this->line(sprintf('  %-38s ₹ %s', 'other (RTGS)', number_format($other, 2)));
        $this->line(sprintf('  %-38s ₹ %s', 'total', number_format($cash + $upi + $other, 2)));

        // Independent cross-check: what the shop's own TOTAL column adds up to.
        // These matching is the proof that nothing was mis-read or dropped.
        $stated = array_sum(array_column($this->months, 'stated_total'));
        $delta = ($cash + $upi + $other) - $stated;
        $this->line(sprintf(
            '  %-38s ₹ %s  %s',
            "sheet's own TOTAL column",
            number_format($stated, 2),
            abs($delta) < 1 ? '<fg=green>✓ reconciles exactly</>' : '<fg=red>✗ off by ' . number_format($delta, 2) . '</>',
        ));

        if ($this->warnings !== []) {
            $this->newLine();
            $this->line('<options=bold;fg=yellow>Data-quality notes (confirm these with the shop)</>');
            foreach ($this->warnings as $w) {
                $this->line('  • ' . $w);
            }
        }
    }

    // --------------------------------------------------------------- writing

    private function import(): int
    {
        $tenant = $this->resolveTenant();

        if (! $tenant) {
            return self::FAILURE;
        }

        $tenantName = $tenant->store_name;
        $force = (bool) $this->option('force');

        // Re-running must not double-count revenue, so refuse rather than append.
        $existing = Customer::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('name', 'like', 'Historical %')
            ->count();

        if ($existing > 0) {
            $this->error(
                "\"{$tenantName}\" already has {$existing} \"Historical …\" customer(s) — importing again "
                . 'would double the historical revenue. Delete those profiles first.'
            );

            return self::FAILURE;
        }

        $this->newLine();
        $this->warn('Writing to the database now.');
        if (! $force && ! $this->confirm("Import historical totals into \"{$tenantName}\"?", false)) {
            $this->info('Aborted — nothing written.');

            return self::FAILURE;
        }

        $orders = 0;
        $bar = $this->output->createProgressBar(count($this->months));

        DB::transaction(function () use ($tenant, &$orders, $bar) {
            foreach ($this->months as $i => $month) {
                $customer = Customer::withoutGlobalScopes()->create([
                    'tenant_id' => $tenant->id,
                    'name' => 'Historical – ' . $month['label'],
                    'phone' => self::PLACEHOLDER_PREFIX . str_pad((string) ($i + 1), 8, '0', STR_PAD_LEFT),
                    // A synthetic bookkeeping profile, not a person: no consent
                    // to claim and nothing to message.
                    'data_consent_at' => null,
                    'whatsapp_opt_in' => false,
                ]);

                // Backdate the profile to the month it represents. Otherwise all
                // 48 land on today's date and crowd the top of the customer list,
                // ahead of the store's actual current customers.
                $customer->forceFill([
                    'created_at' => $month['date'],
                    'updated_at' => $month['date'],
                ])->saveQuietly();

                foreach (self::CHANNELS as $method => $label) {
                    if ($month[$method] <= 0) {
                        continue;
                    }
                    $this->writeOrder($tenant, $customer, $month, $method, $label);
                    $orders++;
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->info(sprintf(
            'Imported %d month(s) as %d delivered order(s) into "%s".',
            count($this->months), $orders, $tenantName
        ));

        return self::SUCCESS;
    }

    /** @param array<string,mixed> $month */
    private function writeOrder(Tenant $tenant, Customer $customer, array $month, string $method, string $label): void
    {
        $amount = $month[$method];
        $date = $month['date'];

        $order = Order::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'status' => 'delivered',
            'fulfillment_type' => 'instant',
            'subtotal' => $amount,
            'discount_amount' => 0,
            'total_amount' => $amount,
            'advance_paid' => $amount,
        ]);

        // Analytics buckets revenue by created_at, so the historical date has to
        // be written after the insert (Eloquent stamps "now" on create).
        $order->forceFill(['created_at' => $date, 'updated_at' => $date])->saveQuietly();

        $payment = Payment::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'order_id' => $order->id,
            'amount' => $amount,
            'method' => $method,
            'note' => sprintf('Historical %s total for %s (migrated from the shop\'s monthly sheet).', $label, $month['label']),
        ]);

        $payment->forceFill(['created_at' => $date, 'updated_at' => $date])->saveQuietly();
    }

    // -------------------------------------------------------------- helpers

    /** Tolerates "1,82,290", " 45000 ", blanks and stray text. */
    public static function money(mixed $raw): float
    {
        $clean = preg_replace('/[^0-9.\-]/', '', (string) $raw) ?? '';

        return $clean === '' || $clean === '-' ? 0.0 : round((float) $clean, 2);
    }

    /** "JULY" / "july" / "Sept" → 7 / 7 / 9, or null if unrecognised. */
    public static function monthNumber(string $name): ?int
    {
        $name = strtolower(trim($name));

        foreach ([
            1 => 'january', 2 => 'february', 3 => 'march', 4 => 'april',
            5 => 'may', 6 => 'june', 7 => 'july', 8 => 'august',
            9 => 'september', 10 => 'october', 11 => 'november', 12 => 'december',
        ] as $number => $full) {
            if ($name === $full || $name === substr($full, 0, 3)
                || ($number === 9 && $name === 'sept')) {
                return $number;
            }
        }

        return null;
    }
}
