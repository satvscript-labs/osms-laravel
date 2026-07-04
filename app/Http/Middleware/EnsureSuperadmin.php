<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ST-Admin (S11) — hard gate for the platform panel.
 *
 * Single-purpose and deliberately strict: the request must be authenticated AND
 * the user's role must be exactly `superadmin`. Unlike the generic `role:` list
 * middleware, this exists only to protect the superadmin surface, so there is no
 * chance of a route accidentally widening the allowed roles.
 */
class EnsureSuperadmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isSuperadmin()) {
            abort(403, 'This area is restricted to platform administrators.');
        }

        return $next($request);
    }
}
