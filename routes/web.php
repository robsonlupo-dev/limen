<?php

use App\Http\Controllers\Web\Account\DeletionController as AccountDeletionController;
use App\Http\Controllers\Web\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Web\Admin\KycAdminController;
use App\Http\Controllers\Web\Admin\PerformerTierController;
use App\Http\Controllers\Web\Admin\ReportAdminController;
use App\Http\Controllers\Web\Admin\UserBanController;
use App\Http\Controllers\Web\Admin\WaitlistAdminController;
use App\Http\Controllers\Web\Auth\EmailVerificationController;
use App\Http\Controllers\Web\Auth\ForgotPasswordController;
use App\Http\Controllers\Web\Auth\LoginController;
use App\Http\Controllers\Web\Auth\OtpLoginController;
use App\Http\Controllers\Web\Auth\RegisterController;
use App\Http\Controllers\Web\Auth\ResetPasswordController;
use App\Http\Controllers\Web\CallController;
use App\Http\Controllers\Web\CatalogController;
use App\Http\Controllers\Web\GroupShowController;
use App\Http\Controllers\Web\ChatController;
use App\Http\Controllers\Web\Consumer\ConsumerKycController;
use App\Http\Controllers\Web\Consumer\DashboardController as ConsumerDashboardController;
use App\Http\Controllers\Web\Consumer\FavoriteController;
use App\Http\Controllers\Web\Consumer\FeedController;
use App\Http\Controllers\Web\Consumer\GiftController;
use App\Http\Controllers\Web\Consumer\InterestController as ConsumerInterestController;
use App\Http\Controllers\Web\Consumer\LiveViewController;
use App\Http\Controllers\Web\Consumer\MemberPhotoController;
use App\Http\Controllers\Web\Consumer\PhotoAccessController;
use App\Http\Controllers\Web\Consumer\PreferencesController as ConsumerPreferencesController;
use App\Http\Controllers\Web\Consumer\ProfileController as ConsumerProfileController;
use App\Http\Controllers\Web\Consumer\ReportController;
use App\Http\Controllers\Web\Consumer\SavedSearchController;
use App\Http\Controllers\Web\Consumer\StoryController as ConsumerStoryController;
use App\Http\Controllers\Web\Consumer\SubscriptionController;
use App\Http\Controllers\Web\Consumer\TipController;
use App\Http\Controllers\Web\Consumer\WalletController;
use App\Http\Controllers\Web\Content\ContentController;
use App\Http\Controllers\Web\ConviteController;
use App\Http\Controllers\Web\EntradaController;
use App\Http\Controllers\Web\FollowController;
use App\Http\Controllers\Web\FounderPanelController;
use App\Http\Controllers\Web\LandingController;
use App\Http\Controllers\Web\LegalDocumentsController;
use App\Http\Controllers\Web\LinksController;
use App\Http\Controllers\Web\Moderation\EvidenceController;
use App\Http\Controllers\Web\Moderation\ModerationController;
use App\Http\Controllers\Web\Performer\AvailabilityController;
use App\Http\Controllers\Web\Performer\BoostController;
use App\Http\Controllers\Web\Performer\CallSettingsController;
use App\Http\Controllers\Web\Performer\DashboardController;
use App\Http\Controllers\Web\Performer\GroupShowController as PerformerGroupShowController;
use App\Http\Controllers\Web\Performer\DocumentAcceptanceController;
use App\Http\Controllers\Web\Performer\FollowersController;
use App\Http\Controllers\Web\Performer\InterestController as PerformerInterestController;
use App\Http\Controllers\Web\Performer\MemberCatalogController;
use App\Http\Controllers\Web\Performer\MemberNotesController;
use App\Http\Controllers\Web\Performer\OnboardingController;
use App\Http\Controllers\Web\Performer\PayoutController;
use App\Http\Controllers\Web\Performer\PerformerContentController;
use App\Http\Controllers\Web\Performer\PerformerLiveController;
use App\Http\Controllers\Web\Performer\PerformerLocationController;
use App\Http\Controllers\Web\Performer\PhotoController as PerformerPhotoController;
use App\Http\Controllers\Web\Performer\ProfileController as PerformerProfileController;
use App\Http\Controllers\Web\Performer\ReceivedPhotoController;
use App\Http\Controllers\Web\Performer\SentInterestsController;
use App\Http\Controllers\Web\Performer\StoryController as PerformerStoryController;
use App\Http\Controllers\Web\Performer\TwoFactorController;
use App\Http\Controllers\Web\PublicCatalogController;
use App\Http\Controllers\Web\NotificationPreferencesController;
use App\Http\Controllers\Web\UserPreferencesController;
use App\Http\Controllers\Web\WaitlistController;
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

// Confirmação, pelo e-mail, do pedido de exclusão de conta (LGPD art. 18, VI).
// Sem auth de propósito: o link chega na caixa e o titular pode abri-lo em
// outro navegador — o token É a credencial. GET só mostra a página (imune ao
// prefetch de caixa de e-mail), POST consome o token de uso único.
Route::get('/conta/confirmar-exclusao/{token}', [AccountDeletionController::class, 'confirm'])
    ->middleware('throttle:20,1')
    ->where('token', '[A-Za-z0-9]+')
    ->name('account.deletion.confirm');
Route::post('/conta/confirmar-exclusao', [AccountDeletionController::class, 'confirmStore'])
    ->middleware('throttle:10,1')
    ->name('account.deletion.confirm.store');

// Public performer catalog (no auth — SEO/marketing surface). Separate from the
// authenticated /catalogo experience; interaction actions route to signup.
Route::get('/performers', [PublicCatalogController::class, 'index'])
    ->middleware('throttle:60,1')
    ->name('performers.public');
Route::get('/performers/{slug}', [PublicCatalogController::class, 'show'])
    ->middleware('throttle:60,1')
    ->where('slug', '[a-z0-9\-]+')
    ->name('performers.public.show');

// Catálogo de presentes da Limen — público e cacheável (só presentes ativos,
// preços fixos da plataforma). Sem PII; o mesmo shape é servido pela API.
Route::get('/presentes/catalogo', [GiftController::class, 'catalog'])
    ->middleware('throttle:60,1')
    ->name('gifts.catalog');

