<?php

namespace App\Support;

/**
 * Single source of truth for phone-number normalisation.
 *
 * Numbers are stored as "{code} {national}", e.g. "+91 9876543210" (see the
 * StoreCustomerRequest / OrderController regex). This helper is used by every
 * WhatsApp/call affordance (wa.me links, tel: links, and — later — the Cloud
 * API "to" field) so normalisation is never re-implemented per-caller.
 */
class Phone
{
    /**
     * E.164 form with a leading "+", e.g. "+919876543210". For `tel:` links and
     * the Cloud API display. Returns null when the input can't yield a valid
     * 8–15 digit number.
     */
    public static function e164(?string $raw, ?string $defaultCode = null): ?string
    {
        $digits = static::waDigits($raw, $defaultCode);

        return $digits === null ? null : '+' . $digits;
    }

    /**
     * Bare international digits, no "+", e.g. "919876543210". This is what
     * wa.me/{number} and the Cloud API "to" field expect.
     */
    public static function waDigits(?string $raw, ?string $defaultCode = null): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }

        $hasPlus = str_starts_with($raw, '+');
        $digits = preg_replace('/\D/', '', $raw);

        // A bare national number (no leading "+") gets the default dialling code.
        if (! $hasPlus) {
            $code = preg_replace('/\D/', '', $defaultCode ?? (string) config('whatsapp.default_country_code', '+91'));
            $digits = $code . $digits;
        }

        // E.164 allows 8–15 digits total; reject anything outside that.
        return (strlen($digits) >= 8 && strlen($digits) <= 15) ? $digits : null;
    }

    /** Whether the raw value normalises to a usable number. */
    public static function isValid(?string $raw, ?string $defaultCode = null): bool
    {
        return static::waDigits($raw, $defaultCode) !== null;
    }
}
