<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Proration;
use App\Services\StoreProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * E2.5 / FB-13 — a branch pays for the whole span it rides, cycle by cycle, each
 * at its real calendar length (06 §5.1). The design document's two examples are
 * tested to the paisa, verbatim.
 */
class Phase85E2ProrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlanSeeder::class);
        // A flat rate, so the numbers are the design's (no volume tier at 2 branches).
        Plan::where('code', 'basic')->update(['monthly_price' => 499, 'yearly_price' => 4999, 'price_tiers' => null]);
        $this->actingAs(User::factory()->create(['role' => 'superadmin', 'tenant_id' => null]));
    }

    private function d(string $date): Carbon
    {
        return Carbon::parse($date, config('billing.timezone'))->startOfDay();
    }

    /** A paying account whose cover runs $start → $end (the stored clock; no ledger rows). */
    private function covered(string $interval, string $start, string $end): Subscription
    {
        $owner = User::factory()->create(['tenant_id' => null, 'role' => 'store_admin']);
        $tenant = app(StoreProvisioner::class)->provision($owner, ['store_name' => 'Cycles']);
        $sub = Subscription::withoutGlobalScopes()->where('account_id', $tenant->account_id)->firstOrFail();
        $sub->forceFill([
            'status' => 'active', 'interval' => $interval, 'quantity' => 1,
            'current_period_start' => $start, 'current_period_end' => $end,
        ])->save();

        return $sub->fresh();
    }

    private function label(array $q, string $label): ?string
    {
        foreach ($q['lines'] as $line) {
            if ($line['label'] === $label) {
                return $line['value'];
            }
        }

        return null;
    }

    public function test_design_example_monthly_prepaid_three_months_ahead(): void
    {
        // 06 §5.1: monthly, prepaid to 23 Feb 2027; the branch starts 5 Nov 2026.
        $q = app(Proration::class)->forNewBranch($this->covered('monthly', '2026-10-23', '2027-02-23'), $this->d('2026-11-05'));

        $this->assertTrue($q['applicable']);
        $this->assertEquals(1786.74, $q['amount']);
        $this->assertSame('18 of 31', $this->label($q, 'Days being charged'), 'the cycle 23 Oct → 23 Nov is 31 days long');
        $this->assertSame('23 Nov 2026 → 23 Feb 2027 = 3 × ₹ 499.00', $this->label($q, 'Whole cycles'));
        $this->assertSame('₹ 499.00 × 18 ÷ 31 + 3 × ₹ 499.00 = ₹ 1,786.74', $q['formula']);
        $this->assertSame('₹ 1,786.74', $this->label($q, 'Due now'));
        $this->assertSame('2026-11-05', $q['charged_from']->toDateString());
    }

    public function test_design_example_yearly_part_of_one_year(): void
    {
        // 06 §5.1: yearly, covered until 23 Oct 2027; the branch starts 10 Mar 2027.
        $q = app(Proration::class)->forNewBranch($this->covered('yearly', '2026-10-23', '2027-10-23'), $this->d('2027-03-10'));

        $this->assertEquals(3108.97, $q['amount']);
        $this->assertSame('227 of 365', $this->label($q, 'Days being charged'));
        $this->assertNull($this->label($q, 'Whole cycles'));
        $this->assertSame('₹ 4,999.00 × 227 ÷ 365 = ₹ 3,108.97', $q['formula']);
        $this->assertSame('₹ 3,108.97', $this->label($q, 'Part period due now'));
    }

    public function test_february_is_its_real_length(): void
    {
        // 28 Feb → 28 Mar 2027 is 28 days: half of it is half the price, exactly.
        $q = app(Proration::class)->forNewBranch($this->covered('monthly', '2027-02-28', '2027-03-28'), $this->d('2027-03-14'));

        $this->assertSame('14 of 28', $this->label($q, 'Days being charged'));
        $this->assertEquals(249.50, $q['amount']);
    }

    public function test_an_irregular_tail_is_its_own_part_cycle_and_the_working_adds_up(): void
    {
        // Cover runs a week past a cycle boundary (an extension): 23 Oct → 30 Nov.
        $q = app(Proration::class)->forNewBranch($this->covered('monthly', '2026-10-23', '2026-11-30'), $this->d('2026-11-05'));

        $this->assertSame('23 Nov 2026 → 30 Nov 2026 = 7 of 30 days', $this->label($q, 'Part cycle'));
        // 499 × 18/31 = 289.74 ; 499 × 7/30 = 116.43
        $this->assertEquals(406.17, $q['amount']);
        $this->assertEquals($q['amount'], round(array_sum(array_column($q['segments'], 'amount')), 2), 'lines sum to the total');
        $this->assertSame('₹ 499.00 × 18 ÷ 31 + ₹ 499.00 × 7 ÷ 30 = ₹ 406.17', $q['formula']);
    }

    public function test_the_branch_charge_carries_the_multi_cycle_working(): void
    {
        $sub = $this->covered('monthly', now()->subDays(5)->toDateString(), now()->addMonths(2)->addDays(25)->toDateString());

        $branch = app(StoreProvisioner::class)->provision(
            User::factory()->create(['tenant_id' => null, 'role' => 'store_admin']),
            ['store_name' => 'Cycles — Branch 2'],
            $sub->account,
        );

        $charge = \App\Models\BillingCharge::where('tenant_id', $branch->id)->sole();
        $labels = array_column($charge->calculation['lines'], 'label');

        $this->assertContains('Whole cycles', $labels);
        $this->assertStringContainsString(' + ', $charge->calculation['formula']);
        $this->assertSame(now(config('billing.timezone'))->toDateString(), $charge->period_start->toDateString(), 'charged from the day it joined');
    }
}