// Serving público das fotos da galeria do perfil (Sprint 10). PÚBLICO de
// propósito: a galeria é do perfil público, então qualquer visitante — logado ou
// não — vê os bytes, igual ao avatar/cover. Continua passando pela camada de
// bytes (re-sniff de Content-Type no servidor + nosniff), nunca por URL de disco:
// o disco `performer_photos` roda `serve => false`. Sem paywall e sem sessão, ao
// contrário do serving de Story (§ 2.3) — lá o conteúdo é gated, aqui é vitrine.
// Nome `performer.gallery.*` e NÃO `performer.photos.*`: este último já é da
// foto efêmera RECEBIDA (`performer.photos.image`, ReceivedPhotoController). Nome
// repetido não dá erro — o último registrado vence no lookup e `route()` no front
// apontaria para a rota errada. Ver a nota do RouteNameCollisionTest no CLAUDE.md.
Route::get('/performer/fotos/{photo}/imagem', [PerformerPhotoController::class, 'image'])
    ->middleware('throttle:120,1')
    ->whereNumber('photo')
    ->name('performer.gallery.image');

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

    // Login passwordless por código OTP (Sprint 11). Alternativa ao login por
    // senha — convive com ele. Guest-only como as demais rotas de auth.
    //
    // Throttle por IP nos POST (o teto por E-MAIL de 3/hora vive no OtpService, e
    // o hCaptcha no request completa o par email+IP da spec). O envio dispara
    // e-mail, então tem o mesmo teto do login. A verificação é 10/min por IP —
    // os 5 palpites por código já contêm o brute de um código específico.
    Route::get('/entrar-com-codigo', [OtpLoginController::class, 'requestForm'])
        ->middleware('throttle:60,1')
        ->name('otp.request.show');
    Route::post('/entrar-com-codigo', [OtpLoginController::class, 'sendCode'])
        ->middleware('throttle:5,1')
        ->name('otp.request');
    Route::get('/verificar-codigo', [OtpLoginController::class, 'verifyForm'])
        ->middleware('throttle:60,1')
        ->name('otp.verify.show');
    Route::post('/verificar-codigo', [OtpLoginController::class, 'verify'])
        ->middleware('throttle:10,1')
        ->name('otp.verify');

    Route::get('/esqueci-minha-senha', [ForgotPasswordController::class, 'create'])->name('password.request');
    Route::post('/esqueci-minha-senha', [ForgotPasswordController::class, 'store'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/resetar-senha/{token}', [ResetPasswordController::class, 'create'])->name('password.reset');
    Route::post('/resetar-senha', [ResetPasswordController::class, 'store'])->middleware('throttle:5,1')->name('password.update');
});

