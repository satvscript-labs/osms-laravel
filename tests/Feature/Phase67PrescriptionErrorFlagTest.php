<?php

namespace Tests\Feature;

use App\Console\Commands\FlagPrescriptionErrors;
use App\Models\Customer;
use App\Models\EyeRecord;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Marking customers whose prescriptions cannot be dispensed.
 *
 * The important behaviour is that it RECONCILES rather than only tagging: the
 * marker has to disappear once the record is corrected, or the shop is left
 * chasing names that are already fine.
 */
class Phase67PrescriptionErrorFlagTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['store_name' => 'Flag Optical']);
    }

    private function customer(string $name): Customer
    {
        return Customer::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'name' => $name,
            'phone' => '+91 98244' . random_int(10000, 99999),
        ]);
    }

    /** @param array<string,mixed> $attrs */
    private function rx(Customer $customer, array $attrs): EyeRecord
    {
        return EyeRecord::withoutGlobalScopes()->create($attrs + [
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
        ]);
    }

    private function flag(array $options = []): int
    {
        return $this->artisan('osms:flag-prescription-errors', array_merge([
            '--tenant-id' => $this->tenant->id,
            '--commit' => true,
            '--force' => true,
        ], $options))->run();
    }

    public function test_a_cylinder_without_an_axis_marks_the_customer(): void
    {
        $c = $this->customer('Jaustin Patel');
        $this->rx($c, ['od_sph' => -1.75, 'od_cyl' => -2.5]);

        $this->flag();

        $this->assertSame('Jaustin Patel ' . FlagPrescriptionErrors::MARKER, $c->fresh()->name);
    }

    public function test_an_axis_without_a_cylinder_marks_the_customer(): void
    {
        $c = $this->customer('Geeta Khirsariya');
        $this->rx($c, ['od_sph' => -0.5, 'od_axis' => 160]);

        $this->flag();

        $this->assertStringContainsString(FlagPrescriptionErrors::MARKER, $c->fresh()->name);
    }

    public function test_a_complete_prescription_is_left_alone(): void
    {
        $c = $this->customer('Healthy Patient');
        $this->rx($c, ['od_sph' => -1.0, 'od_cyl' => -0.75, 'od_axis' => 90]);

        $this->flag();

        $this->assertSame('Healthy Patient', $c->fresh()->name);
    }

    /** A zero cylinder means no astigmatism, so no axis is expected. */
    public function test_a_zero_cylinder_is_not_a_fault(): void
    {
        $c = $this->customer('Plano Patient');
        $this->rx($c, ['od_sph' => -3.5, 'od_cyl' => 0]);

        $this->flag();

        $this->assertSame('Plano Patient', $c->fresh()->name);
    }

    /** The whole point of reconciling: fixing the record clears the marker. */
    public function test_the_marker_is_removed_once_the_record_is_corrected(): void
    {
        $c = $this->customer('Ashish Patel');
        $record = $this->rx($c, ['od_sph' => -4.5, 'od_cyl' => -0.75]);

        $this->flag();
        $this->assertStringContainsString(FlagPrescriptionErrors::MARKER, $c->fresh()->name);

        $record->forceFill(['od_axis' => 45])->save();
        $this->flag();

        $this->assertSame('Ashish Patel', $c->fresh()->name, 'the marker must clear itself');
    }

    public function test_running_twice_does_not_double_mark(): void
    {
        $c = $this->customer('Twice Marked');
        $this->rx($c, ['od_sph' => -1.0, 'od_cyl' => -1.0]);

        $this->flag();
        $this->flag();

        $this->assertSame(1, substr_count($c->fresh()->name, FlagPrescriptionErrors::MARKER));
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $c = $this->customer('Untouched');
        $this->rx($c, ['od_sph' => -1.0, 'od_cyl' => -1.0]);

        $this->artisan('osms:flag-prescription-errors', ['--tenant-id' => $this->tenant->id])
            ->assertSuccessful();

        $this->assertSame('Untouched', $c->fresh()->name);
    }

    public function test_another_store_is_untouched(): void
    {
        $other = Tenant::create(['store_name' => 'Other Optical']);
        $theirs = Customer::withoutGlobalScopes()->create([
            'tenant_id' => $other->id, 'name' => 'Their Patient', 'phone' => '+91 9812300000',
        ]);
        EyeRecord::withoutGlobalScopes()->create([
            'tenant_id' => $other->id, 'customer_id' => $theirs->id,
            'od_sph' => -1.0, 'od_cyl' => -1.0,
        ]);

        $this->flag();

        $this->assertSame('Their Patient', $theirs->fresh()->name);
    }

    public function test_the_left_eye_is_checked_too(): void
    {
        $c = $this->customer('Left Only');
        $this->rx($c, ['os_sph' => -1.0, 'os_cyl' => -0.25]);

        $this->flag();

        $this->assertStringContainsString(FlagPrescriptionErrors::MARKER, $c->fresh()->name);
    }
}
