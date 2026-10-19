<?php

use App\Http\Controllers\Api\Saas\AdminSubscriptionController;
use App\Http\Controllers\Api\Saas\BillingController;
use App\Http\Controllers\Api\Saas\OnboardingController;
use App\Http\Controllers\Api\Saas\PasswordResetController;
use App\Http\Controllers\Api\Saas\SignupController;
use Illuminate\Support\Facades\Route;

// ── SaaS: herkese açık ──
Route::prefix('public')->middleware('throttle:20,1')->group(function () {
    Route::post('signup', [SignupController::class, 'register'])->middleware('throttle:5,10');
    Route::post('forgot-password', [PasswordResetController::class, 'forgot'])->middleware('throttle:5,10');
    Route::post('reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:5,10');
    Route::get('plans', [BillingController::class, 'publicPlans']);
});
Route::get('public/verify-email/{id}/{hash}', [SignupController::class, 'verify'])->middleware('signed')->name('saas.verify');
Route::post('billing/iyzico/callback', [BillingController::class, 'callback'])->name('saas.billing.callback');

// ── SaaS: oturumlu ──
Route::middleware(['auth:sanctum', 'tenant', 'throttle:120,1'])->group(function () {
    Route::post('auth/resend-verification', [SignupController::class, 'resendVerification'])->middleware('throttle:3,10');
    Route::get('billing/overview', [BillingController::class, 'overview']);
    Route::put('billing/profile', [BillingController::class, 'updateProfile']);
    Route::post('billing/checkout', [BillingController::class, 'checkout']);
    Route::post('billing/bank-transfer', [BillingController::class, 'bankTransferInvoice']);
    Route::post('billing/cancel', [BillingController::class, 'cancel']);
    Route::post('billing/resume', [BillingController::class, 'resume']);
    Route::get('onboarding/status', [OnboardingController::class, 'status']);
    Route::post('onboarding/dismiss', [OnboardingController::class, 'dismiss']);

    Route::middleware('role:admin')->prefix('admin/subscriptions')->group(function () {
        Route::get('/', [AdminSubscriptionController::class, 'index']);
        Route::post('{tenant}/activate', [AdminSubscriptionController::class, 'activate']);
        Route::post('{tenant}/extend-trial', [AdminSubscriptionController::class, 'extendTrial']);
        Route::post('{tenant}/lock', [AdminSubscriptionController::class, 'lock']);
    });
});
