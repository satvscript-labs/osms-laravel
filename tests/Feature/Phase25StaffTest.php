<?php

namespace Tests\Feature;

use App\Mail\StaffInvitationMail;
use App\Models\StaffInvitation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Bunch 4 — ST-Staff (S3) team management + cap, and the ST-Lifecycle
 * last-admin guard (S10).
 */
class Phase25StaffTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->tenant = Tenant::create(['store_name' => 'Team Optical']);
        $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'store_admin']);
    }

    // ---- invite ----------------------------------------------------------

    public function test_admin_can_invite_a_staff_member(): void
    {
        $this->actingAs($this->admin)->post(route('tenant.staff.invite'), [
            'email' => 'new@staff.test', 'role' => 'staff',
        ])->assertRedirect()->assertSessionHas('status');

        $this->assertDatabaseHas('staff_invitations', [
            'tenant_id' => $this->tenant->id, 'email' => 'new@staff.test', 'role' => 'staff',
        ]);
        Mail::assertQueued(StaffInvitationMail::class);
    }

    public function test_invite_is_blocked_at_seat_limit(): void
    {
        config(['saas.max_staff' => 1]); // the admin already fills the only seat

        $this->actingAs($this->admin)->post(route('tenant.staff.invite'), [
            'email' => 'extra@staff.test', 'role' => 'staff',
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertDatabaseCount('staff_invitations', 0);
    }

    public function test_pending_invite_counts_toward_seats(): void
    {
        config(['saas.max_staff' => 2]); // admin + one invite = full

        $this->actingAs($this->admin)->post(route('tenant.staff.invite'), ['email' => 'a@staff.test', 'role' => 'staff']);
        // Second invite should now be blocked (admin + 1 pending = 2 = limit).
        $this->actingAs($this->admin)->post(route('tenant.staff.invite'), ['email' => 'b@staff.test', 'role' => 'staff'])
            ->assertSessionHas('error');

        $this->assertDatabaseCount('staff_invitations', 1);
    }

    public function test_cannot_invite_existing_member(): void
    {
        $this->actingAs($this->admin)->post(route('tenant.staff.invite'), [
            'email' => strtoupper($this->admin->email), 'role' => 'staff',
        ])->assertSessionHas('error');

        $this->assertDatabaseCount('staff_invitations', 0);
    }

    public function test_staff_cannot_access_team_page(): void
    {
        $staff = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'staff']);

        $this->actingAs($staff)->get(route('tenant.staff.index'))->assertForbidden();
    }

    // ---- accept ----------------------------------------------------------

    private function makeInvitation(array $overrides = []): StaffInvitation
    {
        return StaffInvitation::withoutGlobalScopes()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'email' => 'invitee@staff.test',
            'role' => 'staff',
            'token' => StaffInvitation::freshToken(),
            'expires_at' => now()->addDays(7),
        ], $overrides));
    }

    public function test_invitee_can_accept_and_join(): void
    {
        $invite = $this->makeInvitation();

        $this->get(route('invitations.show', $invite->token))->assertOk();

        $this->post(route('invitations.accept', $invite->token), [
            'name' => 'New Person',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])->assertRedirect(route('tenant.dashboard'));

        $this->assertDatabaseHas('users', [
            'email' => 'invitee@staff.test', 'tenant_id' => $this->tenant->id, 'role' => 'staff',
        ]);
        $this->assertNotNull($invite->fresh()->accepted_at);
        $this->assertAuthenticated();
    }

    public function test_expired_invitation_is_rejected(): void
    {
        $invite = $this->makeInvitation(['expires_at' => now()->subDay()]);

        $this->get(route('invitations.show', $invite->token))->assertRedirect(route('login'));
        $this->post(route('invitations.accept', $invite->token), [
            'name' => 'X', 'password' => 'Password123!', 'password_confirmation' => 'Password123!',
        ])->assertRedirect(route('login'));

        $this->assertDatabaseMissing('users', ['email' => 'invitee@staff.test']);
    }

    // ---- manage: role + remove + isolation -------------------------------

    public function test_cannot_demote_last_admin(): void
    {
        $this->actingAs($this->admin)->patch(route('tenant.staff.role', $this->admin), ['role' => 'staff'])
            ->assertSessionHas('error');

        $this->assertSame('store_admin', $this->admin->fresh()->role);
    }

    public function test_cannot_remove_last_admin(): void
    {
        $staff = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'staff']);

        // Removing staff is fine, but the sole admin can't be removed.
        $this->actingAs($staff); // acts irrelevant; admin performs the action
        $this->actingAs($this->admin)->delete(route('tenant.staff.remove', $this->admin))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('users', ['id' => $this->admin->id]);
    }

    public function test_admin_can_remove_a_staff_member(): void
    {
        $staff = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'staff']);

        $this->actingAs($this->admin)->delete(route('tenant.staff.remove', $staff))
            ->assertSessionHas('status');

        $this->assertDatabaseMissing('users', ['id' => $staff->id]);
    }

    public function test_cannot_manage_another_tenants_member(): void
    {
        $otherTenant = Tenant::create(['store_name' => 'Other']);
        $otherUser = User::factory()->create(['tenant_id' => $otherTenant->id, 'role' => 'staff']);

        $this->actingAs($this->admin)->delete(route('tenant.staff.remove', $otherUser))->assertNotFound();
    }

    public function test_cannot_revoke_another_tenants_invitation(): void
    {
        $otherTenant = Tenant::create(['store_name' => 'Other']);
        $otherInvite = StaffInvitation::withoutGlobalScopes()->create([
            'tenant_id' => $otherTenant->id, 'email' => 'x@x.test', 'role' => 'staff',
            'token' => StaffInvitation::freshToken(), 'expires_at' => now()->addDays(7),
        ]);

        $this->actingAs($this->admin)->delete(route('tenant.staff.invitations.revoke', $otherInvite))
            ->assertNotFound();
    }

    // ---- ST-Lifecycle: last-admin self-delete guard ----------------------

    public function test_last_admin_cannot_delete_their_account(): void
    {
        $this->actingAs($this->admin)->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertSessionHas('error');

        $this->assertDatabaseHas('users', ['id' => $this->admin->id]);
    }

    public function test_non_last_admin_can_delete_their_account(): void
    {
        $second = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'store_admin']);

        $this->actingAs($second)->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertRedirect('/');

        $this->assertDatabaseMissing('users', ['id' => $second->id]);
    }
}
