<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * E1.11 — a money action is done once per submitted form, never twice.
 *
 * A double-click, a re-sent form, or Back-then-Submit on Renew / Record payment /
 * Mark paid / Reverse used to do the whole thing again: two ledger rows, two
 * renewals, money counted twice. Each operator form carries a one-time token
 * (`_once`, rendered by <x-operator-modal>); the first request with it wins via
 * an atomic cache add, and a replay is refused out loud.
 *
 * A FAILED attempt (validation error, an action the engine refused, a server
 * error) releases its token, so correcting the form and submitting again works.
 * Requests without a token (API calls, tests) behave exactly as before.
 *
 * E2's charges carry an `open_key` — the deeper, data-level guarantee. This
 * closes the operator's door now.
 */
class OncePerSubmission
{
    public const FIELD = '_once';

    /** How long a token stays spent. Long enough for any double-submit or Back. */
    private const TTL_MINUTES = 30;

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->input(self::FIELD);

        if (! is_string($token) || $token === '') {
            return $next($request);
        }

        $key = 'once:' . ($request->user()?->getAuthIdentifier() ?? 'guest') . ':' . hash('sha256', $token);

        if (! Cache::add($key, true, now()->addMinutes(self::TTL_MINUTES))) {
            return back()->with('error', 'That was already submitted — it has not been done a second time. Check the ledger before trying again.');
        }

        $response = $next($request);

        if ($this->failed($request, $response)) {
            Cache::forget($key);
        }

        return $response;
    }

    /** Did this attempt end without doing anything? Then the token is not spent. */
    private function failed(Request $request, Response $response): bool
    {
        if ($response->getStatusCode() >= 400) {
            return true;
        }

        // Flashed by THIS request (not left over from the page before it).
        $flashed = $request->hasSession() ? (array) $request->session()->get('_flash.new', []) : [];

        return in_array('error', $flashed, true) || in_array('errors', $flashed, true);
    }
}
