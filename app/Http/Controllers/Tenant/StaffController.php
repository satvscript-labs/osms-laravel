<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Mail\StaffInvitationMail;
use App\Models\StaffInvitation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

/**
 * ST-Staff (S3) — team management for a store admin: invite, manage roles, and
 * remove members, capped by the store's seat limit.
 */
class StaffController extends Controller
{
    public function index(Request $request): View
    {
        $tenant = $request->user()->tenant;

        return view('tenant.staff.index', [
            'tenant' => $tenant,
            'members' => $tenant->users()->orderByRaw("role = 'store_admin' desc")->orderBy('name')->get(),
            'invitations' => $tenant->invitations()->whereNull('accepted_at')->latest()->get(),
        ]);
    }

    public function invite(Request $request): RedirectResponse
    {
        $tenant = $request->user()->tenant;

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', 'in:store_admin,staff'],
        ]);
        $email = strtolower($validated['email']);

        if ($tenant->users()->whereRaw('lower(email) = ?', [$email])->exists()) {
            return back()->with('error', 'That email is already a member of your store.');
        }

        $existing = $tenant->invitations()->where('email', $email)->whereNull('accepted_at')->first();

        if (! $existing && ! $tenant->canAddSeat()) {
            return back()->with('error', "You've reached your limit of {$tenant->seatLimit()} users. Remove a member to invite someone new.");
        }

        $invitation = $tenant->invitations()->updateOrCreate(
            ['email' => $email, 'accepted_at' => null],
            [
                'role' => $validated['role'],
                'token' => StaffInvitation::freshToken(),
                'expires_at' => now()->addDays((int) config('saas.invite_days', 7)),
            ],
        );

        Mail::to($email)->queue(new StaffInvitationMail($invitation, $tenant));

        return back()->with('status', "Invitation sent to {$email}.");
    }

    public function resend(Request $request, StaffInvitation $invitation): RedirectResponse
    {
        if ($invitation->accepted_at) {
            return back()->with('error', 'That invitation has already been accepted.');
        }

        $invitation->update([
            'token' => StaffInvitation::freshToken(),
            'expires_at' => now()->addDays((int) config('saas.invite_days', 7)),
        ]);

        Mail::to($invitation->email)->queue(new StaffInvitationMail($invitation, $request->user()->tenant));

        return back()->with('status', "Invitation resent to {$invitation->email}.");
    }

    public function revoke(StaffInvitation $invitation): RedirectResponse
    {
        $invitation->delete();

        return back()->with('status', 'Invitation revoked.');
    }

    public function updateRole(Request $request, User $member): RedirectResponse
    {
        $tenant = $request->user()->tenant;
        abort_unless($member->tenant_id === $tenant->id, 404);

        $validated = $request->validate(['role' => ['required', 'in:store_admin,staff']]);

        if ($validated['role'] !== 'store_admin' && $this->isLastAdmin($tenant, $member)) {
            return back()->with('error', 'Your store must keep at least one admin.');
        }

        $member->update(['role' => $validated['role']]);

        return back()->with('status', "{$member->name}'s role was updated.");
    }

    public function remove(Request $request, User $member): RedirectResponse
    {
        $tenant = $request->user()->tenant;
        abort_unless($member->tenant_id === $tenant->id, 404);

        if ($member->id === $request->user()->id) {
            return back()->with('error', 'You cannot remove yourself. Use account settings instead.');
        }

        if ($this->isLastAdmin($tenant, $member)) {
            return back()->with('error', 'Your store must keep at least one admin.');
        }

        $member->delete();

        return back()->with('status', "{$member->name} was removed from your store.");
    }

    private function isLastAdmin(Tenant $tenant, User $member): bool
    {
        return $member->role === 'store_admin'
            && $tenant->users()->where('role', 'store_admin')->count() <= 1;
    }
}
