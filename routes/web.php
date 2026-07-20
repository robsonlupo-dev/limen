<?php

use App\Http\Controllers\Web\Auth\EmailVerificationController;
use App\Http\Controllers\Web\Auth\ForgotPasswordController;
use App\Http\Controllers\Web\Auth\LoginController;
use App\Http\Controllers\Web\Auth\RegisterController;
use App\Http\Controllers\Web\Auth\ResetPasswordController;
use App\Http\Controllers\Web\Admin\ReportAdminController;
use App\Http\Controllers\Web\Admin\WaitlistAdminController;
use App\Http\Controllers\Web\CatalogController;
use App\Http\Controllers\Web\ChatController;
use App\Http\Controllers\Web\PublicCatalogController;
use App\Http\Controllers\Web\ConviteController;
use App\Http\Controllers\Web\EntradaController;
use App\Http\Controllers\Web\FounderPanelController;
use App\Http\Controllers\Web\Consumer\DashboardController as ConsumerDashboardController;
use App\Http\Controllers\Web\Consumer\InterestController as ConsumerInterestController;
use App\Http\Controllers\Web\Consumer\PreferencesController as ConsumerPreferencesController;
use App\Http\Controllers\Web\Consumer\ReportController;
use App\Http\Controllers\Web\Consumer\SubscriptionController;
use App\Http\Controllers\Web\Consumer\TipController;
use App\Http\Controllers\Web\Consumer\WalletController;
use App\Http\Controllers\Web\Performer\InterestController as PerformerInterestController;
use App\Http\Controllers\Web\FollowController;
use App\Http\Controllers\Web\LandingController;
use App\Http\Controllers\Web\LegalDocumentsController;
use App\Http\Controllers\Web\LinksController;
use App\Http\Controllers\Web\UserPreferencesController;
use App\Http\Controllers\Web\WaitlistController;
use App\Http\Controllers\Web\Performer\DashboardController;
use App\Http\Controllers\Web\Performer\DocumentAcceptanceController;
use App\Http\Controllers\Web\Performer\FollowersController;
use App\Http\Controllers\Web\Performer\OnboardingController;
use App\Http\Controllers\Web\Performer\ProfileController as PerformerProfileController;
use App\Http\Controllers\Web\Performer\PayoutController;
use App\Http\Controllers\Web\Performer\SentInterestsController;
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

// Textos jurídicos aceitos pela performer no cadastro. Públicos: o contrato
// precisa ser legível ANTES de existir conta e continuar acessível depois.
Route::get('/politica-de-conteudo', [LegalDocumentsController::class, 'contentPolicy'])
    ->middleware('throttle:60,1')
    ->name('legal.content-policy');
