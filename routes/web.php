<?php

use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\TransactionDebuggerController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\ChangePasswordController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\ConfirmPasswordController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InstallController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PaymentAccountController;
use App\Http\Controllers\SandboxCheckoutController;
use App\Http\Controllers\SettingsController;
use App\Http\Middleware\RedirectIfInstalled;
use Illuminate\Support\Facades\Route;

// ── Installer ─────────────────────────────────────────────────────────────────
Route::middleware(RedirectIfInstalled::class)->prefix('install')->name('install.')->group(function () {
    Route::get('/', [InstallController::class, 'index'])->name('index');
    Route::get('/database', [InstallController::class, 'database'])->name('database');
    Route::post('/database', [InstallController::class, 'databaseSave'])->name('database.save');
    Route::get('/migrate', [InstallController::class, 'migrate'])->name('migrate');
    Route::post('/migrate', [InstallController::class, 'migrateRun'])->name('migrate.run');
    Route::get('/admin', [InstallController::class, 'admin'])->name('admin');
    Route::post('/admin', [InstallController::class, 'adminSave'])->name('admin.save');
    Route::get('/done', [InstallController::class, 'done'])->name('done');
});

Route::view('/', 'welcome')->name('home');
Route::view('/docs', 'docs')->name('docs');

// ── Public checkout pages ─────────────────────────────────────────────────────
Route::get('/pay/{order}', [CheckoutController::class, 'show'])->name('pay.show');
Route::get('/pay/{order}/status', [CheckoutController::class, 'status'])->middleware('throttle:40,1')->name('pay.status');
Route::post('/pay/{order}/start', [CheckoutController::class, 'start'])->middleware('throttle:20,1')->name('pay.start');
Route::match(['get', 'post'], '/pay/{order}/return', [CheckoutController::class, 'return'])->middleware('throttle:30,1')->name('pay.return');
Route::post('/pay/{order}/utr', [CheckoutController::class, 'submitUtr'])->middleware('throttle:10,1')->name('pay.utr');
Route::get('/pay/{order}/qr.svg', [CheckoutController::class, 'qr'])->name('pay.qr');

Route::get('/sandbox/pay/{order}', [SandboxCheckoutController::class, 'show'])
    ->middleware('throttle:60,1')->name('sandbox.pay.show');
Route::post('/sandbox/pay/{order}/simulate', [SandboxCheckoutController::class, 'simulate'])
    ->middleware('throttle:20,1')->name('sandbox.pay.simulate');

// ── Guest auth ────────────────────────────────────────────────────────────────
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
});

// ── Password confirmation (requires auth, no active check — even disabled accounts
//    should be able to confirm before being bounced by EnsureActive) ────────────
Route::middleware('auth')->group(function () {
    Route::get('/confirm-password', [ConfirmPasswordController::class, 'show'])->name('password.confirm');
    Route::post('/confirm-password', [ConfirmPasswordController::class, 'confirm']);
});

