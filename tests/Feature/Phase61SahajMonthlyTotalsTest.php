<?php

namespace Tests\Feature;

use App\Console\Commands\ImportSahajMonthlyTotals;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Sahaj Optical historical monthly totals.
 *
 * The whole point of this import is that Analytics can answer "what did March
 * 2023 make?", so the acceptance test below asks exactly that question the way
 * AnalyticsController does — delivered orders bucketed by `created_at`, payments
 * split by method — rather than just counting rows.
 */
class Phase61SahajMonthlyTotalsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['store_name' => 'Monthly Test Optical']);
        User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'store_admin']);

        $this->file = $this->makeSheet([
            // year, month, day, cash, upi, total
            [2022, 'JULY', 24, 35000, 10000, 45000],
            [2023, 'MARCH', 1, 349175, 52794, 401969],
            // A month whose stated total is wrong — the components must win.
            [2025, 'JUNE', 1, 217090, 115110, 33220],
            // The still-running month: present but empty, must be skipped.
            [2026, 'JULY', 1, null, null, null],
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    /** @param list<array<int,mixed>> $rows */
    private function makeSheet(array $rows): string
    {
        $ss = new Spreadsheet;
        $sheet = $ss->getActiveSheet();
        // Header deliberately keeps the source file's "CASE" misspelling.
        $sheet->fromArray(['YEAR', 'MONTH', 'DATE', 'CASE', 'UPI', 'TOTAL'], null, 'A1');
        $sheet->fromArray($rows, null, 'A2');

        $path = tempnam(sys_get_temp_dir(), 'monthly') . '.xlsx';
        (new Xlsx($ss))->save($path);

        return $path;
    }

    private function runImport(array $extra = []): int
    {
        return $this->artisan('osms:import-sahaj-monthly', array_merge([
            '--file' => $this->file,
            '--tenant' => 'Monthly Test Optical',
            '--commit' => true,
            '--force' => true,
        ], $extra))->run();
    }

    // ------------------------------------------------------------- parsing

    #[DataProvider('moneyProvider')]
    public function test_money_parsing(mixed $raw, float $expected): void
    {
        $this->assertSame($expected, ImportSahajMonthlyTotals::money($raw));
    }

    public static function moneyProvider(): array
    {
        return [
            'plain integer' => [35000, 35000.0],
            'indian grouping' => ['1,82,290', 182290.0],
            'padded string' => ['  45000 ', 45000.0],
            'rupee prefix' => ['₹ 12,500', 12500.0],
            'decimal' => ['1234.50', 1234.5],
            'blank' => ['', 0.0],
            'null' => [null, 0.0],
        ];
    }

    #[DataProvider('monthProvider')]
    public function test_month_name_parsing(string $raw, ?int $expected): void
    {
        $this->assertSame($expected, ImportSahajMonthlyTotals::monthNumber($raw));
    }

    public static function monthProvider(): array
    {
        return [
            'upper case' => ['JULY', 7],
            'mixed case' => ['March', 3],
            'padded' => ['  december  ', 12],
            'three-letter' => ['SEP', 9],
            'sept' => ['Sept', 9],
            'nonsense' => ['SMARCH', null],
            'blank' => ['', null],
        ];
    }

    // ----------------------------------------------------------- dry run

    public function test_dry_run_writes_nothing(): void
    {
        $this->artisan('osms:import-sahaj-monthly', [
            '--file' => $this->file,
            '--tenant' => 'Monthly Test Optical',
        ])->assertSuccessful();

        $this->assertSame(0, Customer::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(0, Order::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count());
    }

    // ------------------------------------------------------------ writing

    public function test_historical_profiles_are_backdated_to_their_own_month(): void
    {
        $this->runImport();

        // Otherwise all 48 land on today and crowd out the store's real
        // customers at the top of the list.
        $march = Customer::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('name', 'Historical – March 2023')
            ->firstOrFail();

        $this->assertSame('2023-03-01', $march->created_at->toDateString());

        $this->assertSame(
            0,
            Customer::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)
                ->whereDate('created_at', now()->toDateString())->count(),
            'no historical profile may carry today as its added date',
        );
    }

    public function test_a_channel_the_sheet_cannot_hold_is_supplemented(): void
    {
        // August 2025's stated total includes a 2,300 RTGS payment that has no
        // column in the sheet; it must land under the "other" method so the
        // month reconciles.
        $file = $this->makeSheet([[2025, 'AUGUST', 1, 195450, 70080, 267830]]);

        $this->artisan('osms:import-sahaj-monthly', [
            '--file' => $file, '--tenant' => 'Monthly Test Optical',
            '--commit' => true, '--force' => true,
        ])->assertSuccessful();
        @unlink($file);

        $byMethod = Payment::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->selectRaw('method, SUM(amount) as total')
            ->groupBy('method')->pluck('total', 'method');

        $this->assertSame(195450.0, (float) $byMethod['cash']);
        $this->assertSame(70080.0, (float) $byMethod['upi']);
        $this->assertSame(2300.0, (float) $byMethod['other']);
        $this->assertSame(267830.0, (float) $byMethod->sum(), 'must match the sheet total exactly');
    }

    public function test_it_creates_one_customer_and_two_orders_per_month(): void
    {
        $this->assertSame(0, $this->runImport());

        $customers = Customer::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->get();

        // Three months with money; the empty July 2026 row is skipped.
        $this->assertCount(3, $customers);
        $this->assertEqualsCanonicalizing(
            ['Historical – July 2022', 'Historical – March 2023', 'Historical – June 2025'],
            $customers->pluck('name')->all(),
        );
        $this->assertSame(6, Order::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(6, Payment::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_analytics_can_read_back_a_single_month(): void
    {
        $this->runImport();

        $from = Carbon::create(2023, 3, 1)->startOfDay();
        $to = Carbon::create(2023, 3, 31)->endOfDay();

        // Exactly the AnalyticsController query: delivered orders by created_at.
        $revenue = (float) Order::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('status', 'delivered')
            ->whereBetween('created_at', [$from, $to])
            ->sum('total_amount');

        $this->assertSame(401969.0, $revenue, 'March 2023 revenue must equal cash + UPI');

        // ...and the payment-method split.
        $byMethod = Payment::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('method, SUM(amount) as total')
            ->groupBy('method')
            ->pluck('total', 'method');

        $this->assertSame(349175.0, (float) $byMethod['cash']);
        $this->assertSame(52794.0, (float) $byMethod['upi']);
    }

    public function test_a_wrong_stated_total_does_not_override_the_components(): void
    {
        $this->runImport();

        // The sheet says June 2025 totalled 33,220; cash + UPI is 332,200.
        $revenue = (float) Order::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->whereBetween('created_at', [Carbon::create(2025, 6, 1)->startOfDay(), Carbon::create(2025, 6, 30)->endOfDay()])
            ->sum('total_amount');

        $this->assertSame(332200.0, $revenue);
    }

    public function test_orders_are_fully_paid_and_leave_no_outstanding_balance(): void
    {
        $this->runImport();

        // These are closed historical sales — a stray balance would show up as a
        // pending due on the dashboard forever.
        $this->assertSame(
            0,
            Order::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->where('balance_due', '>', 0)->count(),
        );
    }

    public function test_synthetic_profiles_never_claim_consent(): void
    {
        $this->runImport();

        $q = Customer::withoutGlobalScopes()->where('tenant_id', $this->tenant->id);

        $this->assertSame(0, (clone $q)->whereNotNull('data_consent_at')->count());
        $this->assertSame(0, (clone $q)->where('whatsapp_opt_in', true)->count());
    }

    public function test_a_second_run_is_refused_so_revenue_cannot_double(): void
    {
        $this->assertSame(0, $this->runImport());
        $this->assertSame(1, $this->runImport(), 'a repeat run must fail, not append');

        $this->assertSame(6, Order::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_placeholder_numbers_cannot_collide_with_the_customer_migration(): void
    {
        $this->runImport();

        // The customer migration hands out "+91 0" + 9 digits starting at
        // 000000001; this one uses "+91 09" + 8 digits. Neither block can reach
        // into the other.
        foreach (Customer::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->pluck('phone') as $phone) {
            $this->assertStringStartsWith('+91 09', $phone);
        }
    }

    /**
     * `tenants.store_name` is not unique and MySQL matches it case-insensitively,
     * so production can hold both "Sahaj Optical" and "SAHAJ OPTICAL". Picking
     * one at random would import thousands of rows into the wrong store.
     */
    public function test_an_ambiguous_store_name_is_refused_rather_than_guessed(): void
    {
        Tenant::create(['store_name' => 'Monthly Test Optical']); // a second one

        $this->assertSame(1, $this->runImport(), 'must fail rather than pick one');

        $this->assertSame(
            0,
            Customer::withoutGlobalScopes()->whereNotNull('tenant_id')->count(),
            'nothing may be written while the target is ambiguous',
        );
    }

    public function test_tenant_id_resolves_unambiguously_past_a_duplicate_name(): void
    {
        $decoy = Tenant::create(['store_name' => 'Monthly Test Optical']);

        $this->assertSame(0, $this->runImport(['--tenant-id' => $this->tenant->id]));

        $this->assertSame(3, Customer::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(0, Customer::withoutGlobalScopes()->where('tenant_id', $decoy->id)->count());
    }

    public function test_an_unknown_tenant_id_writes_nothing(): void
    {
        $this->assertSame(1, $this->runImport(['--tenant-id' => '00000000-0000-0000-0000-000000000000']));
        $this->assertSame(0, Customer::withoutGlobalScopes()->whereNotNull('tenant_id')->count());
    }

    public function test_it_does_not_touch_another_store(): void
    {
        $other = Tenant::create(['store_name' => 'Bystander Optical']);

        $this->runImport();

        $this->assertSame(0, Customer::withoutGlobalScopes()->where('tenant_id', $other->id)->count());
        $this->assertSame(0, Order::withoutGlobalScopes()->where('tenant_id', $other->id)->count());
    }
}
