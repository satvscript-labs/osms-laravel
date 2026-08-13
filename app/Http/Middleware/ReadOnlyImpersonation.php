<?php

namespace App\Http\Middleware;

use App\Services\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * P5 — the thing that makes "read-only" true rather than merely intended.
 *
 * Runs on every web request, because the guarantee has to hold on routes that
 * do not know impersonation exists. Three jobs:
 *
 *   1. Expire the session on its own clock, wherever the operator happens to be.
 *   2. Refuse any request that could write. The test is the HTTP VERB, not a
 *      list of routes — a list rots the moment somebody adds a route, and the
 *      failure mode of a stale list is a silent write into a real customer's
 *      data while wearing their identity.
 *   3. Share the banner state, so no layout can render an impersonated session
 *      that looks like an ordinary one.
 *
 * Registered globally on `web`, deliberately not as an alias: a guarantee you
 * have to remember to apply is not a guarantee.
 */
class ReadOnlyImpersonation
{
    /** Read-shaped verbs. Everything else is a write until proven otherwise. */
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /** The only write allowed: the exit. Never widen this list casually. */
    private const ALLOWED_ROUTES = ['impersonation.stop'];

    public function __construct(private readonly Impersonation $impersonation) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->impersonation->expired($request)) {
            $this->impersonation->stop($request, 'expired');

            return redirect()->route('superadmin.dashboard')
                ->with('status', 'The view-as session timed out and you are back in your own account.');
        }

        $state = $this->impersonation->active($request);

        if (! $state) {
            return $next($request);
        }

        /*
         * AUD-A04 — logging out mid-session used to destroy the operator's own
         * login and write NO exit audit row, because `logout` regenerates the
         * session and the impersonation state went with it. The panel claims
         * impersonation is "audited on entry AND exit"; an entry with no exit
         * is the single worst shape for that trail, since it reads as a session
         * that never ended.
         *
         * The store's own Log out button now means "leave the store", which is
         * also the only thing an operator could sensibly intend by pressing it.
         */
        if ($request->routeIs('logout')) {
            $this->impersonation->stop($request, 'logout');

            return redirect()->route('superadmin.dashboard')
                ->with('status', 'You left the view-as session and are back in your own account.');
        }

        View::share('impersonation', $state);

        if (! in_array($request->method(), self::SAFE_METHODS, true)
            && ! $request->routeIs(...self::ALLOWED_ROUTES)) {
            // 403 rather than a redirect: the caller asked for something that is
            // not permitted, and a redirect would look like the write succeeded.
            abort(403, 'You are viewing this store read-only. Leave the view-as session to make changes.');
        }

        return $next($request);
    }
}
