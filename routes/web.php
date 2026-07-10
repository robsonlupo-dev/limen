<?php

use App\Http\Controllers\Web\Auth\EmailVerificationController;
use App\Http\Controllers\Web\Auth\ForgotPasswordController;
use App\Http\Controllers\Web\Auth\LoginController;
use App\Http\Controllers\Web\Auth\RegisterController;
use App\Http\Controllers\Web\Auth\ResetPasswordController;
use App\Http\Controllers\Web\CatalogController;
use App\Http\Controllers\Web\EntradaController;
use App\Http\Controllers\Web\Consumer\TipController;
use App\Http\Controllers\Web\Consumer\WalletController;
use App\Http\Controllers\Web\FollowController;
use App\Http\Controllers\Web\LandingController;
use App\Http\Controllers\Web\UserPreferencesController;
use App\Http\Controllers\Web\WaitlistController;
use App\Http\Controllers\Web\Performer\DashboardController;
use App\Http\Controllers\Web\Performer\OnboardingController;
use App\Http\Controllers\Web\Performer\PayoutController;
use Illuminate\Support\Facades\Route;

Route::get('/', [LandingController::class, 'index'])->name('landing');
Route::get('/entrada', [EntradaController::class, 'index'])->name('entrada');

// Pre-launch waitlist capture from the public landing page (no auth).
Route::post('/interesse', [WaitlistController::class, 'store'])
    ->middleware('throttle:5,1')
    ->name('waitlist.store');

// Unsubscribe from the waitlist email. GET only shows a confirmation page (safe
// against link pre-fetch); the POST performs the delete (CSRF-protected). The
// token is opaque and carries the email — no PII in the URL/access log.
Route::get('/waitlist/cancelar', [WaitlistController::class, 'confirmUnsubscribe'])
    ->middleware('throttle:20,1')
    ->name('waitlist.unsubscribe');
Route::post('/waitlist/cancelar', [WaitlistController::class, 'unsubscribe'])
    ->middleware('throttle:10,1')
    ->name('waitlist.unsubscribe.confirm');

// Auth (guest only)
Route::middleware('guest')->group(function () {
    Route::get('/cadastro', [RegisterController::class, 'create'])->name('register');
    Route::post('/cadastro', [RegisterController::class, 'store'])->name('register.store');
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:5,1')->name('login.store');

    Route::get('/esqueci-minha-senha', [ForgotPasswordController::class, 'create'])->name('password.request');
    Route::post('/esqueci-minha-senha', [ForgotPasswordController::class, 'store'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/resetar-senha/{token}', [ResetPasswordController::class, 'create'])->name('password.reset');
    Route::post('/resetar-senha', [ResetPasswordController::class, 'store'])->middleware('throttle:5,1')->name('password.update');
});

// Logout
Route::post('/logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

// Authenticated area
Route::middleware('auth')->group(function () {
    Route::get('/email/verificar', [EmailVerificationController::class, 'notice'])->name('verification.notice');

    Route::get('/email/verificar/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::post('/email/verificar/reenviar', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    Route::get('/catalogo', [CatalogController::class, 'index'])->name('catalog');
    Route::get('/catalogo/{slug}', [CatalogController::class, 'show'])->name('catalog.show');

    Route::patch('/preferencias', [UserPreferencesController::class, 'update'])
        ->middleware('throttle:30,1')
        ->name('preferences.update');

    Route::middleware(['role:consumer', 'throttle:30,1'])->group(function () {
        Route::post('/catalogo/{slug}/seguir', [FollowController::class, 'store'])->name('catalog.follow');
        Route::delete('/catalogo/{slug}/seguir', [FollowController::class, 'destroy'])->name('catalog.unfollow');
    });

    // Performer onboarding — available to pending performers (before KYC/active).
    Route::middleware('role:performer')->group(function () {
        Route::get('/performer/onboarding', [OnboardingController::class, 'index'])->name('performer.onboarding');
        Route::post('/performer/onboarding/perfil', [OnboardingController::class, 'updateProfile'])->name('performer.onboarding.profile');
        Route::post('/performer/onboarding/foto', [OnboardingController::class, 'avatar'])
            ->middleware('throttle:20,1')
            ->name('performer.onboarding.avatar');
    });

    Route::get('/performer/dashboard', [DashboardController::class, 'index'])
        ->name('performer.dashboard')
        ->can('performer-active');

    Route::get('/performer/payouts', [PayoutController::class, 'index'])
        ->name('performer.payouts.index')
        ->can('performer-active');

    Route::get('/performer/payouts/history', [PayoutController::class, 'history'])
        ->name('performer.payouts.history')
        ->can('performer-active');

    Route::post('/performer/payouts', [PayoutController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('performer.payouts.store')
        ->can('performer-active');

    Route::middleware(['role:consumer'])->group(function () {
        Route::post('/gorjetas', [TipController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('tips.send');

        Route::get('/wallet', [WalletController::class, 'index'])->name('wallet.index');
        Route::get('/wallet/history', [WalletController::class, 'history'])->name('wallet.history');

        Route::post('/wallet/purchase/{package}', [WalletController::class, 'purchase'])
            ->middleware('throttle:10,1')
            ->name('wallet.purchase');

        Route::get('/wallet/pending', [WalletController::class, 'pending'])
            ->middleware('throttle:60,1')
            ->name('wallet.pending');
    });
});