// Logout
Route::post('/logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

// Admin back-office (auth + admin role).
Route::middleware(['auth', 'role:admin'])->prefix('admin')->group(function () {
    // Painel de receita (Sprint 16): agregados do ledger + contadores + payouts
    // em needs_review. Só role:admin — moderador não vê receita.
    Route::get('/dashboard', [AdminDashboardController::class, 'index'])->name('admin.dashboard');

    Route::get('/waitlist', [WaitlistAdminController::class, 'index'])->name('admin.waitlist');

    // Fila de moderação das denúncias (conteúdo ilegal, coerção, etc).
    Route::get('/reports', [ReportAdminController::class, 'index'])->name('admin.reports');
    Route::patch('/reports/{report}', [ReportAdminController::class, 'update'])
        ->whereNumber('report')
        ->name('admin.reports.update');

    // Grant de tier (verificada/select/maison). tier_granted_by é autoridade
    // do servidor — os campos ficam fora do $fillable, gravação via forceFill.
    Route::post('/performers/{profile}/tier', [PerformerTierController::class, 'store'])
        ->whereNumber('profile')
        ->name('admin.performers.tier.store');

    // Fila de aprovação de KYC. A mutação delega a KycService (mesma fonte do
    // webhook Didit e da API admin) — aqui é só a porta web. Os nomes levam o
    // sufixo .panel porque admin.kyc.index/approve/reject já são da API
    // (routes/api.php) — reusar o nome faria route() apontar para o JSON.
    Route::get('/kyc', [KycAdminController::class, 'index'])->name('admin.kyc.panel');
    Route::post('/kyc/{verification}/approve', [KycAdminController::class, 'approve'])
        ->whereNumber('verification')
        ->name('admin.kyc.panel.approve');
    Route::post('/kyc/{verification}/reject', [KycAdminController::class, 'reject'])
        ->whereNumber('verification')
        ->name('admin.kyc.panel.reject');

    // Ban permanente de conta (moderação). `status='banned'` via forceFill —
    // fora do $fillable, autoridade do servidor. Ação admin server-side; NÃO
    // entra no allowlist do Ziggy (config/ziggy.php) — não é usada no JS.
    Route::post('/users/{user}/ban', [UserBanController::class, 'ban'])
        ->whereNumber('user')
        ->name('admin.users.ban');
});

// Área de moderação (Sprint 13 — refactor de roles). SEPARADA de /admin/*: o
// moderador vê a fila de denúncias, mas NÃO alcança KYC, payout, ban nem tier.
// `moderator.access` deixa passar moderator OU admin (o admin não perde nada).
Route::middleware(['auth', 'moderator.access'])->prefix('moderacao')->group(function () {
    Route::get('/denuncias', [ModerationController::class, 'index'])->name('moderacao.reports.index');
    Route::get('/denuncias/{report}', [ModerationController::class, 'show'])
        ->whereNumber('report')
        ->name('moderacao.reports.show');
    Route::patch('/denuncias/{report}', [ModerationController::class, 'update'])
        ->whereNumber('report')
        ->name('moderacao.reports.update');

    // Visualizador da PROVA RETIDA (Sprint 13). Serve o conteúdo denunciado —
    // foto efêmera, story, corpo da mensagem — SÓ quando há denúncia apontando
    // para ele (o EvidenceController resolve o alvo por id + `withTrashed` e casa
    // com uma linha de `reports`; sem ela, 404). `throttle` porque estes endpoints
    // decifram/leem bytes e são a superfície mais sensível da área de moderação.
    Route::middleware('throttle:30,1')->group(function () {
        Route::get('/evidencia/foto/{photo}', [EvidenceController::class, 'photo'])
            ->whereNumber('photo')
            ->name('moderacao.evidence.photo');
        Route::get('/evidencia/story/{story}', [EvidenceController::class, 'story'])
            ->whereNumber('story')
            ->name('moderacao.evidence.story');
        Route::get('/evidencia/mensagem/{message}', [EvidenceController::class, 'message'])
            ->whereNumber('message')
            ->name('moderacao.evidence.message');
    });
});

// Authenticated area
// Desafio de 2FA. FORA do gate `2fa` de propósito — pela mesma razão do aceite
// de documentos: é o destino do redirect daquele middleware, e gatear a própria
// tela de saída daria loop. O logout também fica fora (está acima, no seu
// próprio `auth`): quem perdeu o autenticador precisa conseguir sair da sessão.
//
// O POST é o alvo de força bruta da feature — 6 dígitos são 1 em 1.000.000 por
// tentativa, e a janela de ±30s do TOTP alarga isso. 5/min é o mesmo teto do
// login, e por simetria: as duas rotas guardam a mesma porta.
Route::middleware(['auth', 'role:performer'])->group(function () {
    Route::get('/performer/2fa/challenge', [TwoFactorController::class, 'challenge'])
        ->middleware('throttle:60,1')
        ->name('performer.2fa.challenge');

    Route::post('/performer/2fa/challenge', [TwoFactorController::class, 'verify'])
        ->middleware('throttle:5,1')
        ->name('performer.2fa.verify');
});

// `2fa` aplicado no grupo INTEIRO, não só nas rotas performer.*: a sessão da
// performer também alcança catálogo e chat, e gatear só o dashboard deixaria a
// conta sequestrada conversando com membros. O middleware ignora quem não é
// performer com 2FA ativo, então membro e admin passam iguais.
Route::middleware(['auth', '2fa'])->group(function () {
    Route::get('/email/verificar', [EmailVerificationController::class, 'notice'])->name('verification.notice');

    Route::get('/email/verificar/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::post('/email/verificar/reenviar', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    // O catálogo de MEMBROS é a vitrine onde a performer é o produto — ela não o
    // navega (o logo dela leva ao próprio painel, PR #146). O corte de papel mora
    // no CONTROLLER, não num `role:consumer` no meio: a performer é REDIRECIONADA
    // ao próprio painel (302), UX melhor que a página de 403 (UAT fase 1, R3); os
    // demais não-membros (admin) seguem barrados com 403. O serving da própria
    // atividade (TrackPerformerActivity) roda no grupo `web` inteiro, então o
    // carimbo de "última atividade" da performer não depende desta porta.
    // O `/catalogo/{slug}` (show) já fica aberto a performer/admin por link.
    Route::get('/catalogo', [CatalogController::class, 'index'])->name('catalog');
    Route::get('/catalogo/{slug}', [CatalogController::class, 'show'])->name('catalog.show');

    Route::patch('/preferencias', [UserPreferencesController::class, 'update'])
        ->middleware('throttle:30,1')
        ->name('preferences.update');

    // Sons de notificação (Sprint 16). Role-NEUTRA de propósito: `message` toca
    // para ambos, `tip`/`live` para a performer — por isso fica no grupo
    // compartilhado auth+2fa, e não sob role:consumer. Cada usuário edita só a
    // própria preferência (allowlist de chaves no Form Request).
    Route::patch('/notificacoes/som', [NotificationPreferencesController::class, 'update'])
        ->middleware('throttle:30,1')
        ->name('notifications.sound.update');

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
    // O `documents.accepted` entra porque desde o Sprint 9B a PERFORMER também
    // denuncia por aqui (a foto efêmera que ela recebeu). O middleware ignora
    // quem não é performer, então a rota continua aberta ao membro exatamente
    // como antes — é o mesmo padrão do chat, que é rota compartilhada.
    //
    // O que NÃO entra é `role:performer`: esta porta é a mesma para os quatro
    // tipos, e três deles (perfil, mensagem, story) são denunciados pelo MEMBRO.
    // Quem restringe a denúncia de foto à performer que a recebeu é
    // `Report::visibleTo()`, que exige um acesso VIVO àquela foto — condição
    // estritamente mais forte do que "tem papel de performer", e que um
    // middleware de papel não teria como expressar.
    Route::post('/reportar', [ReportController::class, 'store'])
        ->middleware(['documents.accepted', 'throttle:20,1', 'throttle:30,1440'])
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

    Route::middleware(['role:consumer', 'throttle:30,1', 'member.verified'])->group(function () {
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
            // Porta web do envio de KYC (a API Sanctum tem a dela em api.php);
            // ambas delegam ao KycSubmissionService.
            Route::post('/performer/onboarding/kyc', [OnboardingController::class, 'submitKyc'])
                ->middleware('throttle:10,1')
                ->name('performer.onboarding.kyc');
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

        // Foto de capa (UAT fix): gêmea da foto de perfil pela porta WEB. Sem ela
        // o `cover_path` (coluna + `cover_url` no resource + DeletionService)
        // nunca era preenchido pelo frontend Inertia, e o checklist "Adicionar
        // foto de capa" ficava impossível. Nome DISTINTO do `performer.profile.cover`
        // da API (mesma disciplina de `.photo` vs `.avatar`): nome repetido faria
        // route() apontar para a URL da API (405). Ver RouteNameCollisionTest.
        Route::post('/performer/perfil/capa', [PerformerProfileController::class, 'cover'])
            ->middleware('throttle:20,1')
            ->name('performer.profile.cover-photo')
            ->can('performer-active');

        // Localizações da performer (Sprint 13). Porta DEDICADA — separada de
        // `performer.profile.save`, que deixou de aceitar `state`/`city`: o
        // PerformerLocationService é a dona única da escrita (tabela + cache).
        // `role:performer` explícito além do `can('performer-active')`, como pede
        // a spec (auth+role:performer+2fa+documents.accepted vêm do grupo).
        Route::put('/performer/localizacoes', [PerformerLocationController::class, 'update'])
            ->middleware(['role:performer', 'throttle:30,1'])
            ->name('performer.locations.update')
            ->can('performer-active');

        // Sprint 7: sem `can('performer-active')` de propósito — a performer
        // PENDENTE também vê o próprio painel, com o KycPendingBanner e o "Ir
        // ao vivo" travado. É o destino do "Verificar depois" do KycGate; com o
        // gate antigo esse link dava 403. Suspensa continua barrada — o corte
        // por status vive no controller (só active|pending passam).
        Route::get('/performer/dashboard', [DashboardController::class, 'index'])
            ->middleware('role:performer')
            ->name('performer.dashboard');

        // "Disponível para conversa" (Sprint 11) — o Caminho 3 do Interesse
        // Expandido. Já dentro do grupo `documents.accepted`, que por sua vez
        // está sob `auth`+`2fa`: a rota nasce nos dois gates, como manda o
        // CLAUDE.md. Mais `role:performer` (só ela sinaliza) e o throttle de
        // 10/min. SEM `can('performer-active')` de propósito: o toggle é benigno
        // e sinalizar antes do KYC é no-op (o perfil pendente não está no
        // catálogo), do mesmo modo que o dashboard aceita performer pendente.
        Route::patch('/performer/disponibilidade', [AvailabilityController::class, 'toggle'])
            ->middleware(['role:performer', 'throttle:10,1'])
            ->name('performer.availability.toggle');

        // Boost pago (Sprint 11): a performer gasta tokens para destacar o perfil
        // no topo do catálogo. Já sob `auth`+`2fa`+`documents.accepted` dos
        // grupos-pai; `role:performer` explícito (só ela boosta) e throttle
        // 5/min — é rota que move o ledger, teto apertado. SEM
        // `can('performer-active')`: quem barra performer não-elegível (pendente
        // ou suspensa) é o BoostService, ANTES de qualquer débito, com um
        // `reason` claro — a rota não é o guard, e um 403 mudo aqui daria pior UX
        // que a mensagem de domínio.
        Route::post('/performer/boost', [BoostController::class, 'store'])
            ->middleware(['role:performer', 'throttle:5,1'])
            ->name('performer.boost');

        // Live pública GRÁTIS (Sprint 15, PR #139). Já sob `auth`+`2fa`+
        // `documents.accepted`; `feature:live` (dark launch) + `role:performer` +
        // `can('performer-active')` (só a performer ATIVA no catálogo transmite —
        // a pendente vê o botão travado, como o resto do dashboard). A live não
        // move o ledger (grátis); a receita é gorjeta/presente pelas rotas delas.
        Route::middleware(['role:performer', 'feature:live', 'throttle:30,1'])->group(function () {
            Route::get('/performer/live', [PerformerLiveController::class, 'page'])
                ->name('performer.live')
                ->can('performer-active');

            Route::post('/performer/live/start', [PerformerLiveController::class, 'start'])
                ->name('performer.live.start')
                ->can('performer-active');

            Route::post('/performer/live/stop', [PerformerLiveController::class, 'stop'])
                ->name('performer.live.stop')
                ->can('performer-active');

            // Frame de preview do catálogo (PR #143): o <LiveRoom> captura um quadro
            // do vídeo local (canvas) a cada ~10s (6/min << 30/min do grupo) e envia
            // aqui; grava só se houver live ativa. Throttle próprio um pouco mais
            // largo para absorver jitter de rede sem competir com start/stop.
            Route::post('/performer/live/preview-frame', [PerformerLiveController::class, 'previewFrame'])
                ->middleware('throttle:20,1')
                ->name('performer.live.preview')
                ->can('performer-active');
        });

        // 2FA TOTP. Sem `can('performer-active')` de propósito: a performer
        // pendente (em KYC) já tem senha, e-mail e documento enviado — é
        // justamente a janela em que a conta guarda PII e ainda não tem
        // segundo fator. Adiar o 2FA até a ativação protegeria o KYC só
        // depois de ele já estar no banco.
        //
        // A alteração do próprio fator entra pelo throttle mais apertado:
        // confirm/disable/recovery comparam código, então são superfície de
        // brute force igual ao desafio.
        Route::middleware('role:performer')->group(function () {
            Route::get('/performer/configuracoes/2fa', [TwoFactorController::class, 'show'])
                ->middleware('throttle:60,1')
                ->name('performer.2fa.show');

            Route::post('/performer/configuracoes/2fa/enable', [TwoFactorController::class, 'enable'])
                ->middleware('throttle:10,1')
                ->name('performer.2fa.enable');

            Route::post('/performer/configuracoes/2fa/confirm', [TwoFactorController::class, 'confirm'])
                ->middleware('throttle:5,1')
                ->name('performer.2fa.confirm');

            Route::post('/performer/configuracoes/2fa/disable', [TwoFactorController::class, 'disable'])
                ->middleware('throttle:5,1')
                ->name('performer.2fa.disable');

            Route::post('/performer/configuracoes/2fa/recovery-codes', [TwoFactorController::class, 'regenerateRecoveryCodes'])
                ->middleware('throttle:5,1')
                ->name('performer.2fa.recovery-codes');
        });

        // Origem do envio de Interesse Controlado: a performer escolhe entre quem
        // já a segue. Ver Web\Performer\FollowersController.
        Route::get('/performer/seguidores', [FollowersController::class, 'index'])
            ->middleware('throttle:60,1')
            ->name('performer.followers')
            ->can('performer-active');

        // Notas privadas da performer sobre membros (Sprint 11). A performer
        // anota sobre um FanAlias; o membro NUNCA vê. Já sob
        // `auth`+`2fa`+`documents.accepted` (grupos-pai). `role:performer` é
        // EXPLÍCITO em cada rota, não herdado: este grupo `documents.accepted`
        // NÃO aplica `role:performer` no todo — só subgrupos específicos o fazem,
        // e as notas não estão em nenhum deles. `can('performer-active')` já
        // exigiria `role===performer`, mas mantê-lo redundante é o piso de papel
        // que sobrevive a um afrouxamento futuro do gate (como o dashboard e o
        // toggle de disponibilidade, que aceitam performer `pending`) — achado da
        // revisão de segurança. O `member_handle` da rota é o handle opaco do
        // FanAlias (16 hex), resolvido contra seguidores/visitantes no Form
        // Request — nunca o id do membro. Ver Web\Performer\MemberNotesController.
        Route::get('/performer/notas', [MemberNotesController::class, 'index'])
            ->middleware(['role:performer', 'throttle:60,1'])
            ->name('performer.notes.index')
            ->can('performer-active');

        Route::put('/performer/notas/{member_handle}', [MemberNotesController::class, 'update'])
            ->middleware(['role:performer', 'throttle:30,1'])
            ->whereAlphaNumeric('member_handle')
            ->name('performer.notes.save')
            ->can('performer-active');

        Route::delete('/performer/notas/{member_handle}', [MemberNotesController::class, 'destroy'])
            ->middleware(['role:performer', 'throttle:30,1'])
            ->whereAlphaNumeric('member_handle')
            ->name('performer.notes.destroy')
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

        // Interesse a partir do PAINEL DE VISITANTES. Rota separada da de cima
        // de propósito: a origem decide contra qual conjunto o handle é
        // resolvido, e um `source` no payload deixaria essa escolha com o
        // cliente. Throttle mais apertado que o da outra porta porque a cota
        // diária aqui é 3, não 5.
        Route::post('/performer/interesses/visitante', [PerformerInterestController::class, 'storeForVisitor'])
            ->middleware('throttle:6,1')
            ->name('performer.interests.send-visitor')
            ->can('performer-active');

        // Catálogo de MEMBROS (Sprint 16): a performer navega membros que optaram
        // por aparecer e sinaliza interesse (Interesse Controlado invertido). Só
        // performer verificada (can('performer-active')); a máscara de privacidade
        // e a regra de visibilidade vivem no MemberCatalogService.
        // `role:performer` explícito além do `can('performer-active')`: o grupo
        // `documents.accepted` NÃO aplica papel no todo (só subgrupos), então é o
        // piso de papel redundante que notas/boost adotaram — se o gate
        // performer-active for afrouxado no futuro, a rota não fica sem rede.
        Route::get('/performer/membros', [MemberCatalogController::class, 'index'])
            ->middleware(['role:performer', 'throttle:60,1'])
            ->name('performer.members')
            ->can('performer-active');

        // Envio de interesse a partir do catálogo. Rota/porta separada das outras
        // duas de propósito (a origem decide contra qual conjunto o handle é
        // resolvido — ver SendInterestFromCatalogRequest). Throttle igual ao de
        // seguidores: a cota diária desta origem é 5.
        Route::post('/performer/membros/interesse', [PerformerInterestController::class, 'storeFromCatalog'])
            ->middleware(['role:performer', 'throttle:10,1'])
            ->name('performer.members.interest')
            ->can('performer-active');

        // Foto efêmera recebida de um membro (Sprint 9B). Dentro do grupo
        // `documents.accepted`, que por sua vez está sob `auth`+`2fa`: a rota
        // nasce nos dois gates, como manda o CLAUDE.md. `can('performer-active')`
        // porque quem ainda está em KYC não conversa com membro nenhum e,
        // portanto, não tem foto para receber.
        Route::get('/performer/fotos-recebidas/{access}/imagem', [ReceivedPhotoController::class, 'image'])
            ->middleware(['role:performer', 'throttle:60,1'])
            ->whereNumber('access')
            ->name('performer.photos.image')
            ->can('performer-active');

        // Stories da performer (Sprint 9C). Dentro do grupo `documents.accepted`,
        // que por sua vez está sob `auth`+`2fa`: a rota nasce nos dois gates, como
        // manda o CLAUDE.md ("rota autenticada nova entra no gate"). Publicar
        // conteúdo é exatamente o que a Política de Conteúdo Proibido governa, e
        // `can('performer-active')` porque quem ainda está em KYC não publica.
        //
        // O POST leva o throttle de 10/min — é a rota cara (re-encode do
        // ImageProcessingService, com o pico de memória do config/image.php) e a
        // única que grava em disco. Mesmo teto do upload da foto efêmera.
        Route::middleware('role:performer')->group(function () {
            Route::get('/performer/stories', [PerformerStoryController::class, 'index'])
                ->middleware('throttle:60,1')
                ->name('performer.stories.index')
                ->can('performer-active');

            Route::post('/performer/stories', [PerformerStoryController::class, 'store'])
                ->middleware('throttle:10,1')
                ->name('performer.stories.store')
                ->can('performer-active');

            Route::delete('/performer/stories/{story}', [PerformerStoryController::class, 'destroy'])
                ->middleware('throttle:20,1')
                ->whereNumber('story')
                ->name('performer.stories.destroy')
                ->can('performer-active');

            // Thumbnail do painel dela. Rota separada da do membro de propósito —
            // o serving do membro é onde vive o paywall (§ 2.3), e um ramo "ou é a
            // dona" ali seria exceção dentro do caminho que precisa ser lido sem
            // ressalva.
            Route::get('/performer/stories/{story}/imagem', [PerformerStoryController::class, 'image'])
                ->middleware('throttle:60,1')
                ->whereNumber('story')
                ->name('performer.stories.image')
                ->can('performer-active');

            // Conteúdo permanente pago (Sprint 14, M.4/M.13.13). Gestão pela
            // performer: já sob auth+2fa+documents.accepted, mais role:performer
            // (grupo) e performer-active. O `desbloqueios` (GET, literal) e o POST
            // vêm antes das rotas com {content}. Serving da própria peça (preview de
            // gestão) é rota SEPARADA da do membro, onde vive o paywall.
            // Tela de gestão (UAT fix): página Inertia. Vem ANTES das rotas com
            // {content} (todas com sufixo /… ou whereNumber), então `/gerenciar`
            // não é capturado por elas. O `index` (JSON) segue como fonte de dados.
            Route::get('/performer/conteudo/gerenciar', [PerformerContentController::class, 'page'])
                ->middleware('throttle:60,1')
                ->name('performer.content')
                ->can('performer-active');

            Route::get('/performer/conteudo', [PerformerContentController::class, 'index'])
                ->middleware('throttle:60,1')
                ->name('performer.content.index')
                ->can('performer-active');

            Route::post('/performer/conteudo', [PerformerContentController::class, 'store'])
                ->middleware('throttle:10,1')
                ->name('performer.content.store')
                ->can('performer-active');

            Route::get('/performer/conteudo/{content}/desbloqueios', [PerformerContentController::class, 'unlockers'])
                ->middleware('throttle:60,1')
                ->whereNumber('content')
                ->name('performer.content.unlockers')
                ->can('performer-active');

            Route::get('/performer/conteudo/{content}/imagem', [PerformerContentController::class, 'image'])
                ->middleware('throttle:60,1')
                ->whereNumber('content')
                ->name('performer.content.image')
                ->can('performer-active');

            Route::delete('/performer/conteudo/{content}', [PerformerContentController::class, 'destroy'])
                ->middleware('throttle:20,1')
                ->whereNumber('content')
                ->name('performer.content.destroy')
                ->can('performer-active');

            // Galeria de fotos do perfil (Sprint 10). Mesmos gates da edição de
            // perfil e dos stories: já sob `auth`+`2fa`+`documents.accepted`, mais
            // `role:performer` (grupo) e `can('performer-active')` — publicar
            // conteúdo de perfil é para performer no ar. O serving fica FORA daqui
            // (rota pública lá em cima); estas três são gestão.
            //
            // O POST leva o throttle de 10/min — é a rota cara (re-encode com o
            // pico de memória do config/image.php) e a única que grava em disco.
            // Mesmo teto do upload do story e da foto efêmera.
            Route::post('/performer/fotos', [PerformerPhotoController::class, 'store'])
                ->middleware('throttle:10,1')
                ->name('performer.gallery.store')
                ->can('performer-active');

            Route::delete('/performer/fotos/{photo}', [PerformerPhotoController::class, 'destroy'])
                ->middleware('throttle:20,1')
                ->whereNumber('photo')
                ->name('performer.gallery.destroy')
                ->can('performer-active');

            Route::patch('/performer/fotos/reordenar', [PerformerPhotoController::class, 'reorder'])
                ->middleware('throttle:30,1')
                ->name('performer.gallery.reorder')
                ->can('performer-active');

            // Permissões por foto (Sprint 13). Mesmos gates da gestão acima —
            // já sob auth+2fa+documents.accepted, mais role:performer (grupo) e
            // performer-active. `solicitacoes` (GET, literal) vem ANTES das rotas
            // com {photo} para não ser capturada por elas; e o serving público
            // continua sendo o `performer.gallery.image` lá em cima, fora daqui.
            //
            // O `member_handle` é o handle opaco do FanAlias (16 hex), resolvido
            // no PhotoAccessService contra os solicitantes/concedidos DESTA foto —
            // nunca o id do membro. Todo modo de falha é 404 uniforme.
            Route::get('/performer/fotos/solicitacoes', [PerformerPhotoController::class, 'requests'])
                ->middleware('throttle:60,1')
                ->name('performer.gallery.requests')
                ->can('performer-active');

            Route::patch('/performer/fotos/{photo}/visibilidade', [PerformerPhotoController::class, 'visibility'])
                ->middleware('throttle:30,1')
                ->whereNumber('photo')
                ->name('performer.gallery.visibility')
                ->can('performer-active');

            Route::post('/performer/fotos/{photo}/conceder/{member_handle}', [PerformerPhotoController::class, 'grant'])
                ->middleware('throttle:30,1')
                ->whereNumber('photo')
                ->whereAlphaNumeric('member_handle')
                ->name('performer.gallery.grant')
                ->can('performer-active');

            Route::delete('/performer/fotos/{photo}/revogar/{member_handle}', [PerformerPhotoController::class, 'revoke'])
                ->middleware('throttle:30,1')
                ->whereNumber('photo')
                ->whereAlphaNumeric('member_handle')
                ->name('performer.gallery.revoke')
                ->can('performer-active');
        });

        // Histórico dos envios desta performer (para quem, quem revelou, cota do dia).
        Route::get('/performer/interesses', [SentInterestsController::class, 'index'])
            ->middleware('throttle:60,1')
            ->name('performer.interests.index')
            ->can('performer-active');
    });

    // Exclusão de conta (LGPD art. 18, VI). Fora de role:consumer — performer
    // também é titular. Throttle apertado: o pedido dispara e-mail.
    Route::post('/conta/solicitar-exclusao', [AccountDeletionController::class, 'request'])
        ->middleware('throttle:5,1')
        ->name('account.deletion.request');

    Route::post('/conta/cancelar-exclusao', [AccountDeletionController::class, 'cancel'])
        ->middleware('throttle:10,1')
        ->name('account.deletion.cancel');

    // KYC Nível 2 do membro (envio de selfie). FORA de role:consumer/member.verified
    // de propósito: é o destino do redirect do EnsureMemberVerified — gatear a
    // própria tela de saída daria loop. O corte por papel vive no controller.
    // O membro em pending_kyc ainda não é 'active', então precisa alcançar estas
    // rotas antes de qualquer área de membro.
    Route::get('/verificacao', [ConsumerKycController::class, 'index'])
        ->middleware('throttle:60,1')
        ->name('consumer.kyc.index');

    Route::post('/verificacao/enviar', [ConsumerKycController::class, 'submit'])
        ->middleware('throttle:5,1')
        ->name('consumer.kyc.submit');

    Route::get('/verificacao/aguardando', [ConsumerKycController::class, 'waiting'])
        ->middleware('throttle:60,1')
        ->name('consumer.kyc.waiting');

    // `member.verified` no grupo INTEIRO: toda área de membro (painel, gorjetas,
    // interesses, configurações, assinaturas, carteira) exige a selfie aprovada.
    // O middleware ignora quem não é consumer/pending_kyc, então não afeta as
    // sub-rotas nem quebra o padrão dos outros grupos.
    Route::middleware(['role:consumer', 'member.verified'])->group(function () {
        // Home da área logada do membro.
        Route::get('/painel', [ConsumerDashboardController::class, 'index'])
            ->middleware('throttle:60,1')
            ->name('consumer.dashboard');

        // Feed de conteúdo permanente das performers seguidas (Sprint 16).
        // Consome o backend do PR #135; desbloqueio/serving reusam content.*.
        Route::get('/feed', [FeedController::class, 'index'])
            ->middleware('throttle:60,1')
            ->name('feed');

        Route::post('/gorjetas', [TipController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('tips.send');

        // Presente virtual do catálogo da Limen para uma performer (M.13.6).
        // Débito do membro + crédito split 75/25 da performer, idempotente.
        Route::post('/presentes', [GiftController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('gifts.send');

        // Assistir à live pública GRÁTIS (Sprint 15, PR #139). Já sob
        // `auth`+`2fa`+`role:consumer`+`member.verified`; + `feature:live` (dark
        // launch). Rota por SLUG da performer, não pelo room_name — o nome LiveKit
        // fica server-side (invariante do #138), só dentro do JWT. Não cobra
        // tokens; gorjeta/presente usam as rotas acima. O refresh reautoriza a
        // cada renovação (410 quando a live encerrou) — "expiração na leitura".
        Route::middleware('feature:live')->group(function () {
            Route::get('/live/{slug}', [LiveViewController::class, 'show'])
                ->middleware('throttle:60,1')
                ->name('live.show');

            Route::post('/live/{slug}/token-refresh', [LiveViewController::class, 'refresh'])
                ->middleware('throttle:30,1')
                ->name('live.refresh');

            // Preview animado da live no catálogo (PR #143). Por SLUG (o card tem o
            // slug; o resource não expõe o id). Só membro autenticado (o grupo já é
            // role:consumer + member.verified). 404 quando não há live/frame — o
            // hover cai no badge sozinho. Throttle largo: o hover pode repuxar o
            // frame a cada ~10s enquanto o card está sob o cursor.
            Route::get('/live-preview/{slug}', [LiveViewController::class, 'preview'])
                ->middleware('throttle:120,1')
                ->where('slug', '[a-z0-9\-]+')
                ->name('live.preview');
        });

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

        // Perfil do membro: interesses + "o que estou buscando". Separado de
        // /configuracoes de propósito — lá é privacidade e conta, aqui é
        // auto-declaração. Nada do que se escreve aqui volta para uma
        // superfície da performer (ver Consumer\ProfileController).
        Route::get('/meu-perfil', [ConsumerProfileController::class, 'edit'])
            ->middleware('throttle:60,1')
            ->name('consumer.profile.edit');

        Route::put('/meu-perfil', [ConsumerProfileController::class, 'update'])
            ->middleware('throttle:20,1')
            ->name('consumer.profile.update');

        // Estilo de Vida (Sprint 10). Rota PRÓPRIA, e não mais um campo no PUT
        // acima: `lifestyle_tier` está fora do $fillable (mesma disciplina do
        // Modo Discreto) e é o único campo desta tela que a performer vê. As
        // duas coisas pedem uma porta que se leia sozinha em code review.
        Route::patch('/meu-perfil/estilo-de-vida', [ConsumerProfileController::class, 'updateLifestyleTier'])
            ->middleware('throttle:20,1')
            ->name('consumer.profile.lifestyle-tier');

        // Favoritos (Sprint 10) — bookmark PRIVADO do membro.
        //
        // Aqui e não ao lado de `catalog.follow`, apesar de o coração ficar no
        // mesmo card: follow é superfície compartilhada (a performer lê a outra
        // ponta), favorito é área de membro e a performer não tem lado nenhum
        // nesta tabela. O grupo já traz `role:consumer` + `member.verified`.
        //
        // O param é `{slug}` como nas rotas de follow — a resolução é a mesma
        // `PerformerCatalogService::findBySlug()`, então favoritar um perfil
        // fora do ar 404 igual a abri-lo.
        Route::get('/favoritos', [FavoriteController::class, 'index'])
            ->middleware('throttle:60,1')
            ->name('favorites.index');

        // POST único que alterna — não há par store/destroy como no follow. Um
        // clique no coração não sabe (nem deve precisar saber) o estado atual do
        // servidor; a corrida entre dois cliques é resolvida no
        // FavoriteService::toggle(), sob lock, e não pelo verbo HTTP.
        Route::post('/favoritos/{slug}', [FavoriteController::class, 'toggle'])
            ->middleware('throttle:30,1')
            ->name('favorites.toggle');

        // Solicitar acesso a uma foto PRIVADA da galeria (Sprint 13). Área do
        // membro (role:consumer + member.verified, já sob auth+2fa), como seguir
        // e favoritar: é um ato deliberado do membro, que expõe o FanAlias dele à
        // performer mas NÃO cria Follow. Idempotente; foto pública ou perfil fora
        // do ar → 404 uniforme (PhotoAccessService). `{photo}` numérico.
        Route::post('/fotos/{photo}/solicitar-acesso', [PhotoAccessController::class, 'request'])
            ->middleware('throttle:20,1')
            ->whereNumber('photo')
            ->name('photos.access.request');

        // Buscas salvas (Sprint 12) — o membro guarda combinações de filtros do
        // catálogo para reaplicar. Área de membro: a performer não tem rota irmã
        // aqui e não é para ganhar uma (decisão do PO — R3 do Sprint 9). O grupo
        // já traz `role:consumer` + `member.verified`, sob `auth`+`2fa`.
        //
        // Consumidas por `fetch` (JSON): o Form Request usa FailsValidationAsJson,
        // e o cap de 10 é imposto sob lock no SavedSearchService.
        Route::get('/buscas-salvas', [SavedSearchController::class, 'index'])
            ->middleware('throttle:60,1')
            ->name('saved-searches.index');

        Route::post('/buscas-salvas', [SavedSearchController::class, 'store'])
            ->middleware('throttle:30,1')
            ->name('saved-searches.store');

        // `{search}` numérico e checagem de dono no controller — 404 para a busca
        // de outro membro, indistinguível de inexistente.
        Route::delete('/buscas-salvas/{search}', [SavedSearchController::class, 'destroy'])
            ->middleware('throttle:30,1')
            ->whereNumber('search')
            ->name('saved-searches.destroy');

        // Configurações do membro (hoje: Modo Discreto).
        Route::get('/configuracoes', [ConsumerPreferencesController::class, 'index'])
            ->middleware('throttle:60,1')
            ->name('consumer.settings');

        Route::patch('/configuracoes/modo-discreto', [ConsumerPreferencesController::class, 'toggleDiscreteMode'])
            ->middleware('throttle:20,1')
            ->name('consumer.settings.discrete-mode');

        // Perks de privacidade Black/FC (Ghost Mode, Status Invisível, Read
        // Receipts). Um endpoint, perk validado por allowlist.
        Route::patch('/configuracoes/privacidade', [ConsumerPreferencesController::class, 'togglePrivacyPerk'])
            ->middleware('throttle:20,1')
            ->name('consumer.settings.privacy');

        // Visibilidade no catálogo de membros (Sprint 16): o membro liga/desliga
        // aparecer para as performers. Escreve a escolha EXPLÍCITA (tri-state).
        Route::patch('/configuracoes/visibilidade-performers', [ConsumerPreferencesController::class, 'togglePerformerVisibility'])
            ->middleware('throttle:20,1')
            ->name('consumer.settings.performer-visibility');

        // Assinaturas (Círculos) — escolha de tier + cartão + cancelamento.
        Route::get('/assinar', [SubscriptionController::class, 'index'])->name('subscribe.index');
        // throttle apertado: barra carding/BIN — poucas tentativas de cartão/min.
        Route::post('/assinar', [SubscriptionController::class, 'store'])
            ->middleware('throttle:3,1')
            ->name('subscribe.store');
        Route::post('/assinar/cancelar', [SubscriptionController::class, 'cancel'])
            ->middleware('throttle:6,1')
            ->name('subscribe.cancel');

        // Fotos efêmeras do membro (Sprint 9B). Dentro do grupo
        // `role:consumer` + `member.verified` — e o grupo já está sob
        // `auth`+`2fa` —, então a rota nova entra no gate pelas duas portas sem
        // repetir middleware. Ver "Rota autenticada nova entra no gate" no
        // CLAUDE.md.
        //
        // O upload leva o throttle de 10/min do § 1.6, o mesmo teto da gorjeta:
        // é a rota cara (re-encode + cifra) e a única que grava em disco.
        Route::post('/membro/fotos', [MemberPhotoController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('member.photos.store');

        Route::post('/membro/fotos/{photo}/compartilhar', [MemberPhotoController::class, 'share'])
            ->middleware('throttle:20,1')
            ->whereNumber('photo')
            ->name('member.photos.share');

        Route::delete('/membro/fotos/{photo}', [MemberPhotoController::class, 'destroy'])
            ->middleware('throttle:20,1')
            ->whereNumber('photo')
            ->name('member.photos.destroy');

        // Serving ao próprio titular. Throttle mais folgado: é GET de imagem, e
        // a tela pode carregar as 5 ativas de uma vez.
        Route::get('/membro/fotos/{photo}/imagem', [MemberPhotoController::class, 'image'])
            ->middleware('throttle:60,1')
            ->whereNumber('photo')
            ->name('member.photos.image');

        // Stories do lado do membro (Sprint 9C). Dentro de `role:consumer` +
        // `member.verified`, e o grupo já está sob `auth`+`2fa` — a rota nasce no
        // gate pelas duas portas sem repetir middleware.
        //
        // O serving é AUTENTICADO por sessão, com follow e tier resolvidos a cada
        // request (§ 2.3). Não há — e não deve haver — versão assinada desta rota:
        // assinatura não amarra viewer nenhum, e a URL do membro Black viraria um
        // bearer token que qualquer pessoa deslogada usa até expirar.
        Route::get('/stories/feed', [ConsumerStoryController::class, 'feed'])
            ->middleware('throttle:60,1')
            ->name('stories.feed');

        Route::get('/stories/{story}/imagem', [ConsumerStoryController::class, 'image'])
            ->middleware('throttle:120,1')
            ->whereNumber('story')
            ->name('stories.image');

        // Conteúdo permanente pago (Sprint 14, M.4/M.13.13). Lado do MEMBRO, no
        // grupo role:consumer + member.verified sob auth+2fa. Serving AUTENTICADO
        // por sessão, tier resolvido a cada request — sem URL assinada, pela mesma
        // razão do Story: assinatura não amarra viewer, viraria bearer token.
        Route::get('/conteudo/{content}', [ContentController::class, 'show'])
            ->middleware('throttle:60,1')
            ->whereNumber('content')
            ->name('content.show');

        Route::get('/conteudo/{content}/imagem', [ContentController::class, 'image'])
            ->middleware('throttle:120,1')
            ->whereNumber('content')
            ->name('content.image');

        // Bytes do vídeo higienizado (MP4). Range/seek via BinaryFileResponse;
        // mesma autorização por request do serving da imagem.
        Route::get('/conteudo/{content}/video', [ContentController::class, 'video'])
            ->middleware('throttle:120,1')
            ->whereNumber('content')
            ->name('content.video');

        Route::post('/conteudo/{content}/desbloquear', [ContentController::class, 'unlock'])
            ->middleware('throttle:20,1')
            ->whereNumber('content')
            ->name('content.unlock');

        Route::get('/wallet', [WalletController::class, 'index'])->name('wallet.index');
        Route::get('/wallet/history', [WalletController::class, 'history'])->name('wallet.history');

        Route::post('/wallet/purchase/{package}', [WalletController::class, 'purchase'])
            ->middleware('throttle:10,1')
            ->name('wallet.purchase');

        Route::get('/wallet/pending', [WalletController::class, 'pending'])
            ->middleware('throttle:60,1')
            ->name('wallet.pending');
    });

    // Chamada privada 1:1 com cobrança por minuto (Sprint 15, PR #140). Dark
    // launch: `feature:call`. Já sob `auth`+`2fa` (grupo-pai). A cobrança
    // pré-paga por minuto (spend_call/call_credit 70/30) vive no CallService.
    //
    // A AUTORIZAÇÃO DE PARTICIPANTE das rotas `/call/{call}/*` é do CallService
    // (404 uniforme p/ chamada de terceiro), NÃO do route-binding: `{call}` é
    // resolvido por id, então o serviço confere `member_id`/`performer_profile_id
    // == ator`. Armadilha do SubstituteBindings (CLAUDE.md): testar com id
    // EXISTENTE de terceiro, não inexistente (esse 404 do binding não exercita o
    // gate).
    Route::middleware('feature:call')->group(function () {
        // Membro pede a chamada. role:consumer + member.verified (área de membro,
        // como assistir à live). `{profile}` = performer_profile_id.
        Route::post('/performer/{profile}/call/request', [CallController::class, 'request'])
            ->middleware(['role:consumer', 'member.verified', 'throttle:10,1'])
            ->whereNumber('profile')
            ->name('call.request');

        // Performer aceita/recusa/configura. role:performer + documents.accepted
        // (atender é publicar sob a Política de Conteúdo) + performer-active (só a
        // performer no ar), mesmos gates da live.
        Route::middleware(['role:performer', 'documents.accepted'])->group(function () {
            Route::post('/performer/call/{call}/accept', [CallController::class, 'accept'])
                ->middleware('throttle:20,1')
                ->whereNumber('call')
                ->name('call.accept')
                ->can('performer-active');

            Route::post('/performer/call/{call}/decline', [CallController::class, 'decline'])
                ->middleware('throttle:20,1')
                ->whereNumber('call')
                ->name('call.decline')
                ->can('performer-active');

            // Preço/minuto (piso 5, passo 5) + teto de duração. Campos fora do
            // $fillable; escrita só pelo CallService.
            Route::patch('/performer/chamada/configuracoes', [CallSettingsController::class, 'update'])
                ->middleware('throttle:30,1')
                ->name('performer.call.settings')
                ->can('performer-active');
        });

        // Rotas por sessão, compartilhadas pelos dois lados. SEM role middleware —
        // o CallService resolve o participante (404 uniforme p/ terceiro). O
        // heartbeat é do membro; token-refresh e end servem os dois.
        Route::post('/call/{call}/heartbeat', [CallController::class, 'heartbeat'])
            ->middleware('throttle:120,1')
            ->whereNumber('call')
            ->name('call.heartbeat');

        Route::post('/call/{call}/token-refresh', [CallController::class, 'tokenRefresh'])
            ->middleware('throttle:30,1')
            ->whereNumber('call')
            ->name('call.token-refresh');

        Route::post('/call/{call}/end', [CallController::class, 'end'])
            ->middleware('throttle:60,1')
            ->whereNumber('call')
            ->name('call.end');

        // ── Group show 1:X (Sprint 15, PR #141) ──────────────────────────────
        // Mesmo dark-launch (`feature:call`) e mesma disciplina do 1:1. `{group}`
        // = call_sessions.id (NUNCA o room_name — invariante do #138). A
        // autorização de PARTICIPANTE das rotas de membro é do GroupShowService
        // (404 uniforme p/ participação de terceiro), não do route-binding.

        // Performer: inicia/encerra o show e resolve o upgrade. start/stop resolvem
        // "o group dela" pelo perfil (sem id); upgrade-accept/decline levam {group}.
        Route::middleware(['role:performer', 'documents.accepted'])->group(function () {
            Route::post('/performer/group/start', [PerformerGroupShowController::class, 'start'])
                ->middleware('throttle:20,1')
                ->name('group.start')
                ->can('performer-active');

            Route::post('/performer/group/stop', [PerformerGroupShowController::class, 'stop'])
                ->middleware('throttle:20,1')
                ->name('group.stop')
                ->can('performer-active');

            Route::post('/performer/group/{group}/upgrade-accept', [PerformerGroupShowController::class, 'upgradeAccept'])
                ->middleware('throttle:20,1')
                ->whereNumber('group')
                ->name('group.upgrade.accept')
                ->can('performer-active');

            Route::post('/performer/group/{group}/upgrade-decline', [PerformerGroupShowController::class, 'upgradeDecline'])
                ->middleware('throttle:20,1')
                ->whereNumber('group')
                ->name('group.upgrade.decline')
                ->can('performer-active');
        });

        // Membro: entra, paga (heartbeat próprio), renova, sai, pede upgrade.
        // role:consumer + member.verified (área de membro, como assistir à live).
        Route::middleware(['role:consumer', 'member.verified'])->group(function () {
            Route::post('/group/{group}/join', [GroupShowController::class, 'join'])
                ->middleware('throttle:20,1')
                ->whereNumber('group')
                ->name('group.join');

            Route::post('/group/{group}/heartbeat', [GroupShowController::class, 'heartbeat'])
                ->middleware('throttle:120,1')
                ->whereNumber('group')
                ->name('group.heartbeat');

            Route::post('/group/{group}/token-refresh', [GroupShowController::class, 'tokenRefresh'])
                ->middleware('throttle:30,1')
                ->whereNumber('group')
                ->name('group.token-refresh');

            Route::post('/group/{group}/leave', [GroupShowController::class, 'leave'])
                ->middleware('throttle:60,1')
                ->whereNumber('group')
                ->name('group.leave');

            Route::post('/group/{group}/upgrade-request', [GroupShowController::class, 'upgradeRequest'])
                ->middleware('throttle:10,1')
                ->whereNumber('group')
                ->name('group.upgrade.request');
        });
    });
});
