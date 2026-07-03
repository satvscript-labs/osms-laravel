<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        // Unified Settings hub: account sections for everyone, plus store
        // sections when the user is a store admin (tenant is null otherwise).
        return view('profile.edit', [
            'user' => $request->user(),
            'tenant' => $request->user()->tenant,
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit')->with('status', 'Your profile has been updated.');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        // ST-Lifecycle (S10): the last admin can't self-delete — it would orphan
        // the store (and keep billing running with no one able to manage it).
        if ($user->role === 'store_admin' && $user->tenant_id) {
            $adminCount = User::where('tenant_id', $user->tenant_id)
                ->where('role', 'store_admin')->count();

            if ($adminCount <= 1) {
                return back()->with('error',
                    'You are the last admin of your store. Promote another member to admin first, or contact support to close the store.');
            }
        }

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
