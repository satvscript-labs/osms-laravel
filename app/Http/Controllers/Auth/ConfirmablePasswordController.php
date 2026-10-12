<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequirePasswordConfirmation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ConfirmablePasswordController extends Controller
{
    /**
     * Show the confirm password view.
     */
    public function show(): View
    {
        return view('auth.confirm-password');
    }

    /**
     * Confirm the user's password.
     */
    public function store(Request $request): RedirectResponse|JsonResponse
    {
        if (! Auth::guard('web')->validate([
            'email' => $request->user()->email,
            'password' => $request->password,
        ])) {
            throw ValidationException::withMessages([
                'password' => __('auth.password'),
            ]);
        }

        $request->session()->put('auth.password_confirmed_at', time());
        $refusedAt = (int) $request->session()->pull(RequirePasswordConfirmation::UNSAVED, 0);

        // AUDIT-01 — the in-page prompt (resources/js/password-confirm.js) confirms
        // for a change it is ABOUT to send. A left-over "your last change was NOT
        // saved" here would land on the page after that change succeeds, and an
        // operator who believed it would enter the money twice. JSON callers get a
        // plain yes; any stale refusal and stale return address are cleared.
        if ($request->expectsJson()) {
            $request->session()->forget('url.intended');

            return response()->json(['confirmed' => true]);
        }

        $redirect = redirect()->intended(route('dashboard', absolute: false));

        // QA-02 — the change that triggered this was refused, not queued. Say so
        // on the page they return to, in the toast that stays until dismissed.
        // Only a RECENT refusal: one abandoned hours ago is no longer "your last change".
        if ($refusedAt > 0 && time() - $refusedAt <= RequirePasswordConfirmation::UNSAVED_TTL) {
            $redirect->with('error', 'Password confirmed. Your last change was NOT saved — please do it again.');
        }

        return $redirect;
    }
}
