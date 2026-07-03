<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class OnboardingController extends Controller
{
    public function create(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if ($user->isSuperadmin()) {
            return redirect()->route('superadmin.dashboard');
        }

        if ($user->hasTenant()) {
            return redirect()->route('tenant.dashboard');
        }

        return view('onboarding.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->hasTenant()) {
            return redirect()->route('tenant.dashboard');
        }

        $validated = $request->validate([
            'store_name' => ['required', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:500'],
            'logo' => ['nullable', 'image', 'max:2048'], // 2MB
        ]);

        // Logo upload → public disk (replaces Supabase Storage `logos` bucket)
        $logoUrl = null;
        if ($request->hasFile('logo')) {
            try {
                $path = $request->file('logo')->store('logos', 'public');
                $logoUrl = Storage::url($path);
            } catch (\Throwable $e) {
                return back()->withInput()
                    ->with('error', 'We could not upload your logo. Please try again.');
            }
        }

        // Atomic: create tenant, link user, start trial (replaces the SECURITY DEFINER RPC).
        // Lock + re-check the user row inside the transaction so two concurrent
        // submissions can't both create a tenant for the same user (BUG-008).
        DB::transaction(function () use ($user, $validated, $logoUrl) {
            $locked = User::whereKey($user->id)->lockForUpdate()->first();
            if (! $locked || $locked->hasTenant()) {
                return; // another request already onboarded this user
            }

            $tenant = Tenant::create([
                'store_name' => $validated['store_name'],
                'tax_id' => $validated['tax_id'] ?? null,
                'address' => $validated['address'] ?? null,
                'logo_url' => $logoUrl,
            ]);

            $locked->forceFill(['tenant_id' => $tenant->id])->save();

            // The trial subscription is created by the Tenant model's `created`
            // hook (ST-Enforce) — no explicit creation needed here.
        });

        return redirect()->route('tenant.dashboard')
            ->with('status', 'Your store is ready. Welcome to OSMS!');
    }
}
