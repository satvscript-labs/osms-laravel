<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppConfig;
use App\Services\WhatsApp\WhatsAppException;
use App\Services\WhatsApp\WhatsAppGateway;
use App\Support\Phone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * FT-WhatsApp — save a store's WhatsApp messaging preferences (Settings hub).
 *
 * Phase 3 covers the manual flow: mode + per-event toggles + custom wording +
 * country code. The automated (Cloud API) credential fields are accepted and
 * stored so the settings screen is complete, but the send engine that consumes
 * them lands in a later phase; until then an "automated" store degrades to the
 * manual pill (see WhatsAppConfig::modeFor / MessagingMode::ManualFallback).
 */
class WhatsAppSettingsController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $tenant = $request->user()->tenant;

        // Automated is frozen as "Coming soon" in production — only Off/Manual are
        // selectable there (the segment is disabled in the UI too). Dev/tests keep
        // the full set so the Cloud API path can still be exercised.
        $modes = config('whatsapp.automated_enabled') ? 'off,manual,automated' : 'off,manual';

        // Use a dedicated error bag so the Settings hub can jump to this panel.
        $validated = $request->validateWithBag('whatsappSettings', [
            'mode' => ['required', 'in:' . $modes],
            'on_placed' => ['nullable', 'boolean'],
            'on_ready' => ['nullable', 'boolean'],
            'on_delivered' => ['nullable', 'boolean'],
            'on_birthday' => ['nullable', 'boolean'],
            'msg_placed' => ['nullable', 'string', 'max:1000'],
            'msg_ready' => ['nullable', 'string', 'max:1000'],
            'msg_delivered' => ['nullable', 'string', 'max:1000'],
            'msg_birthday' => ['nullable', 'string', 'max:1000'],
            'default_country_code' => ['required', 'string', 'regex:/^\+\d{1,4}$/'],
            // Automated (Cloud API) — optional; inert until the send engine ships.
            'phone_number_id' => ['nullable', 'string', 'max:100'],
            'access_token' => ['nullable', 'string', 'max:1000'],
            'display_phone' => ['nullable', 'string', 'max:30'],
        ], [
            'default_country_code.regex' => 'Enter a dialling code like +91.',
        ]);

        $config = WhatsAppConfig::firstOrNew(['tenant_id' => $tenant->id]);

        $config->fill([
            'mode' => $validated['mode'],
            'on_placed' => $request->boolean('on_placed'),
            'on_ready' => $request->boolean('on_ready'),
            'on_delivered' => $request->boolean('on_delivered'),
            'on_birthday' => $request->boolean('on_birthday'),
            'msg_placed' => $validated['msg_placed'] ?? null,
            'msg_ready' => $validated['msg_ready'] ?? null,
            'msg_delivered' => $validated['msg_delivered'] ?? null,
            'msg_birthday' => $validated['msg_birthday'] ?? null,
            'default_country_code' => $validated['default_country_code'],
            'phone_number_id' => $validated['phone_number_id'] ?? null,
            'display_phone' => $validated['display_phone'] ?? null,
        ]);

        // Only overwrite the (encrypted) token when a new value is supplied, so
        // re-saving the form without re-entering it doesn't wipe it.
        if (filled($validated['access_token'] ?? null)) {
            $config->access_token = $validated['access_token'];
        }

        $config->save();

        return redirect()->route('profile.edit')
            ->with('status', 'WhatsApp settings updated.');
    }

    /**
     * Send a test message through the configured driver. On success the store is
     * marked connected (`enabled` + `verified_at`), which flips Automated mode on.
     * With the local `log` driver this always succeeds, so the automated flow can
     * be exercised end-to-end without a Meta account.
     */
    public function test(Request $request, WhatsAppGateway $gateway): RedirectResponse
    {
        $data = $request->validateWithBag('whatsappSettings', [
            'test_to' => ['required', 'string', 'max:30'],
        ]);

        $config = $request->user()->tenant->whatsappConfig;
        if (! $config) {
            return back()->with('error', 'Save your WhatsApp settings first, then send a test.');
        }

        $to = Phone::e164($data['test_to'], $config->default_country_code);
        if ($to === null) {
            return back()->with('error', 'Enter a valid phone number to send the test to.');
        }

        try {
            $gateway->sendTemplate(
                $config->setRelation('tenant', $request->user()->tenant),
                $to,
                $config->templateName('order_ready'),
                $config->tpl_lang ?? 'en',
                ['there', $request->user()->tenant->store_name, 'TEST1234', '₹ 0.00'],
            );

            $config->update(['enabled' => true, 'verified_at' => now(), 'needs_attention' => false]);

            return back()->with('status', 'Test message sent — automated messaging is now active.');
        } catch (WhatsAppException $e) {
            return back()->with('error', 'Test failed: ' . $e->getMessage());
        }
    }
}
