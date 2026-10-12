<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\Request;

/**
 * QA-02 — a lapsed password confirmation must never swallow a change silently.
 *
 * Laravel's `password.confirm` remembers where to return to only for GET pages.
 * For a form submission it sends the operator to "Confirm password" and then
 * back to the PREVIOUS page with the submission gone: no ledger row, no message.
 * An operator who had just taken cash at the counter would believe it recorded.
 *
 * Replaying the POST after confirmation is not safe (double submission, a stale
 * CSRF token, a decision taken on numbers that have since moved), so instead:
 *
 *   • JSON callers (the live preview) get 423 with `password_confirm_required`,
 *     so the page can ask for the password in place rather than guessing;
 *   • a form submission is returned to the page it came from, and both the
 *     confirm screen and that page say plainly that the change was NOT saved.
 *
 * The browser-side guard (resources/js/password-confirm.js) asks for the password
 * BEFORE a panel form is sent, so in normal use this path is the safety net.
 */
class RequirePasswordConfirmation extends RequirePassword
{
    /** Session key: WHEN a write was refused for confirmation (unix time). */
    public const UNSAVED = 'password_confirm.unsaved_write';

    /** How long that refusal still describes "your last change" (seconds). */
    public const UNSAVED_TTL = 900;

    /**
     * Laravel binds ITS RequirePassword with `auth.password_timeout`; a subclass is
     * resolved without that binding and would fall back to a hard-coded 3 hours —
     * silently disagreeing with the browser guard, which reads the config.
     */
    public function __construct(ResponseFactory $responseFactory, UrlGenerator $urlGenerator, $passwordTimeout = null)
    {
        parent::__construct($responseFactory, $urlGenerator, $passwordTimeout ?? config('auth.password_timeout'));
    }

    public function handle($request, Closure $next, $redirectToRoute = null, $passwordTimeoutSeconds = null)
    {
        if (! $this->shouldConfirmPassword($request, $passwordTimeoutSeconds)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return $this->responseFactory->json([
                'message' => 'Password confirmation required.',
                'password_confirm_required' => true,
            ], 423);
        }

        if ($this->isWrite($request)) {
            $request->session()->put('url.intended', $this->returnTo($request));
            $request->session()->put(self::UNSAVED, time());

            return $this->responseFactory->redirectTo(
                $this->urlGenerator->route($redirectToRoute ?: 'password.confirm')
            );
        }

        return $this->responseFactory->redirectGuest(
            $this->urlGenerator->route($redirectToRoute ?: 'password.confirm')
        );
    }

    private function isWrite(Request $request): bool
    {
        return ! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true);
    }

    /** The page the form was on — never the write URL itself, which has no GET. */
    private function returnTo(Request $request): string
    {
        $previous = $this->urlGenerator->previous();

        return $previous !== $request->fullUrl() ? $previous : $this->urlGenerator->to('/');
    }
}
