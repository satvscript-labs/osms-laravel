<?php

use App\Http\Controllers\InvitationController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RazorpayWebhookController;
use App\Http\Controllers\Superadmin\AccountController as SuperadminAccount;
use App\Http\Controllers\Superadmin\AuditLogController as SuperadminAuditLog;
use App\Http\Controllers\Superadmin\BillingController as SuperadminBilling;
use App\Http\Controllers\Superadmin\DashboardController as SuperadminDashboard;
use App\Http\Controllers\Superadmin\PlanController as SuperadminPlan;
use App\Http\Controllers\Superadmin\StoreController as SuperadminStore;
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
        // P2 — "Today": money, base health, and what needs me today.
        Route::get('/', [SuperadminDashboard::class, 'index'])->name('dashboard');

        /*
        | P2 / REQ-12 — the account-first surfaces.
        |
        | Routes say `accounts` (the code name); the UI says "Customers" (the
        | owner's word). `Customer` is already the patient model, so colliding
        | the two in code would be a permanent tax — decision A1.
        |
        | Customers and Stores are deliberately SEPARATE surfaces answering
        | different questions: "who pays me?" vs "which shops are live?".
        */
        Route::get('/customers', [SuperadminAccount::class, 'index'])->name('accounts.index');
        Route::get('/customers/{account}', [SuperadminAccount::class, 'show'])->name('accounts.show');

        // Cross-account operational sweep of every store.
        Route::get('/stores', [SuperadminStore::class, 'index'])->name('stores.index');
        Route::get('/stores/{tenant}', [SuperadminStore::class, 'show'])->name('stores.show');

        // The one ledger, across every account.
        Route::get('/billing', [SuperadminBilling::class, 'index'])->name('billing.index');

        // List prices (read-only in P2; editable in P3).
        Route::get('/plans', [SuperadminPlan::class, 'index'])->name('plans.index');

        // Audit trail (read-only)
        Route::get('/audit', [SuperadminAuditLog::class, 'index'])->name('audit.index');

        /*
        | LEGACY store screens — superseded by the surfaces above.
        |
        | Playbook §4 / decision E3: removed from the nav but left reachable by
        | URL, and deleted only once the new surfaces cover every job they did.
        | Never two competing entry points in the nav for the same job.
        */
        Route::get('/legacy/stores', [SuperadminTenant::class, 'index'])->name('tenants.index');
        Route::get('/legacy/stores/{tenant}', [SuperadminTenant::class, 'show'])->name('tenants.show');

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
