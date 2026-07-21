<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * ST-Harden (S8) — baseline security response headers. HSTS is only sent over
 * HTTPS so it never breaks local http development.
 *
 * SEC-04 — also emits a Content-Security-Policy.
 *
 * Rollout note: the policy ships **Report-Only by default** (`CSP_ENFORCE=false`).
 * A slightly-wrong CSP takes pages down, and this app's checkout is revenue-
 * critical, so the safe order is: deploy → watch the browser console for
 * violations → then set `CSP_ENFORCE=true`. Report-Only sends the identical policy
 * and reports violations without blocking anything.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // Generate the per-request nonce BEFORE the view renders, so @vite tags and
        // inline <script nonce="{{ csp_nonce() }}"> blocks both pick it up.
        $nonce = config('security.csp_enabled') ? Vite::useCspNonce() : null;

        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');

        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        if ($nonce !== null) {
            $header = config('security.csp_enforce')
                ? 'Content-Security-Policy'
                : 'Content-Security-Policy-Report-Only';

            $response->headers->set($header, $this->policy($nonce));
        }

        return $response;
    }

    /**
     * Deliberately conservative rather than maximal. The directives carrying real
     * weight here (form-action, frame-ancestors, object-src, base-uri) cost nothing
     * in compatibility, while script-src must accommodate two hard constraints:
     *
     *   'unsafe-eval'  Alpine.js evaluates its expressions with new Function().
     *                  Removing this needs Alpine's CSP build plus a rewrite of
     *                  every x-data expression in the app.
     *   Razorpay       checkout.js loads from their CDN and opens an iframe;
     *                  blocking it would break billing outright.
     *
     * style-src keeps 'unsafe-inline' because the views use inline style="..."
     * attributes throughout, and nonces do not apply to style attributes.
     */
    private function policy(string $nonce): string
    {
        $razorpay = 'https://checkout.razorpay.com https://api.razorpay.com';

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}' 'unsafe-eval' {$razorpay}",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob: https://*.razorpay.com",
            "font-src 'self' data:",
            "connect-src 'self' https://*.razorpay.com",
            "frame-src 'self' {$razorpay}",
            "frame-ancestors 'none'",
            "form-action 'self'",
            "base-uri 'self'",
            "object-src 'none'",
        ]);
    }
}
