<?php

/*
|--------------------------------------------------------------------------
| Tenant module routes
|--------------------------------------------------------------------------
| Included inside the `tenant` prefix + `tenant.` name group (auth + onboarded).
| Each feature module appends its routes here as it is built.
*/

use App\Http\Controllers\Tenant\ActivityController;
use App\Http\Controllers\Tenant\AnalyticsController;
use App\Http\Controllers\Tenant\BillingController;
use App\Http\Controllers\Tenant\EyeRecordController;
use App\Http\Controllers\Tenant\InventoryController;
use App\Http\Controllers\Tenant\CustomerController;
use App\Http\Controllers\Tenant\OrderController;
use App\Http\Controllers\Tenant\SearchController;
use App\Http\Controllers\Tenant\SettingsController;
use App\Http\Controllers\Tenant\StaffController;
use App\Http\Controllers\Tenant\WhatsAppSettingsController;
use Illuminate\Support\Facades\Route;

// ---- Global search (Cmd+K) ----
Route::middleware('throttle:120,1')->get('search', SearchController::class)->name('search');

// ---- Customers ----
Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
Route::get('customers/create', [CustomerController::class, 'create'])->name('customers.create');
Route::get('customers/trash', [CustomerController::class, 'trash'])->name('customers.trash'); // FG-Delete archive
Route::post('customers', [CustomerController::class, 'store'])->name('customers.store');
Route::get('customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
Route::get('customers/{customer}/edit', [CustomerController::class, 'edit'])->name('customers.edit');
Route::put('customers/{customer}', [CustomerController::class, 'update'])->name('customers.update');
Route::delete('customers/{customer}', [CustomerController::class, 'destroy'])->name('customers.destroy');
Route::patch('customers/{customer}/restore', [CustomerController::class, 'restore'])->name('customers.restore')->withTrashed();
Route::delete('customers/{customer}/force', [CustomerController::class, 'forceDelete'])->name('customers.force-delete')->withTrashed();

// ---- Eye records (nested under a customer) ----
Route::get('customers/{customer}/records/create', [EyeRecordController::class, 'create'])->name('eye-records.create');
Route::post('customers/{customer}/records', [EyeRecordController::class, 'store'])->name('eye-records.store');
Route::get('records/{record}/edit', [EyeRecordController::class, 'edit'])->name('eye-records.edit');
Route::put('records/{record}', [EyeRecordController::class, 'update'])->name('eye-records.update');
Route::delete('records/{record}', [EyeRecordController::class, 'destroy'])->name('eye-records.destroy');

// ---- Inventory ----
Route::get('inventory', [InventoryController::class, 'index'])->name('inventory.index');
Route::middleware('throttle:120,1')->get('inventory/scan', [InventoryController::class, 'scan'])->name('inventory.scan');
Route::get('inventory/create', [InventoryController::class, 'create'])->name('inventory.create');
Route::get('inventory/trash', [InventoryController::class, 'trash'])->name('inventory.trash'); // FG-Delete archive
Route::post('inventory', [InventoryController::class, 'store'])->name('inventory.store');
Route::get('inventory/{inventory}/edit', [InventoryController::class, 'edit'])->name('inventory.edit');
Route::put('inventory/{inventory}', [InventoryController::class, 'update'])->name('inventory.update');
Route::post('inventory/{inventory}/adjust', [InventoryController::class, 'adjustStock'])->name('inventory.adjust');
Route::delete('inventory/{inventory}', [InventoryController::class, 'destroy'])->name('inventory.destroy');
Route::patch('inventory/{inventory}/restore', [InventoryController::class, 'restore'])->name('inventory.restore')->withTrashed();
Route::delete('inventory/{inventory}/force', [InventoryController::class, 'forceDelete'])->name('inventory.force-delete')->withTrashed();

// ---- Orders ----
Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
Route::get('orders/create', [OrderController::class, 'create'])->name('orders.create');
Route::post('orders', [OrderController::class, 'store'])->name('orders.store');
Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
Route::get('orders/{order}/edit', [OrderController::class, 'edit'])->name('orders.edit'); // FG-OrderEdit
Route::put('orders/{order}', [OrderController::class, 'update'])->name('orders.update');   // FG-OrderEdit
Route::patch('orders/{order}/status', [OrderController::class, 'updateStatus'])->name('orders.status');
Route::post('orders/{order}/revert', [OrderController::class, 'revert'])->name('orders.revert'); // FT-WhatsApp undo
Route::post('orders/{order}/settle', [OrderController::class, 'settle'])->name('orders.settle'); // 6.1 / 6.3
Route::post('orders/{order}/cancel', [OrderController::class, 'cancel'])->name('orders.cancel');
Route::post('orders/{order}/payments', [OrderController::class, 'recordPayment'])->name('orders.payments.store');
Route::get('orders/{order}/pdf', [OrderController::class, 'pdf'])->name('orders.pdf');
Route::get('orders/{order}/tax-invoice/pdf', [OrderController::class, 'taxInvoicePdf'])->name('orders.tax-invoice.pdf'); // FT-TaxInvoice
Route::middleware('throttle:120,1')->get('customers/{customer}/eye-records', [OrderController::class, 'eyeRecords'])->name('customers.eye-records');

// ---- Analytics (store admins + superadmin only) ----
Route::middleware('role:store_admin,superadmin')->group(function () {
    Route::get('analytics', [AnalyticsController::class, 'index'])->name('analytics.index');

    // PRIV-04 — store activity log (read-only).
    Route::get('activity', [ActivityController::class, 'index'])->name('activity.index');
});

// ---- Billing / subscriptions (store admins + superadmin only) ----
Route::middleware('role:store_admin,superadmin')->group(function () {
    Route::get('billing', [BillingController::class, 'index'])->name('billing.index');
    Route::post('billing/subscribe', [BillingController::class, 'subscribe'])->name('billing.subscribe');
    Route::post('billing/cancel', [BillingController::class, 'cancel'])->name('billing.cancel');
    Route::post('billing/change-interval', [BillingController::class, 'changeInterval'])->name('billing.change-interval');
    Route::get('billing/success', [BillingController::class, 'success'])->name('billing.success');
    Route::get('billing/invoices/{invoice}/pdf', [BillingController::class, 'invoicePdf'])->name('billing.invoices.pdf');
});

// ---- Store settings (store admins + superadmin only) ----
Route::middleware('role:store_admin,superadmin')->group(function () {
    Route::get('settings', [SettingsController::class, 'edit'])->name('settings.edit');
    Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');

    // FT-WhatsApp — messaging preferences (mode, wording, credentials).
    Route::put('settings/whatsapp', [WhatsAppSettingsController::class, 'update'])->name('whatsapp.update');
    Route::post('settings/whatsapp/test', [WhatsAppSettingsController::class, 'test'])->name('whatsapp.test');
});

// ---- Team / staff management (store admins + superadmin only) ----
Route::middleware('role:store_admin,superadmin')->group(function () {
    Route::get('staff', [StaffController::class, 'index'])->name('staff.index');
    Route::post('staff/invite', [StaffController::class, 'invite'])->name('staff.invite');
    Route::post('staff/invitations/{invitation}/resend', [StaffController::class, 'resend'])->name('staff.invitations.resend');
    Route::delete('staff/invitations/{invitation}', [StaffController::class, 'revoke'])->name('staff.invitations.revoke');
    Route::patch('staff/{member}/role', [StaffController::class, 'updateRole'])->name('staff.role');
    Route::delete('staff/{member}', [StaffController::class, 'remove'])->name('staff.remove');
});
