<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PRIV-02 — minors are kept out of birthday marketing (DPDP: no behavioural
 * marketing directed at children). A known-minor customer (derived age < 18) is
 * excluded from the birthdays outreach list and their birthday nudge is suppressed;
 * adults with an upcoming birthday still appear.
 */
class Phase41MinorPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['store_name' => 'Minor Optical', 'tax_id' => 'G', 'address' => 'Delhi']);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'store_admin']);
    }

    /** Birthday 2 days from now, N years back — so it lands in the 7-day window. */
    private function birthdayInWindow(int $yearsAgo): string
    {
        return now()->addDays(2)->subYears($yearsAgo)->format('Y-m-d');
    }

    public function test_is_minor_reflects_derived_age(): void
    {
        $minor = Customer::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Kid', 'phone' => '+91 9000000001',
            'birthday' => now()->subYears(15)->format('Y-m-d'),
        ]);
        $adult = Customer::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Grown', 'phone' => '+91 9000000002',
            'birthday' => now()->subYears(40)->format('Y-m-d'),
        ]);
        $unknown = Customer::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Nobirthday', 'phone' => '+91 9000000003',
        ]);

        $this->assertTrue($minor->isMinor());
        $this->assertFalse($adult->isMinor());
        $this->assertFalse($unknown->isMinor(), 'Unknown age must not be treated as a minor.');
    }

    public function test_minor_is_excluded_from_the_birthdays_list_but_adult_is_included(): void
    {
        $minor = Customer::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Birthday Kid', 'phone' => '+91 9000000004',
            'birthday' => $this->birthdayInWindow(12), // ~12yo, birthday in 2 days
        ]);
        $adult = Customer::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Birthday Adult', 'phone' => '+91 9000000005',
            'birthday' => $this->birthdayInWindow(30), // ~30yo, birthday in 2 days
        ]);

        $rows = $this->actingAs($this->user)
            ->getJson(route('tenant.customers.index', ['filter' => 'birthdays']))
            ->assertOk()
            ->json('customers');

        $ids = collect($rows)->pluck('id')->all();
        $this->assertContains($adult->id, $ids, 'An adult with an upcoming birthday should appear.');
        $this->assertNotContains($minor->id, $ids, 'A minor must NOT appear in the birthdays marketing list.');
    }

    public function test_minor_birthday_nudge_is_suppressed_in_the_all_list_json(): void
    {
        $minor = Customer::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Nudge Kid', 'phone' => '+91 9000000006',
            'birthday' => $this->birthdayInWindow(10),
        ]);

        $rows = $this->actingAs($this->user)
            ->getJson(route('tenant.customers.index', ['q' => 'Nudge']))
            ->assertOk()
            ->json('customers');

        $row = collect($rows)->firstWhere('id', $minor->id);
        $this->assertNotNull($row);
        $this->assertNull($row['days_until_birthday'], 'A minor should get no birthday nudge/chip.');
    }

    public function test_born_adult_scope_isolates_by_tenant(): void
    {
        // Another tenant's adult with an upcoming birthday must never leak in.
        $otherTenant = Tenant::create(['store_name' => 'Other', 'address' => 'X']);
        Customer::create([
            'tenant_id' => $otherTenant->id, 'name' => 'Other Adult', 'phone' => '+91 9000000007',
            'birthday' => $this->birthdayInWindow(35),
        ]);

        $rows = $this->actingAs($this->user)
            ->getJson(route('tenant.customers.index', ['filter' => 'birthdays']))
            ->assertOk()
            ->json('customers');

        $this->assertCount(0, $rows, 'No other-tenant customers may appear.');
    }
}
