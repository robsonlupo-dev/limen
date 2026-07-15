<?php

use App\Http\Controllers\Web\Auth\EmailVerificationController;
use App\Http\Controllers\Web\Auth\ForgotPasswordController;
use App\Http\Controllers\Web\Auth\LoginController;
use App\Http\Controllers\Web\Auth\RegisterController;
use App\Http\Controllers\Web\Auth\ResetPasswordController;
use App\Http\Controllers\Web\Admin\WaitlistAdminController;
use App\Http\Controllers\Web\CatalogController;
use App\Http\Controllers\Web\PublicCatalogController;
use App\Http\Controllers\Web\ConviteController;
use App\Http\Controllers\Web\EntradaController;
use App\Http\Controllers\Web\FounderPanelController;
use App\Http\Controllers\Web\Consumer\DashboardController as ConsumerDashboardController;
use App\Http\Controllers\Web\Consumer\InterestController as ConsumerInterestController;
use App\Http\Controllers\Web\Consumer\TipController;
use App\Http\Controllers\Web\Consumer\WalletController;
use App\Http\Controllers\Web\Performer\InterestController as PerformerInterestController;
use App\Http\Controllers\Web\FollowController;
use App\Http\Controllers\Web\LandingController;
use App\Http\Controllers\Web\LinksController;
use App\Http\Controllers\Web\UserPreferencesController;
use App\Http\Controllers\Web\WaitlistController;
use App\Http\Controllers\Web\Performer\DashboardController;
use App\Http\Controllers\Web\Performer\FollowersController;
use App\Http\Controllers\Web\Performer\OnboardingController;
use App\Http\Controllers\Web\Performer\PayoutController;
use Illuminate\Support\Facades\Route;

Route::get('/', [LandingController::class, 'index'])->name('landing');
Route::get('/entrada', [EntradaController::class, 'index'])->name('entrada');

// Public link-in-bio hub (Linktree replacement, no auth). Allowlisted on the
// public domain (thelimen.com.br) — see deploy/nginx/thelimen.com.br.
Route::get('/links', [LinksController::class, 'index'])->name('links');

// Pre-launch waitlist capture from the public landing page (no auth).
Route::post('/interesse', [WaitlistController::class, 'store'])
    ->middleware('throttle:5,1')
    ->name('waitlist.store');

// Invite link: renders the landing with a referral banner and stashes the
// referrer in the session so the signup is attributed to them.
Route::get('/convite/{invite_code}', [ConviteController::class, 'show'])
    ->middleware('throttle:60,1')
    ->name('convite.show');

// Public founder panel (shareable viral surface, no auth).
Route::get('/f/{invite_code}', [FounderPanelController::class, 'show'])
    ->middleware('throttle:60,1')
    ->name('waitlist.founder');

// Double opt-in email confirmation (idempotent; from the confirmation email).
Route::get('/waitlist/confirmar', [WaitlistController::class, 'confirm'])
    ->middleware('throttle:20,1')
    ->name('waitlist.confirm');

// Unsubscribe from the waitlist email. GET only shows a confirmation page (safe
// against link pre-fetch); the POST performs the delete (CSRF-protected). The
// token is opaque and carries the email — no PII in the URL/access log.
Route::get('/waitlist/cancelar', [WaitlistController::class, 'confirmUnsubscribe'])
    ->middleware('throttle:20,1')
    ->name('waitlist.unsubscribe');
Route::post('/waitlist/cancelar', [WaitlistController::class, 'unsubscribe'])
    ->middleware('throttle:10,1')
    ->name('waitlist.unsubscribe.confirm');

// Public performer catalog (no auth — SEO/marketing surface). Separate from the
// authenticated /catalogo experience; interaction actions route to signup.
Route::get('/performers', [PublicCatalogController::class, 'index'])
    ->middleware('throttle:60,1')
    ->name('performers.public');
Route::get('/performers/{slug}', [PublicCatalogController::class, 'show'])
    ->middleware('throttle:60,1')
    ->where('slug', '[a-z0-9\-]+')
    ->name('performers.public.show');

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

// Admin back-office (auth + admin role).
Route::middleware(['auth', 'role:admin'])->prefix('admin')->group(function () {
    Route::get('/waitlist', [WaitlistAdminController::class, 'index'])->name('admin.waitlist');
});

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

    // Origem do envio de Interesse Controlado: a performer escolhe entre quem
    // já a segue. Ver Web\Performer\FollowersController.
    Route::get('/performer/seguidores', [FollowersController::class, 'index'])
        ->middleware('throttle:60,1')
        ->name('performer.followers')
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

    // Interesse Controlado — a performer ativa sinaliza interesse em um membro.
    Route::post('/performer/interesses', [PerformerInterestController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('performer.interests.send')
        ->can('performer-active');

    Route::middleware(['role:consumer'])->group(function () {
        // Home da área logada do membro.
        Route::get('/painel', [ConsumerDashboardController::class, 'index'])
            ->middleware('throttle:60,1')
            ->name('consumer.dashboard');

        Route::post('/gorjetas', [TipController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('tips.send');

        // Interesse Controlado — caixa do membro, desbloqueio e opt-out.
        Route::get('/interesses', [ConsumerInterestController::class, 'index'])
            ->middleware('throttle:60,1')
            ->name('interests.index');

        Route::post('/interesses/{interest}/desbloquear', [ConsumerInterestController::class, 'unlock'])
            ->middleware('throttle:10,1')
            ->name('interests.unlock');

        Route::patch('/interesses/opt-out', [ConsumerInterestController::class, 'optOut'])
            ->middleware('throttle:30,1')
            ->name('interests.opt-out');

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