Route::get('/contrato-de-performance', [LegalDocumentsController::class, 'performanceContract'])
    ->middleware('throttle:60,1')
    ->name('legal.performance-contract');

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
    // Throttle não é só anti-força-bruta aqui: o Piso de Anonimato conta
    // seguidores para decidir se a performer vê a lista, e registro em lote é o
    // caminho barato para plantar contas e destravá-lo. O corte de idade
    // encarece a pressa; o throttle encarece o volume. Atende os dois papéis —
    // a rota é uma só, o papel vem no payload.
    Route::post('/cadastro', [RegisterController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('register.store');
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

    // Fila de moderação das denúncias (conteúdo ilegal, coerção, etc).
    Route::get('/reports', [ReportAdminController::class, 'index'])->name('admin.reports');
    Route::patch('/reports/{report}', [ReportAdminController::class, 'update'])
        ->whereNumber('report')
        ->name('admin.reports.update');
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

    // Denúncia de conteúdo/conduta. Fora de role:consumer de propósito: uma
    // performer também precisa poder denunciar (impersonation, coerção), e
    // fechar o canal por papel é fechar a porta de compliance para metade da
    // base.
    //
    // Dois tetos: o por minuto barra o script, e o DIÁRIO barra o flood que a
    // janela de 24h sozinha não pega — ela é por (alvo, motivo), então com 6
    // motivos e alvos variados uma conta só geraria milhares de e-mails ao
    // admin e enterraria uma denúncia real de conteúdo com menor no ruído.
    // 30/dia é folgado para quem denuncia de boa-fé e caro para quem inunda.
    Route::post('/reportar', [ReportController::class, 'store'])
        ->middleware(['throttle:20,1', 'throttle:30,1440'])
        ->name('report.store');

    // Chat pós-desbloqueio de Interesse. Membro e performer compartilham as
    // telas; a ConversationPolicy garante que só participantes entrem. Não há
    // rota de abertura pelo membro — o canal nasce no desbloqueio.
    // As telas de chat são compartilhadas, então o `documents.accepted` entra
    // rota a rota e não no grupo: ele ignora quem não é performer, logo o
    // membro passa igual. Sem isso a performer sem aceite continuaria
    // conversando por um canal já aberto — a superfície que a Política de
    // Conteúdo Proibido justamente governa.
    Route::get('/chat', [ChatController::class, 'index'])
        ->middleware(['throttle:60,1', 'documents.accepted'])
        ->name('chat.index');

    Route::get('/chat/{conversation}', [ChatController::class, 'show'])
        ->middleware(['throttle:60,1', 'documents.accepted'])
        ->whereNumber('conversation')
        ->name('chat.show');

    Route::post('/chat/{conversation}/mensagens', [ChatController::class, 'storeMessage'])
        ->middleware(['throttle:30,1', 'documents.accepted'])
        ->whereNumber('conversation')
        ->name('chat.messages.store');

    // Compra/renova o acesso ao chat desta conversa (membro sem assinatura).
    Route::post('/chat/{conversation}/acesso', [ChatController::class, 'openAccess'])
        ->middleware('throttle:10,1')
        ->whereNumber('conversation')
        ->name('chat.access.open');

    // A performer manda a 1ª mensagem a partir de uma linha de Interesse. Resposta
    // uniforme por design (máscara de opt-out) — ver ChatController::performerStart.
    Route::post('/chat/interesse/{interest}/mensagem', [ChatController::class, 'performerStart'])
        ->middleware('throttle:10,1')
        ->whereNumber('interest')
        ->middleware('documents.accepted')
        ->can('performer-active')
        ->name('chat.performer.start');

    Route::middleware(['role:consumer', 'throttle:30,1'])->group(function () {
        Route::post('/catalogo/{slug}/seguir', [FollowController::class, 'store'])->name('catalog.follow');
        Route::delete('/catalogo/{slug}/seguir', [FollowController::class, 'destroy'])->name('catalog.unfollow');
    });

    // Aceite dos documentos (Política de Conteúdo Proibido + Contrato de
    // Performance). FORA do grupo `documents.accepted` de propósito: é o destino
    // do redirect daquele middleware, e gatear a própria tela de saída daria
    // loop. Vale para performer pendente também — o aceite não espera o KYC.
    Route::middleware(['role:performer', 'throttle:60,1'])->group(function () {
        Route::get('/performer/aceitar-documentos', [DocumentAcceptanceController::class, 'index'])
            ->name('performer.documents');
        Route::post('/performer/aceitar-documentos', [DocumentAcceptanceController::class, 'store'])
            ->name('performer.documents.accept');
    });

    // Tudo que a performer faz na plataforma passa pelo aceite vigente. O
    // middleware ignora quem não é performer, então membro/admin que caia numa
    // destas rotas continua barrado pelo role/gate de sempre, não por aqui.
    Route::middleware('documents.accepted')->group(function () {
        // Performer onboarding — available to pending performers (before KYC/active).
        Route::middleware('role:performer')->group(function () {
            Route::get('/performer/onboarding', [OnboardingController::class, 'index'])->name('performer.onboarding');
            Route::post('/performer/onboarding/perfil', [OnboardingController::class, 'updateProfile'])->name('performer.onboarding.profile');
            Route::post('/performer/onboarding/foto', [OnboardingController::class, 'avatar'])
                ->middleware('throttle:20,1')
                ->name('performer.onboarding.avatar');
        });

        // Edição de perfil da performer já ativa. O onboarding continua sendo o
        // caminho de quem ainda não entrou.
        Route::get('/performer/perfil', [PerformerProfileController::class, 'edit'])
            ->middleware('throttle:60,1')
            ->name('performer.profile.edit')
            ->can('performer-active');

        // Nomes distintos dos da API de propósito: 'performer.profile.update' e
        // 'performer.profile.avatar' já existem em routes/api.php. Nome repetido não
        // dá erro — o último registrado vence no lookup, e route() no front passaria
        // a apontar para a API (405, verbo diferente). Ver RouteNameCollisionTest.
        Route::post('/performer/perfil', [PerformerProfileController::class, 'update'])
            ->middleware('throttle:30,1')
            ->name('performer.profile.save')
            ->can('performer-active');

        Route::post('/performer/perfil/foto', [PerformerProfileController::class, 'avatar'])
            ->middleware('throttle:20,1')
            ->name('performer.profile.photo')
            ->can('performer-active');

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

        // Histórico dos envios desta performer (para quem, quem revelou, cota do dia).
        Route::get('/performer/interesses', [SentInterestsController::class, 'index'])
            ->middleware('throttle:60,1')
            ->name('performer.interests.index')
            ->can('performer-active');
    });

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

        // Configurações do membro (hoje: Modo Discreto).
        Route::get('/configuracoes', [ConsumerPreferencesController::class, 'index'])
            ->middleware('throttle:60,1')
            ->name('consumer.settings');

        Route::patch('/configuracoes/modo-discreto', [ConsumerPreferencesController::class, 'toggleDiscreteMode'])
            ->middleware('throttle:20,1')
            ->name('consumer.settings.discrete-mode');

        // Assinaturas (Círculos) — escolha de tier + cartão + cancelamento.
        Route::get('/assinar', [SubscriptionController::class, 'index'])->name('subscribe.index');
        // throttle apertado: barra carding/BIN — poucas tentativas de cartão/min.
        Route::post('/assinar', [SubscriptionController::class, 'store'])
            ->middleware('throttle:3,1')
            ->name('subscribe.store');
        Route::post('/assinar/cancelar', [SubscriptionController::class, 'cancel'])
            ->middleware('throttle:6,1')
            ->name('subscribe.cancel');

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
