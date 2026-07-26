<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SHARE-01 — a phone number identifies a household handset, not a person.
 *
 * The headline case is BUG A: the order builder matched an inline walk-in on the
 * PHONE ALONE, so typing a family member's name against a number already on file
 * silently filed the order — and any attached prescription — under the relative.
 *
 * See _artifacts/SHARED_PHONE_DESIGN.md.
 */
class Phase63SharedPhoneTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private Inventory $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['store_name' => 'Household Optical']);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'store_admin']);
        $this->item = Inventory::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'item_type' => 'frame',
            'brand' => 'Rayban', 'model_name' => 'RB1',
            'sku' => 'RB-1', 'selling_price' => 1000, 'cost_price' => 400, 'stock_qty' => 50,
        ]);
    }

    /** @param array<string,mixed> $overrides */
    private function placeOrder(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)->post(route('tenant.orders.store'), array_merge([
            'customer_name' => 'Priya Shah',
            'customer_country_code' => '+91',
            'customer_phone' => '9824459668',
            'customer_consent' => 1,
            'fulfillment_type' => 'instant',
            'items' => [[
                'inventory_id' => $this->item->id, 'quantity' => 1, 'unit_price' => 1000,
            ]],
        ], $overrides));
    }

    private function makeCustomer(string $name, ?string $phone): Customer
    {
        return Customer::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'name' => $name, 'phone' => $phone,
        ]);
    }

    // ------------------------------------------------------------ the database

    public function test_two_people_can_share_one_number(): void
    {
        $this->makeCustomer('Sunita Shah', '+91 9824459668');
        $this->makeCustomer('Priya Shah', '+91 9824459668');

        $this->assertSame(2, Customer::withoutGlobalScopes()
            ->where('phone', '+91 9824459668')->count());
    }

    /** Seven people on one number is not hypothetical — it is in the real data. */
    public function test_a_seven_person_household_is_allowed(): void
    {
        foreach (range(1, 7) as $i) {
            $this->makeCustomer("Member {$i}", '+91 9824459668');
        }

        $this->assertSame(7, Customer::withoutGlobalScopes()
            ->where('phone', '+91 9824459668')->count());
    }

    public function test_a_customer_can_have_no_number_at_all(): void
    {
        $c = $this->makeCustomer('Anonymous Walkin', null);

        $this->assertNull($c->fresh()->phone);
    }

    /** BUG B — a soft-deleted customer used to burn their number permanently. */
    public function test_a_deleted_customers_number_can_be_reused(): void
    {
        $this->makeCustomer('Gone Away', '+91 9824459668')->delete();

        $fresh = $this->makeCustomer('New Person', '+91 9824459668');

        $this->assertNotNull($fresh->id);
    }

    // ------------------------------------------------- BUG A: the order builder

    /** The regression. Typing a relative's name must NOT return the existing profile. */
    public function test_a_new_name_on_a_shared_number_creates_its_own_profile(): void
    {
        $mother = $this->makeCustomer('Sunita Shah', '+91 9824459668');

        $this->placeOrder(['customer_name' => 'Priya Shah'])->assertRedirect();

        $order = Order::withoutGlobalScopes()->firstOrFail();
        $priya = Customer::withoutGlobalScopes()->where('name', 'Priya Shah')->firstOrFail();

        $this->assertSame($priya->id, $order->customer_id, 'the order must belong to Priya');
        $this->assertNotSame($mother->id, $order->customer_id, 'and NOT to her mother');
        $this->assertSame('+91 9824459668', $priya->phone, 'she keeps the household number');
        $this->assertSame('Sunita Shah', $mother->fresh()->name, 'the mother is untouched');
    }

    /** The same person re-typed instead of searched for is reused, not duplicated. */
    public function test_the_same_name_and_number_reuses_the_existing_profile(): void
    {
        $existing = $this->makeCustomer('Priya Shah', '+91 9824459668');

        $this->placeOrder()->assertRedirect();

        $this->assertSame(1, Customer::withoutGlobalScopes()->count(), 'no duplicate');
        $this->assertSame($existing->id, Order::withoutGlobalScopes()->firstOrFail()->customer_id);
    }

    /** Matching is forgiving about case, spacing and punctuation — but nothing more. */
    public function test_name_matching_ignores_case_spacing_and_punctuation(): void
    {
        $existing = $this->makeCustomer('Priya Shah', '+91 9824459668');

        $this->placeOrder(['customer_name' => '  pRIYA   shah. '])->assertRedirect();

        $this->assertSame(1, Customer::withoutGlobalScopes()->count());
        $this->assertSame($existing->id, Order::withoutGlobalScopes()->firstOrFail()->customer_id);
    }

    /**
     * A partial name is treated as a DIFFERENT person. On a household number
     * "Priya" and "Priya Shah" really can be two people, and guessing wrong
     * misfiles a prescription — the expensive direction of the error.
     */
    public function test_a_partial_name_is_treated_as_a_different_person(): void
    {
        $this->makeCustomer('Priya Shah', '+91 9824459668');

        $this->placeOrder(['customer_name' => 'Priya'])->assertRedirect();

        $this->assertSame(2, Customer::withoutGlobalScopes()->count());
    }

    public function test_an_order_can_be_placed_without_any_phone_number(): void
    {
        $this->placeOrder(['customer_name' => 'No Number Walkin', 'customer_phone' => null])
            ->assertRedirect();

        $customer = Customer::withoutGlobalScopes()->firstOrFail();

        $this->assertNull($customer->phone);
        $this->assertSame($customer->id, Order::withoutGlobalScopes()->firstOrFail()->customer_id);
    }

    /** Two numberless customers are not silently collapsed into one. */
    public function test_numberless_customers_are_never_matched_to_each_other(): void
    {
        $this->makeCustomer('First Walkin', null);

        $this->placeOrder(['customer_name' => 'Second Walkin', 'customer_phone' => null])
            ->assertRedirect();

        $this->assertSame(2, Customer::withoutGlobalScopes()->count());
    }

    /** Consent still only ever stamps the newly-created customer. */
    public function test_consent_is_not_written_onto_an_existing_profile(): void
    {
        $existing = $this->makeCustomer('Priya Shah', '+91 9824459668');
        $this->assertNull($existing->data_consent_at);

        $this->placeOrder(['customer_consent' => 1])->assertRedirect();

        $this->assertNull($existing->fresh()->data_consent_at, 'an existing profile is untouched');
    }

    // -------------------------------------------------------------- validation

    public function test_the_customer_form_accepts_a_number_already_in_use(): void
    {
        $this->makeCustomer('Sunita Shah', '+91 9824459668');

        $this->actingAs($this->user)->post(route('tenant.customers.store'), [
            'name' => 'Priya Shah', 'country_code' => '+91', 'phone' => '9824459668',
            'data_consent' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(2, Customer::withoutGlobalScopes()->count());
    }

    public function test_the_customer_form_accepts_a_blank_number(): void
    {
        $this->actingAs($this->user)->post(route('tenant.customers.store'), [
            'name' => 'No Number Person', 'country_code' => '+91', 'phone' => '',
            'data_consent' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertNull(Customer::withoutGlobalScopes()->firstOrFail()->phone);
    }

    public function test_a_malformed_number_is_still_rejected(): void
    {
        $this->actingAs($this->user)->post(route('tenant.customers.store'), [
            'name' => 'Bad Number', 'country_code' => '+91', 'phone' => '12345',
            'data_consent' => '1',
        ])->assertSessionHasErrors('phone');
    }

    // --------------------------------------------------------- household helpers

    public function test_household_helpers(): void
    {
        $a = $this->makeCustomer('Sunita Shah', '+91 9824459668');
        $b = $this->makeCustomer('Priya Shah', '+91 9824459668');
        $alone = $this->makeCustomer('Solo Person', '+91 9824459670');
        $none = $this->makeCustomer('No Number', null);

        $this->actingAs($this->user);

        $this->assertTrue($a->isPhoneShared());
        $this->assertFalse($alone->isPhoneShared());
        $this->assertFalse($none->isPhoneShared(), 'no number is not a household');

        $this->assertSame([$b->id], $a->householdMembers()->pluck('id')->all());
        $this->assertSame([], $none->householdMembers()->pluck('id')->all());
    }

    public function test_households_do_not_leak_across_stores(): void
    {
        $other = Tenant::create(['store_name' => 'Other Optical']);
        Customer::withoutGlobalScopes()->create([
            'tenant_id' => $other->id, 'name' => 'Stranger', 'phone' => '+91 9824459668',
        ]);
        $mine = $this->makeCustomer('Sunita Shah', '+91 9824459668');

        $this->actingAs($this->user);

        $this->assertFalse($mine->isPhoneShared(), 'another store\'s customer is not my household');
    }
}
