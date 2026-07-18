<?php

use App\Http\Controllers\Api\AsaasTransferWebhookController;
use App\Http\Controllers\Api\V1\AdminKycController;
use App\Http\Controllers\Api\V1\AsaasWebhookController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\MeController;
use App\Http\Controllers\Api\V1\Auth\PasswordController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\FollowController;
use App\Http\Controllers\Api\V1\TipController;
use App\Http\Controllers\Api\V1\KycController;
use App\Http\Controllers\Api\V1\KycWebhookController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PerformerCatalogController;
use App\Http\Controllers\Api\V1\PerformerMediaController;
use App\Http\Controllers\Api\V1\PerformerProfileController;
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
        ->name('api.verification.verify');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', LogoutController::class)->name('auth.logout');
        Route::get('me', MeController::class)->name('auth.me');
        Route::post('email/verify/resend', [EmailVerificationController::class, 'resend'])
            ->middleware('throttle:3,1')
            ->name('auth.verification.resend');
    });
});

// Public catalog (no auth required)
Route::prefix('v1')->middleware('throttle:30,1')->group(function () {
    Route::get('performers', [PerformerCatalogController::class, 'index'])->name('performers.index');
    Route::get('performers/{slug}', [PerformerCatalogController::class, 'show'])->name('performers.show');
});

// Private media serving (signed URL, no session auth)
Route::get('v1/performer-media', PerformerMediaController::class)
    ->middleware('signed')
    ->name('performer.media');

Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    Route::get('token-packages', [TokenPackageController::class, 'index'])->name('token-packages.index');
    Route::post('payments', [PaymentController::class, 'store'])->middleware('throttle:10,1')->name('payments.store');
    Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
    Route::get('payments/{payment}', [PaymentController::class, 'show'])->name('payments.show');
});

Route::post('v1/webhooks/asaas', AsaasWebhookController::class)
    ->middleware('asaas.webhook_ip')
    ->name('webhooks.asaas');
// Generous throttle caps the log/audit-flood vector from unauthenticated hits
// while staying well above any real Didit webhook burst (retries survive a 429).
Route::post('v1/webhooks/kyc', KycWebhookController::class)
    ->middleware('throttle:120,1')
    ->name('webhooks.kyc');
// Generous throttle: caps the log/audit-flood vector from unauthenticated hits
// while staying well above any real Asaas webhook burst (retries survive a 429).
Route::post('/webhooks/asaas/transfer', [AsaasTransferWebhookController::class, 'handle'])
    ->middleware(['asaas.webhook_ip', 'throttle:120,1'])
    ->name('webhooks.asaas.transfer');

// Performer profile management + KYC
Route::prefix('v1')->middleware(['auth:sanctum', 'role:performer'])->group(function () {
    Route::get('performer/dashboard', fn () => response()->json(['message' => 'Performer area.']));
    Route::get('performer/profile', [PerformerProfileController::class, 'show'])->name('performer.profile.show');
    Route::put('performer/profile', [PerformerProfileController::class, 'update'])->name('performer.profile.update');
    Route::post('performer/profile/avatar', [PerformerProfileController::class, 'avatar'])->name('performer.profile.avatar');
    Route::post('performer/profile/cover', [PerformerProfileController::class, 'cover'])->name('performer.profile.cover');

    Route::post('performer/kyc/submit', [KycController::class, 'submit'])->name('performer.kyc.submit');
    Route::get('performer/kyc/status', [KycController::class, 'status'])->name('performer.kyc.status');

    // Tips received by performer
    Route::get('performer/tips', [TipController::class, 'performerHistory'])->name('tips.performer-history');
});

// Admin KYC management
Route::prefix('v1')->middleware(['auth:sanctum', 'role:admin'])->group(function () {
    Route::get('admin/kyc', [AdminKycController::class, 'index'])->name('admin.kyc.index');
    Route::post('admin/kyc/{verification}/approve', [AdminKycController::class, 'approve'])->name('admin.kyc.approve');
    Route::post('admin/kyc/{verification}/reject', [AdminKycController::class, 'reject'])->name('admin.kyc.reject');
});

// Follow system (consumer only)
Route::prefix('v1')->middleware(['auth:sanctum', 'role:consumer'])->group(function () {
    Route::post('performers/{slug}/follow', [FollowController::class, 'follow'])->name('performers.follow');
    Route::delete('performers/{slug}/follow', [FollowController::class, 'unfollow'])->name('performers.unfollow');
    Route::get('performers/{slug}/following', [FollowController::class, 'following'])->name('performers.following');

    // Tips (consumer sends tips)
    Route::post('tips', [TipController::class, 'store'])->middleware('throttle:10,1')->name('tips.store');
    Route::get('tips', [TipController::class, 'consumerHistory'])->name('tips.consumer-history');
});

// Public tips summary
Route::get('v1/performers/{slug}/tips/summary', [TipController::class, 'performerSummary'])
    ->middleware('throttle:30,1')
    ->name('tips.performer-summary');