// ── Authenticated routes ──────────────────────────────────────────────────────
Route::middleware(['auth', 'active'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/accounts', [PaymentAccountController::class, 'index'])->name('accounts.index');
    Route::get('/accounts/create', [PaymentAccountController::class, 'create'])->name('accounts.create');
    Route::post('/accounts', [PaymentAccountController::class, 'store'])->name('accounts.store');
    // Scope account bindings to the authenticated user so Merchant B cannot
    // reach Merchant A's accounts even if they guess the numeric ID.
    Route::post('/accounts/{account}/limit', [PaymentAccountController::class, 'updateLimit'])->name('accounts.limit')->can('update', 'account');
    Route::post('/accounts/{account}/toggle', [PaymentAccountController::class, 'toggle'])->name('accounts.toggle')->can('update', 'account');
    Route::post('/accounts/{account}/default', [PaymentAccountController::class, 'makeDefault'])->name('accounts.default')->can('update', 'account');
    Route::delete('/accounts/{account}', [PaymentAccountController::class, 'destroy'])->name('accounts.destroy')->can('delete', 'account');

    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('/orders/create', [OrderController::class, 'create'])->name('orders.create');
    Route::post('/orders', [OrderController::class, 'store'])->name('orders.store');
    Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::post('/orders/{order}/recheck', [OrderController::class, 'recheck'])->name('orders.recheck');
    Route::post('/orders/{order}/approve-utr', [OrderController::class, 'approveUtr'])->name('orders.approve-utr');
    Route::post('/orders/{order}/reject-utr', [OrderController::class, 'rejectUtr'])->name('orders.reject-utr');
    Route::post('/orders/{order}/mark-paid', [OrderController::class, 'markPaid'])->name('orders.mark-paid');

    Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
    Route::post('/settings/profile', [SettingsController::class, 'updateProfile'])->name('settings.profile');
    Route::post('/settings/webhook', [SettingsController::class, 'updateWebhook'])->name('settings.webhook');
    Route::post('/settings/sandbox/webhook', [SettingsController::class, 'updateSandboxWebhook'])->name('settings.sandbox.webhook');
    Route::post('/settings/sandbox/regenerate-keys', [SettingsController::class, 'regenerateSandboxKeys'])->name('settings.sandbox.regenerate');
    Route::post('/settings/sandbox/revoke-keys', [SettingsController::class, 'revokeSandboxKeys'])->name('settings.sandbox.revoke');
    Route::post('/settings/rotation', [SettingsController::class, 'updateRotation'])->name('settings.rotation');
    Route::post('/settings/redirect-domains', [SettingsController::class, 'updateRedirectDomains'])->name('settings.redirect-domains');
    Route::post('/settings/regenerate-keys', [SettingsController::class, 'regenerateKeys'])->name('settings.regenerate');

    // Password change — uses Auth::logoutOtherDevices to sign out other sessions.
    Route::get('/settings/password', [ChangePasswordController::class, 'show'])->name('password.change.show');
    Route::put('/settings/password', [ChangePasswordController::class, 'update'])->name('password.change');

    // Admin routes — require password re-confirmation within the last 15 minutes.
    Route::prefix('admin')->name('admin.')->middleware(['admin', 'password.confirm:,900'])->group(function () {
        Route::get('/merchants', [AdminController::class, 'merchants'])->name('merchants');
        Route::post('/merchants/{user}/toggle', [AdminController::class, 'toggleMerchant'])->name('merchants.toggle');
        Route::get('/orders', [AdminController::class, 'orders'])->name('orders');
        Route::get('/orders/{order}', [AdminController::class, 'showOrder'])->name('order.show');
        Route::get('/transaction-debugger', [TransactionDebuggerController::class, 'index'])->name('transactions.index');
        Route::get('/transaction-debugger/{order}', [TransactionDebuggerController::class, 'show'])->name('transactions.show');
    });
});

// Fee-free billing: no automatic debit or payment-provider calls.
Route::get('/invoice/{token}', [BillingController::class, 'publicInvoice'])->where('token', '[A-Za-z0-9]{48}')->name('billing.public');
Route::post('/invoice/{token}/pay', [BillingController::class, 'pay'])->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:15,1')->name('billing.pay');
Route::prefix('billing')->name('billing.')->middleware(['auth', 'active'])->controller(BillingController::class)->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('/customers', 'customers')->name('customers');
    Route::post('/customers', 'storeCustomer')->name('customers.store');
    Route::get('/customers/{customer}/edit', 'editCustomer')->name('customers.edit');
    Route::put('/customers/{customer}', 'updateCustomer')->name('customers.update');
    Route::post('/customers/{customer}/toggle', 'toggleCustomer')->name('customers.toggle');
    Route::get('/plans', 'plans')->name('plans');
    Route::post('/plans', 'storePlan')->name('plans.store');
    Route::post('/plans/{plan}/toggle', 'togglePlan')->name('plans.toggle');
    Route::get('/subscriptions', 'subscriptions')->name('subscriptions');
    Route::post('/subscriptions', 'storeSubscription')->name('subscriptions.store');
    Route::post('/renewals', 'generate')->middleware('throttle:5,1')->name('generate');
    Route::get('/subscriptions/{subscription}', 'subscription')->name('subscription');
    Route::post('/subscriptions/{subscription}', 'changeSubscription')->name('subscription.change');
    Route::get('/invoices', 'invoiceList')->name('invoices');
    Route::post('/invoices', 'storeInvoice')->name('invoices.store');
    Route::get('/invoices/{invoice}', 'invoice')->name('invoice');
    Route::post('/invoices/{invoice}/void', 'voidInvoice')->name('invoice.void');
    Route::post('/invoices/{invoice}/refund', 'refund')->name('invoice.refund');
    Route::post('/invoices/{invoice}/reminder', 'markReminder')->name('invoice.reminder');
    Route::get('/reminders', 'reminders')->name('reminders');
    Route::get('/export', 'export')->name('export');
});
