<?php

namespace App\Http\Controllers;

use App\Models\StaffInvitation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\View\View;

/**
 * ST-Staff (S3) — public accept flow for a staff invitation. The invitee has no
 * tenant session yet, so these routes live outside the tenant group.
 */
class InvitationController extends Controller
{
    public function show(string $token): View|RedirectResponse
    {
        $invitation = $this->pendingInvitation($token);

        if (! $invitation) {
            return redirect()->route('login')->with('error', 'This invitation is invalid or has expired.');
        }

        return view('invitations.accept', [
            'invitation' => $invitation,
            'tenant' => Tenant::find($invitation->tenant_id),
        ]);
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->pendingInvitation($token);

        if (! $invitation) {
            return redirect()->route('login')->with('error', 'This invitation is invalid or has expired.');
        }

        if (User::where('email', $invitation->email)->exists()) {
            return redirect()->route('login')
                ->with('error', 'An account with this email already exists. Please log in.');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $invitation->email,
            'password' => Hash::make($validated['password']),
            'tenant_id' => $invitation->tenant_id,
            'role' => $invitation->role,
            'email_verified_at' => now(), // clicking the emailed link proves email control
        ]);

        $invitation->update(['accepted_at' => now()]);

        Auth::login($user);

        return redirect()->route('tenant.dashboard')->with('status', 'Welcome to the team!');
    }

    /** Look up a pending invitation by token (bypassing the tenant scope — no session yet). */
    private function pendingInvitation(string $token): ?StaffInvitation
    {
        $invitation = StaffInvitation::withoutGlobalScopes()->where('token', $token)->first();

        return $invitation && $invitation->isPending() ? $invitation : null;
    }
}
