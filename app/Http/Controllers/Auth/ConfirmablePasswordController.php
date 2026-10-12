<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequirePasswordConfirmation;
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
    public function store(Request $request): RedirectResponse
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

        $redirect = redirect()->intended(route('dashboard', absolute: false));

        // QA-02 — the change that triggered this was refused, not queued. Say so
        // on the page they return to, in the toast that stays until dismissed.
        if ($request->session()->pull(RequirePasswordConfirmation::UNSAVED, false)) {
            $redirect->with('error', 'Password confirmed. Your last change was NOT saved — please do it again.');
        }

        return $redirect;
    }
}
