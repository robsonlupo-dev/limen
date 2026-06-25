<?php

use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\MeController;
use App\Http\Controllers\Api\V1\Auth\PasswordController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
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

Route::prefix('v1')->middleware(['auth:sanctum', 'role:performer'])->group(function () {
    Route::get('performer/dashboard', fn () => response()->json(['message' => 'Performer area.']))->name('performer.dashboard');
});
