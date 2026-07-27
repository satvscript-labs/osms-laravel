<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SHARE-01 phase 5 — handing back the numbers the old unique index refused.
 *
 * The risk here is not failing to restore a number; it is restoring the WRONG
 * one, or rewriting a row that never came from the import. Both are covered.
 */
class Phase64RestoreSharedNumbersTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['store_name' => 'Restore Optical']);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'store_admin']);

        $this->dir = sys_get_temp_dir() . '/restore_' . uniqid();
        mkdir($this->dir);

        // SUNITA holds 9824459668 (most recent visit). PRIYA shares it but visited
        // earlier, so the import gave her a placeholder. RAHUL never had a number.
        file_put_contents($this->dir . '/u174003801_sahaj_optical_table_eyerecourd.sql',
            "INSERT INTO `eyerecourd` (`id`, `name`, `contectno`, `date`, `lspl`) VALUES\n"
            . "('1', 'PRIYA SHAH', '9824459668', '2024-01-10', '-1.00'),\n"
            . "('2', 'SUNITA SHAH', '9824459668', '2025-06-01', '-2.00'),\n"
            . "('3', 'RAHUL PATEL', '', '2024-03-05', '-1.50');\n");
        file_put_contents($this->dir . '/u174003801_sahaj_optical_table_estimatebook.sql',
            "INSERT INTO `estimatebook` (`order_no`, `first_name`, `contact`, `date`, `total`) VALUES\n"
            . "('1', 'SUNITA SHAH', '9824459668', '2025-06-01', '900');\n");

        $this->artisan('osms:import-sahaj-legacy', [
            '--dir' => $this->dir,
            '--tenant-id' => $this->tenant->id,
            '--no-report' => true,
            '--commit' => true,
            '--force' => true,
        ])->assertSuccessful();
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*'));
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function restore(array $options = []): int
    {
        return $this->artisan('osms:restore-shared-numbers', array_merge([
            '--dir' => $this->dir,
            '--tenant-id' => $this->tenant->id,
            '--commit' => true,
            '--force' => true,
        ], $options))->run();
    }

    private function find(string $like): Customer
    {
        return Customer::withoutGlobalScopes()->where('name', 'like', $like . '%')->firstOrFail();
    }

    public function test_the_import_left_priya_on_a_placeholder(): void
    {
        $this->assertStringStartsWith('+91 0', $this->find('Priya')->phone);
        $this->assertSame('+91 9824459668', $this->find('Sunita')->phone);
    }

    public function test_it_restores_the_real_number_priya_actually_had(): void
    {
        $this->restore();

        $this->assertSame('+91 9824459668', $this->find('Priya')->phone);
        $this->assertSame('+91 9824459668', $this->find('Sunita')->phone, 'the holder keeps it too');
    }

    /** The marker described a limitation that no longer exists. */
    public function test_the_marker_is_dropped_once_the_number_is_back(): void
    {
        $this->restore();

        $this->assertSame('Priya Shah', $this->find('Priya')->name);
    }

    /** Someone who never had a number gets a real NULL, not another fake one. */
    public function test_a_customer_who_never_had_a_number_is_blanked_not_invented(): void
    {
        $this->restore();

        $rahul = $this->find('Rahul');
        $this->assertNull($rahul->phone);
        $this->assertStringContainsString('[Action needed]', $rahul->name, 'they still need a number');
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $before = $this->find('Priya')->phone;

        $this->artisan('osms:restore-shared-numbers', [
            '--dir' => $this->dir, '--tenant-id' => $this->tenant->id,
        ])->assertSuccessful();

        $this->assertSame($before, $this->find('Priya')->phone);
    }

    /**
     * Rows that did not come from this import — the historical monthly profiles,
     * or anything staff added themselves — sit on placeholder-shaped numbers too
     * and must be left exactly as they are.
     */
    public function test_customers_not_from_the_import_are_never_touched(): void
    {
        $historical = Customer::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Historical – March 2023',
            'phone' => '+91 0900000007',
        ]);
        $staffAdded = Customer::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'A Test Customer',
            'phone' => '+91 0000009999',
        ]);

        $this->restore();

        $this->assertSame('+91 0900000007', $historical->fresh()->phone);
        $this->assertSame('Historical – March 2023', $historical->fresh()->name);
        $this->assertSame('+91 0000009999', $staffAdded->fresh()->phone);
    }

    /** A customer renamed by staff since the import is no longer safely identifiable. */
    public function test_a_renamed_customer_is_skipped_not_guessed_at(): void
    {
        $priya = $this->find('Priya');
        $priya->forceFill(['name' => 'Priya S (renamed by staff)'])->save();

        $this->restore();

        $this->assertStringStartsWith('+91 0', $priya->fresh()->phone, 'left alone');
    }

    public function test_running_twice_is_harmless(): void
    {
        $this->restore();
        $this->restore();

        $this->assertSame('+91 9824459668', $this->find('Priya')->phone);
        $this->assertNull($this->find('Rahul')->phone);
    }

    public function test_another_store_is_untouched(): void
    {
        $other = Tenant::create(['store_name' => 'Other Optical']);
        $theirs = Customer::withoutGlobalScopes()->create([
            'tenant_id' => $other->id, 'name' => 'Priya Shah [Action needed]', 'phone' => '+91 0000000001',
        ]);

        $this->restore();

        $this->assertSame('+91 0000000001', $theirs->fresh()->phone);
        $this->assertSame('Priya Shah [Action needed]', $theirs->fresh()->name);
    }
}
