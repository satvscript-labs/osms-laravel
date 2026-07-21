<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;

if (! function_exists('csp_nonce')) {
    /**
     * SEC-04 — the per-request CSP nonce (set by the SecurityHeaders middleware).
     * Every inline <script> must carry it, or the browser will refuse to run it
     * once the policy is enforced. Returns '' when no nonce is active (e.g. CSP
     * disabled), which is harmless.
     */
    function csp_nonce(): string
    {
        return Vite::cspNonce() ?? '';
    }
}

if (! function_exists('safe_route')) {
    /**
     * Resolve a named route, or return a fallback if it isn't registered yet.
     * Lets shared UI (sidebar, dashboard) reference module routes that may be
     * added in a later build phase without throwing.
     */
    function safe_route(string $name, mixed $parameters = [], string $fallback = '#'): string
    {
        return Route::has($name) ? route($name, $parameters) : $fallback;
    }
}
