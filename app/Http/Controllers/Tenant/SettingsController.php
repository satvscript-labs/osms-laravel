<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SettingsController extends Controller
{
    /** Store settings now live in the unified Settings hub (profile.edit). */
    public function edit(Request $request): RedirectResponse
    {
        return redirect()->route('profile.edit');
    }

    public function update(Request $request): RedirectResponse
    {
        $tenant = $request->user()->tenant;

        // Normalise a GSTIN to its canonical shape (uppercase, no spaces) before validating.
        if ($request->filled('tax_id')) {
            $request->merge(['tax_id' => strtoupper(preg_replace('/\s+/', '', (string) $request->tax_id))]);
        }

        $validated = $request->validate([
            'store_name' => ['required', 'string', 'max:255'],
            // GST/Tax ID — a GSTIN is 15 uppercase alphanumerics; keep it lenient
            // (any 1–15 alphanumerics) so a non-GST store id still fits.
            'tax_id' => ['nullable', 'string', 'max:15', 'regex:/^[0-9A-Z]+$/'],
            'address' => ['nullable', 'string', 'max:500'],
            'logo' => ['nullable', 'image', 'max:2048'], // 2MB
            'remove_logo' => ['nullable', 'boolean'],
            // FT-TaxInvoice — GST registration + rate (drives the tax-invoice breakup).
            'gst_enabled' => ['nullable', 'boolean'],
            'gst_rate' => ['nullable', 'numeric', 'min:0', 'max:28'],
        ], [
            'tax_id.regex' => 'Enter a valid GST/Tax ID (letters and numbers only).',
        ]);

        $logoUrl = $tenant->logo_url;

        // Replace logo when a new file is supplied (mirrors OnboardingController's guard).
        if ($request->hasFile('logo')) {
            try {
                $path = $request->file('logo')->store('logos', 'public');
                $logoUrl = Storage::url($path);
            } catch (\Throwable $e) {
                return back()->withInput()
                    ->with('error', 'We could not upload your logo. Please try again.');
            }
        } elseif ($request->boolean('remove_logo')) {
            $logoUrl = null;
        }

        $tenant->update([
            'store_name' => $validated['store_name'],
            'tax_id' => $validated['tax_id'] ?? null,
            'address' => $validated['address'] ?? null,
            'logo_url' => $logoUrl,
            'gst_enabled' => $request->boolean('gst_enabled'),
            'gst_rate' => $validated['gst_rate'] ?? null,
        ]);

        return redirect()->route('profile.edit')
            ->with('status', 'Store settings updated.');
    }
}
