<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\EyeRecord;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Prescription entry rules.
 *
 * These are clinical constraints, not form preferences: a cylinder without an
 * axis cannot be ground into a lens, and an axis of 0 is not a reading. The
 * V/S cases are drawn from the values actually present in the migrated store —
 * a "standard values" dropdown would have rejected 371 of them.
 */
class Phase66PrescriptionRulesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create(['store_name' => 'Rx Optical']);
        $this->user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'store_admin']);
        $this->customer = Customer::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'name' => 'Test Patient', 'phone' => '+91 9824400001',
        ]);
    }

    /** @param array<string,mixed> $payload */
    private function store(array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)->post(
            route('tenant.eye-records.store', $this->customer),
            $payload,
        );
    }

    // ------------------------------------------------------- cylinder ↔ axis

    public function test_a_cylinder_without_an_axis_is_rejected(): void
    {
        $this->store(['od_sph' => '-2.00', 'od_cyl' => '-0.75'])
            ->assertSessionHasErrors('od_axis');

        $this->assertSame(0, EyeRecord::withoutGlobalScopes()->count());
    }

    public function test_an_axis_without_a_cylinder_is_rejected(): void
    {
        $this->store(['od_sph' => '-2.00', 'od_axis' => '90'])
            ->assertSessionHasErrors('od_axis');
    }

    public function test_a_cylinder_with_an_axis_is_accepted(): void
    {
        $this->store(['od_sph' => '-2.00', 'od_cyl' => '-0.75', 'od_axis' => '90'])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, EyeRecord::withoutGlobalScopes()->count());
    }

    /** A zero cylinder means no astigmatism, so no axis is expected. */
    public function test_a_zero_cylinder_needs_no_axis(): void
    {
        $this->store(['od_sph' => '-2.00', 'od_cyl' => '0'])
            ->assertSessionHasNoErrors();
    }

    public function test_the_rule_applies_to_the_left_eye_too(): void
    {
        $this->store(['os_sph' => '-2.00', 'os_cyl' => '-0.50'])
            ->assertSessionHasErrors('os_axis');
    }

    /** The message has to name the fix — 23 legacy rows already break this. */
    public function test_the_error_explains_how_to_fix_it(): void
    {
        $errors = $this->store(['od_sph' => '-2.00', 'od_cyl' => '-0.75'])
            ->assertSessionHasErrors('od_axis')
            ->getSession()->get('errors')->get('od_axis');

        $this->assertStringContainsString('Right eye', $errors[0]);
        $this->assertStringContainsString('clear the cylinder', $errors[0]);
    }

    // ------------------------------------------------------------------ axis

    /** An axis of 0 is not a distinct angle — it is treated as "not recorded". */
    public function test_an_axis_of_zero_is_stored_as_nothing_rather_than_rejected(): void
    {
        $this->store(['od_sph' => '-2.00', 'od_axis' => '0'])->assertSessionHasNoErrors();

        $this->assertNull(EyeRecord::withoutGlobalScopes()->firstOrFail()->od_axis);
    }

    public function test_an_axis_above_180_is_rejected(): void
    {
        $this->store(['od_sph' => '-2.00', 'od_cyl' => '-0.75', 'od_axis' => '181'])
            ->assertSessionHasErrors('od_axis');
    }

    public function test_axis_1_and_180_are_both_valid(): void
    {
        foreach (['1', '180'] as $axis) {
            $this->store(['od_sph' => '-2.00', 'od_cyl' => '-0.75', 'od_axis' => $axis])
                ->assertSessionHasNoErrors();
        }

        $this->assertSame(2, EyeRecord::withoutGlobalScopes()->count());
    }

    // ------------------------------------------------------------------ V/S

    /**
     * Every one of these appears in the migrated store, or is standard notation.
     * 6/4, 6/5, 6/8, 6/10 and 6/9+ are exactly the values a "standard list"
     * dropdown would have thrown away.
     */
    public static function validAcuities(): array
    {
        return array_map(fn ($v) => [$v], [
            '6/6', '6/5', '6/4', '6/9', '6/12', '6/18', '6/8', '6/36', '6/7.5', '6/60', '6/10',
            '6/9+', '6/9-', '6/12+2',
            '20/20', '20/40', '20/200', '20/400', '20/15', '20/10',
            '1.0', '0.5', '0.05', '2.0', '.8',
            'CF', 'HM', 'PL', 'NPL', 'cf', 'npl',
        ]);
    }

    #[DataProvider('validAcuities')]
    public function test_real_acuity_notations_are_accepted(string $va): void
    {
        $this->store(['od_sph' => '-1.00', 'od_va' => $va])
            ->assertSessionHasNoErrors();
    }

    public static function invalidAcuities(): array
    {
        return array_map(fn ($v) => [$v], [
            'asd',      // junk found in the real data
            '90',       // an axis typed into the V/S field — also real
            '20 20',    // the classic free-text mistake
            '20-20',
            '5/6',      // no such distance
            '6/',
            '/6',
            '3.5',      // above the decimal maximum of 2.0
        ]);
    }

    #[DataProvider('invalidAcuities')]
    public function test_junk_acuity_values_are_rejected(string $va): void
    {
        $this->store(['od_sph' => '-1.00', 'od_va' => $va])
            ->assertSessionHasErrors('od_va');
    }

    public function test_a_blank_acuity_is_fine(): void
    {
        $this->store(['od_sph' => '-1.00', 'od_va' => ''])->assertSessionHasNoErrors();
    }

    /**
     * The field expands bare digits on blur (66 -> 6/6) so nobody has to reach
     * for "/" mid-examination. That happens in the browser, so what this asserts
     * is the other half of the contract: the SHORTHAND itself must not be
     * accepted by the server. If the expansion ever silently stopped running,
     * "66" would otherwise be saved as a meaningless acuity instead of failing
     * loudly.
     */
    public function test_unexpanded_shorthand_is_rejected_by_the_server(): void
    {
        foreach (['66', '69', '612', '2020'] as $shorthand) {
            $this->store(['od_sph' => '-1.00', 'od_va' => $shorthand])
                ->assertSessionHasErrors('od_va');
        }
    }

    /** …and the expanded forms it produces are all valid. */
    public function test_the_expanded_forms_are_accepted(): void
    {
        foreach (['6/6', '6/9', '6/12', '20/20'] as $expanded) {
            $this->store(['od_sph' => '-1.00', 'od_va' => $expanded])
                ->assertSessionHasNoErrors();
        }

        $this->assertSame(4, EyeRecord::withoutGlobalScopes()->count());
    }

    // ---------------------------------------------------------- still intact

    public function test_a_completely_blank_record_is_still_rejected(): void
    {
        $this->store([])->assertSessionHasErrors('od_sph');
    }

    public function test_a_normal_full_prescription_saves(): void
    {
        $this->store([
            'od_sph' => '+0.75', 'od_cyl' => '-0.50', 'od_axis' => '90', 'od_va' => '6/6',
            'od_add' => '+2.00', 'od_nv' => '+2.75',
            'os_sph' => '+0.75', 'os_cyl' => '-0.50', 'os_axis' => '85', 'os_va' => '6/9',
            'os_add' => '+2.00', 'os_nv' => '+2.75',
            'pd' => '62',
        ])->assertSessionHasNoErrors();

        $record = EyeRecord::withoutGlobalScopes()->firstOrFail();
        $this->assertEquals(0.75, $record->od_sph);
        $this->assertEquals(2.75, $record->od_nv, 'near = sphere + add');
        $this->assertSame(90, $record->od_axis);
    }

    /** A leading "+" is how the field now renders, so it must post cleanly. */
    public function test_a_leading_plus_sign_is_accepted(): void
    {
        $this->store(['od_sph' => '+2.50', 'od_add' => '+1.00'])->assertSessionHasNoErrors();

        $this->assertEquals(2.5, EyeRecord::withoutGlobalScopes()->firstOrFail()->od_sph);
    }
}
