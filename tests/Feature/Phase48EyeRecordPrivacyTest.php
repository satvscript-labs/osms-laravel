<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\EyeRecord;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * DATA-07 (soft-delete + 30-day purge for prescriptions), PRIV-03 (notes encrypted
 * at rest), PRIV-04 (staff mutation activity log).
 */
class Phase48EyeRecordPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Tenant $tenant;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['store_name' => 'Rx Optical', 'tax_id' => 'G', 'address' => 'Kochi']);
        $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'store_admin']);
        $this->customer = Customer::create(['tenant_id' => $this->tenant->id, 'name' => 'Meera', 'phone' => '+91 9800000001']);
    }

    private function rx(): EyeRecord
    {
        return EyeRecord::create([
            'tenant_id' => $this->tenant->id, 'customer_id' => $this->customer->id,
            'recorded_by' => $this->admin->id, 'od_sph' => -1.25, 'notes' => 'Sensitive clinical note',
        ]);
    }

    // ---- DATA-07: soft delete + purge ----

    public function test_deleting_a_prescription_soft_deletes_it(): void
    {
        $rx = $this->rx();

        $this->actingAs($this->admin)->delete(route('tenant.eye-records.destroy', $rx))->assertRedirect();

        $this->assertSoftDeleted('eye_records', ['id' => $rx->id]);
        $this->assertNotNull(EyeRecord::withTrashed()->find($rx->id)); // recoverable
        $this->assertNull(EyeRecord::find($rx->id));                    // hidden from normal queries
    }

    public function test_purge_removes_prescriptions_past_the_retention_window(): void
    {
        $rx = $this->rx();
        $rx->delete();
        EyeRecord::withTrashed()->where('id', $rx->id)->update(['deleted_at' => now()->subDays(40)]);

        $this->artisan('model:purge-trashed')->assertExitCode(0);

        $this->assertNull(EyeRecord::withTrashed()->find($rx->id));
    }

    // ---- PRIV-03: notes encrypted at rest ----

    public function test_notes_are_encrypted_at_rest_but_readable_through_the_model(): void
    {
        $rx = $this->rx();

        $raw = DB::table('eye_records')->where('id', $rx->id)->value('notes');
        $this->assertNotSame('Sensitive clinical note', $raw, 'Notes must not be stored in plaintext.');
        $this->assertSame('Sensitive clinical note', $rx->fresh()->notes, 'The model must decrypt notes transparently.');
    }

    // ---- PRIV-04: activity log ----

    public function test_prescription_mutations_are_logged(): void
    {
        $this->actingAs($this->admin)->post(route('tenant.eye-records.store', $this->customer), ['od_sph' => -1.0])
            ->assertRedirect();
        $this->assertDatabaseHas('activity_logs', [
            'tenant_id' => $this->tenant->id, 'action' => 'eye_record.created', 'user_id' => $this->admin->id,
        ]);

        $rx = EyeRecord::first();
        $this->actingAs($this->admin)->delete(route('tenant.eye-records.destroy', $rx))->assertRedirect();
        $this->assertDatabaseHas('activity_logs', ['action' => 'eye_record.deleted', 'subject_id' => $rx->id]);
    }

    public function test_activity_page_is_admin_only(): void
    {
        $this->rx();

        $this->actingAs($this->admin)->get(route('tenant.activity.index'))->assertOk();

        $staff = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'staff']);
        $this->actingAs($staff)->get(route('tenant.activity.index'))->assertForbidden();
    }

    public function test_activity_log_is_tenant_isolated(): void
    {
        // Log an action as this tenant's admin.
        $this->actingAs($this->admin)->post(route('tenant.eye-records.store', $this->customer), ['od_sph' => -2.0]);

        // Another tenant's admin sees none of it.
        $other = Tenant::create(['store_name' => 'Other', 'address' => 'X']);
        $otherAdmin = User::factory()->create(['tenant_id' => $other->id, 'role' => 'store_admin']);

        $this->assertSame(0, ActivityLog::query()->count() - ActivityLog::where('tenant_id', $this->tenant->id)->count());
        $this->actingAs($otherAdmin);
        $this->assertSame(0, ActivityLog::count()); // scoped to the other (empty) tenant
    }
}
