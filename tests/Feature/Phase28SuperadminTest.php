<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\StaffInvitation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ST-Admin (S11) — the superadmin platform panel: hard security lockdown,
 * manual subscription control, audit logging, and no privilege-escalation path.
 */
class Phase28SuperadminTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;
    private Tenant $tenant;
    private User $storeAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->superadmin = User::factory()->create(['role' => 'superadmin', 'tenant_id' => null]);
        $this->tenant = Tenant::create(['store_name' => 'Managed Optical']);
        $this->storeAdmin = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'store_admin']);
    }

    /** Act as the superadmin with a fresh password confirmation (for mutations). */
    private function asAdmin(): self
    {
        $this->actingAs($this->superadmin)
            ->withSession(['auth.password_confirmed_at' => time()]);

        return $this;
    }

    // ---- SECURITY LOCKDOWN ----------------------------------------------

    public function test_guest_is_redirected_from_panel(): void
    {
        $this->get(route('superadmin.dashboard'))->assertRedirect(route('login'));
    }

    public function test_store_admin_is_forbidden_everywhere(): void
    {
        $this->actingAs($this->storeAdmin);

        $this->get(route('superadmin.dashboard'))->assertForbidden();
        $this->get(route('superadmin.tenants.index'))->assertForbidden();
        $this->get(route('superadmin.tenants.show', $this->tenant))->assertForbidden();
        $this->get(route('superadmin.audit.index'))->assertForbidden();
    }

    public function test_staff_is_forbidden(): void
    {
        $staff = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'staff']);
        $this->actingAs($staff)->get(route('superadmin.dashboard'))->assertForbidden();
    }

    public function test_store_admin_cannot_mutate_subscriptions(): void
    {
        $this->actingAs($this->storeAdmin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('superadmin.subscription.extend-trial', $this->tenant), ['days' => 30])
            ->assertForbidden();

        // Nothing was recorded.
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_mutations_require_recent_password_confirmation(): void
    {
        // Superadmin, but WITHOUT a confirmed-password session.
        $this->actingAs($this->superadmin)
            ->post(route('superadmin.subscription.extend-trial', $this->tenant), ['days' => 30])
            ->assertRedirect(route('password.confirm'));
    }

    // ---- NO PRIVILEGE ESCALATION ----------------------------------------

    public function test_store_admin_cannot_invite_a_superadmin(): void
    {
        $this->actingAs($this->storeAdmin)
            ->post(route('tenant.staff.invite'), ['email' => 'evil@x.test', 'role' => 'superadmin'])
            ->assertSessionHasErrors('role');

        $this->assertDatabaseMissing('staff_invitations', ['role' => 'superadmin']);
        $this->assertDatabaseMissing('users', ['role' => 'superadmin', 'email' => 'evil@x.test']);
    }

    // ---- PANEL LOADS ----------------------------------------------------

    public function test_superadmin_can_view_panel_pages(): void
    {
        $this->actingAs($this->superadmin);
        $this->get(route('superadmin.dashboard'))->assertOk();
        $this->get(route('superadmin.tenants.index'))->assertOk()->assertSee('Managed Optical');
        $this->get(route('superadmin.tenants.show', $this->tenant))->assertOk()->assertSee('Managed Optical');
        $this->get(route('superadmin.audit.index'))->assertOk();
    }

    // ---- MANUAL SUBSCRIPTION CONTROL ------------------------------------

    public function test_extend_trial_lengthens_and_logs(): void
    {
        $this->asAdmin()->post(route('superadmin.subscription.extend-trial', $this->tenant), ['days' => 30])
            ->assertRedirect()->assertSessionHas('status');

        $sub = $this->tenant->subscription->fresh();
        $this->assertSame('trialing', $sub->status);
        $this->assertTrue($sub->manual);
        $this->assertTrue($sub->current_period_end->gt(now()->addDays(40)));

        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => 'subscription.trial_extended',
            'tenant_id' => $this->tenant->id,
            'admin_email' => $this->superadmin->email,
        ]);
    }

    public function test_grant_free_access_activates_and_logs(): void
    {
        $this->asAdmin()->post(route('superadmin.subscription.activate', $this->tenant), ['months' => 3, 'interval' => 'yearly'])
            ->assertRedirect()->assertSessionHas('status');

        $sub = $this->tenant->subscription->fresh();
        $this->assertSame('active', $sub->status);
        $this->assertSame('yearly', $sub->interval);
        $this->assertTrue($sub->manual);
        $this->assertTrue($sub->hasAccess());

        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'subscription.comped', 'tenant_id' => $this->tenant->id]);
    }

    public function test_raw_update_persists_with_before_after_audit(): void
    {
        $this->asAdmin()->patch(route('superadmin.subscription.update', $this->tenant), [
            'status' => 'past_due', 'tier' => 'basic', 'interval' => 'monthly',
            'current_period_end' => now()->addDays(5)->format('Y-m-d'),
        ])->assertRedirect()->assertSessionHas('status');

        $sub = $this->tenant->subscription->fresh();
        $this->assertSame('past_due', $sub->status);
        $this->assertTrue($sub->manual);

        $log = AdminAuditLog::where('action', 'subscription.updated')->first();
        $this->assertNotNull($log);
        $this->assertArrayHasKey('before', $log->meta);
        $this->assertArrayHasKey('after', $log->meta);
    }

    public function test_cancel_sets_canceled_and_logs(): void
    {
        $this->asAdmin()->post(route('superadmin.subscription.cancel', $this->tenant))
            ->assertRedirect()->assertSessionHas('status');

        $sub = $this->tenant->subscription->fresh();
        $this->assertSame('canceled', $sub->status);
        $this->assertFalse($sub->hasAccess());

        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'subscription.canceled', 'tenant_id' => $this->tenant->id]);
    }

    public function test_internal_notes_save_and_log(): void
    {
        $this->asAdmin()->patch(route('superadmin.tenants.notes', $this->tenant), ['internal_notes' => 'Wants annual plan'])
            ->assertRedirect()->assertSessionHas('status');

        $this->assertSame('Wants annual plan', $this->tenant->fresh()->internal_notes);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'tenant.notes_updated', 'tenant_id' => $this->tenant->id]);
    }

    public function test_audit_records_capture_actor_and_ip(): void
    {
        $this->asAdmin()->post(route('superadmin.subscription.extend-trial', $this->tenant), ['days' => 7]);

        $log = AdminAuditLog::latest()->first();
        $this->assertSame($this->superadmin->id, $log->admin_user_id);
        $this->assertNotNull($log->ip_address);
    }
}
