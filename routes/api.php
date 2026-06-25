<?php

use App\Http\Controllers\Api\V1\AsaasWebhookController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\MeController;
use App\Http\Controllers\Api\V1\Auth\PasswordController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\TokenPackageController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/auth')->group(function () {
    Route::post('register/consumer', [RegisterController::class, 'consumer'])->middleware('throttle:5,1')->name('auth.register.consumer');
    Route::post('register/performer', [RegisterController::class, 'performer'])->middleware('throttle:5,1')->name('auth.register.performer');
    Route::post('login', LoginController::class)->middleware('throttle:5,1')->name('auth.login');

    Route::post('password/forgot', [PasswordController::class, 'forgot'])->middleware('throttle:5,1')->name('auth.password.forgot');
    Route::post('password/reset', [PasswordController::class, 'reset'])->middleware('throttle:5,1')->name('auth.password.reset');

    Route::get('email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware('signed')
        ->name('verification.verify');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', LogoutController::class)->name('auth.logout');
        Route::get('me', MeController::class)->name('auth.me');
        Route::post('email/verify/resend', [EmailVerificationController::class, 'resend'])
            ->middleware('throttle:3,1')
            ->name('auth.verification.resend');
    });
});

Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    Route::get('token-packages', [TokenPackageController::class, 'index'])->name('token-packages.index');
    Route::post('payments', [PaymentController::class, 'store'])->middleware('throttle:10,1')->name('payments.store');
    Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
    Route::get('payments/{payment}', [PaymentController::class, 'show'])->name('payments.show');
});

Route::post('v1/webhooks/asaas', AsaasWebhookController::class)->name('webhooks.asaas');

Route::prefix('v1')->middleware(['auth:sanctum', 'role:performer'])->group(function () {
    Route::get('performer/dashboard', fn () => response()->json(['message' => 'Performer area.']))->name('performer.dashboard');
});
