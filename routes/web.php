<?php

use App\Http\Controllers\InvitationController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RazorpayWebhookController;
use App\Http\Controllers\Superadmin\AuditLogController as SuperadminAuditLog;
use App\Http\Controllers\Superadmin\DashboardController as SuperadminDashboard;
use App\Http\Controllers\Superadmin\SubscriptionController as SuperadminSubscription;
use App\Http\Controllers\Superadmin\TenantController as SuperadminTenant;
use App\Http\Controllers\Tenant\DashboardController as TenantDashboard;
use App\Http\Controllers\TwoFactorController;
use App\Support\Navigation;
use Illuminate\Support\Facades\Route;

// Public marketing / landing page
Route::get('/', fn () => view('welcome'))->name('home');

// Public legal & compliance pages (ST-Legal / S6)
Route::view('/legal/terms', 'legal.terms')->name('legal.terms');
Route::view('/legal/privacy', 'legal.privacy')->name('legal.privacy');
Route::view('/legal/refund', 'legal.refund')->name('legal.refund');
Route::view('/legal/contact', 'legal.contact')->name('legal.contact');

// Public staff-invitation accept flow (ST-Staff / S3) — invitee has no session yet.
Route::get('/invitations/{token}', [InvitationController::class, 'show'])->name('invitations.show');
Route::post('/invitations/{token}', [InvitationController::class, 'accept'])->name('invitations.accept');

// Generic "dashboard" entry — routes the user to the right home by role/state.
Route::get('/dashboard', fn () => redirect(Navigation::homeFor(request()->user())))
    ->middleware('auth')->name('dashboard');

/*
|--------------------------------------------------------------------------
| Onboarding (auth, but no tenant required yet)
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->group(function () {
    Route::get('/onboarding', [OnboardingController::class, 'create'])->name('onboarding.create');
    Route::post('/onboarding', [OnboardingController::class, 'store'])->name('onboarding.store');

    // Account profile (Breeze)
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // SEC-05 — two-factor authentication.
    // The challenge routes must stay reachable while `2fa_pending` is set (see
    // EnforceTwoFactor::EXEMPT), which is why they live here and not behind it.
    Route::get('/two-factor/challenge', [TwoFactorController::class, 'challenge'])->name('two-factor.challenge');
    Route::post('/two-factor/challenge', [TwoFactorController::class, 'verify'])
        ->middleware('throttle:6,1')   // brute-forcing a 6-digit code must be slow
        ->name('two-factor.verify');
    Route::post('/two-factor/cancel', [TwoFactorController::class, 'cancel'])->name('two-factor.cancel');

    Route::get('/two-factor/setup', [TwoFactorController::class, 'setup'])->name('two-factor.setup');
    Route::post('/two-factor/confirm', [TwoFactorController::class, 'confirm'])
        ->middleware('throttle:6,1')
        ->name('two-factor.confirm');
    // Turning 2FA OFF is a security downgrade — require a recent password.
    Route::delete('/two-factor', [TwoFactorController::class, 'disable'])
        ->middleware('password.confirm')
        ->name('two-factor.disable');
});

/*
|--------------------------------------------------------------------------
| Tenant workspace (auth + onboarded)
|--------------------------------------------------------------------------
*/
// 'verified.optional' (ST-Email) enforces email verification only when
// SAAS_REQUIRE_EMAIL_VERIFICATION=true (enable in production once SMTP is live).
// 'subscribed' hard-locks the workspace when the subscription is inactive
// (ST-Enforce); billing routes self-exempt so a locked store can still pay.
Route::middleware(['auth', 'verified.optional', 'onboarded', 'subscribed'])
    ->prefix('tenant')
    ->name('tenant.')
    ->group(function () {
        Route::get('/', [TenantDashboard::class, 'index'])->name('dashboard');

        // Module routes are registered per-phase below.
        require __DIR__ . '/tenant.php';
    });

/*
|--------------------------------------------------------------------------
| Superadmin platform panel (auth + role)
|--------------------------------------------------------------------------
*/
// Hardened: dedicated `superadmin` guard + throttle. Money-affecting mutations
// additionally require a recent password confirmation (`password.confirm`).
Route::middleware(['auth', 'superadmin', 'throttle:120,1'])
    ->prefix('superadmin')
    ->name('superadmin.')
    ->group(function () {
        Route::get('/', [SuperadminDashboard::class, 'index'])->name('dashboard');

        // Store directory + read-only detail
        Route::get('/stores', [SuperadminTenant::class, 'index'])->name('tenants.index');
        Route::get('/stores/{tenant}', [SuperadminTenant::class, 'show'])->name('tenants.show');

        // Audit trail (read-only)
        Route::get('/audit', [SuperadminAuditLog::class, 'index'])->name('audit.index');

        // Mutations — require recent re-authentication.
        Route::middleware('password.confirm')->group(function () {
            Route::patch('/stores/{tenant}/notes', [SuperadminTenant::class, 'updateNotes'])->name('tenants.notes');
            Route::patch('/stores/{tenant}/subscription', [SuperadminSubscription::class, 'update'])->name('subscription.update');
            Route::post('/stores/{tenant}/subscription/extend-trial', [SuperadminSubscription::class, 'extendTrial'])->name('subscription.extend-trial');
            Route::post('/stores/{tenant}/subscription/activate', [SuperadminSubscription::class, 'activate'])->name('subscription.activate');
            Route::post('/stores/{tenant}/subscription/cancel', [SuperadminSubscription::class, 'cancel'])->name('subscription.cancel');
        });
    });

// Razorpay webhook (no auth, CSRF-exempt — see bootstrap/app.php)
Route::post('/webhooks/razorpay', [RazorpayWebhookController::class, 'handle'])->name('webhooks.razorpay');

require __DIR__ . '/auth.php';
