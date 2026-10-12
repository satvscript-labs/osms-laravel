<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\StoreProvisioner;
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

    public function store(Request $request, StoreProvisioner $provisioner): RedirectResponse
    {
        $user = $request->user();

        if ($user->hasTenant()) {
            return redirect()->route('tenant.dashboard');
        }

        // Normalise a GSTIN (uppercase, no spaces) before validating.
        if ($request->filled('tax_id')) {
            $request->merge(['tax_id' => strtoupper(preg_replace('/\s+/', '', (string) $request->tax_id))]);
        }

        $validated = $request->validate([
            'store_name' => ['required', 'string', 'max:255'],
            // GST/Tax ID — lenient GSTIN shape (1–15 alphanumerics).
            'tax_id' => ['nullable', 'string', 'max:15', 'regex:/^[0-9A-Z]+$/'],
            'address' => ['nullable', 'string', 'max:500'],
            'logo' => ['nullable', 'image', 'max:2048'], // 2MB
        ], [
            'tax_id.regex' => 'Enter a valid GST/Tax ID (letters and numbers only).',
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

        // Atomic: create account + tenant, link user, start trial. Lock + re-check
        // the user row inside the transaction so two concurrent submissions can't
        // both create a tenant for the same user (BUG-008).
        //
        // P1 / REQ-2 — provisioning itself lives in StoreProvisioner, the ONE
        // service every door shares (self-signup here, the operator door in P3,
        // seeders, imports). This controller keeps only the self-signup-specific
        // guard; the provisioner joins this ambient transaction.
        DB::transaction(function () use ($user, $validated, $logoUrl, $provisioner) {
            $locked = User::whereKey($user->id)->lockForUpdate()->first();
            if (! $locked || $locked->hasTenant()) {
                return; // another request already onboarded this user
            }

            $provisioner->provision($locked, [
                'store_name' => $validated['store_name'],
                'tax_id' => $validated['tax_id'] ?? null,
                'address' => $validated['address'] ?? null,
                'logo_url' => $logoUrl,
            ]);
        });

        return redirect()->route('tenant.dashboard')
            ->with('status', 'Your store is ready. Welcome to OSMS!');
    }
}
