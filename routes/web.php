<?php

use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RazorpayWebhookController;
use App\Http\Controllers\Superadmin\DashboardController as SuperadminDashboard;
use App\Http\Controllers\Tenant\DashboardController as TenantDashboard;
use App\Support\Navigation;
use Illuminate\Support\Facades\Route;

// Public marketing / landing page
Route::get('/', fn () => view('welcome'))->name('home');

// Public legal & compliance pages (ST-Legal / S6)
Route::view('/legal/terms', 'legal.terms')->name('legal.terms');
Route::view('/legal/privacy', 'legal.privacy')->name('legal.privacy');
Route::view('/legal/refund', 'legal.refund')->name('legal.refund');
Route::view('/legal/contact', 'legal.contact')->name('legal.contact');

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
Route::middleware(['auth', 'role:superadmin'])
    ->prefix('superadmin')
    ->name('superadmin.')
    ->group(function () {
        Route::get('/', [SuperadminDashboard::class, 'index'])->name('dashboard');
    });

// Razorpay webhook (no auth, CSRF-exempt — see bootstrap/app.php)
Route::post('/webhooks/razorpay', [RazorpayWebhookController::class, 'handle'])->name('webhooks.razorpay');

require __DIR__ . '/auth.php';
