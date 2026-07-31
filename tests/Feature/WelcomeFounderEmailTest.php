<?php

use App\Jobs\SendWelcomeEmail;
use App\Mail\WelcomeFounderEmail;
use App\Models\IdentityVerification;
use App\Models\User;
use App\Services\KycService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Carta dos fundadores, disparada no KYC aprovado (Sprint 9).
 *
 * Três teses:
 *
 *  1. **Vale para os dois lados.** `KycService::approve()` é o único ponto de
 *     aprovação do produto e atende performer e membro — o membro nasce
 *     `pending_kyc` e só vira `active` por aqui. Um teste por papel para a
 *     cobertura não depender de qual dos dois alguém lembrar de exercitar.
 *
 *  2. **Uma vez por conta.** `approve()` é alcançável por três caminhos e o job
 *     pode ser repetido pela fila. Uma apresentação pessoal que chega duas
 *     vezes desmente o próprio tom.
 *
 *  3. **O ENVELOPE não denuncia nada.** É a decisão do PO, e é a que não falha
 *     sozinha: um assunto indiscreto continua entregando o e-mail normalmente,
 *     então só um teste com lista de termos proibidos segura isso.
 *
 * Helpers locais (prefixo wel*) para o arquivo ser autossuficiente.
 */
function welPerformerVerification(): IdentityVerification
{
    $user = User::factory()->create([
        'name' => 'Ana Paula Souza',
        'role' => 'performer',
        'status' => 'pending',
    ]);

    $user->performerProfile()->create([
        'stage_name' => 'Perf '.Str::random(8),
        'slug' => 'wel-'.strtolower(Str::random(6)),
        'category' => 'mulheres',
        'is_verified' => false,
        'level' => 'iniciante',
        'split_pct' => 65,
    ]);

    return $user->identityVerifications()->create([
        'document_type' => 'rg',
        'document_number' => '52998224725',
        'full_legal_name' => 'Nome Legal Sigiloso',
        'date_of_birth' => '1998-01-01',
        'provider' => 'manual',
        'provider_status' => 'pending',
        'status' => 'pending',
    ]);
}

/** Membro: nasce `pending_kyc` e só vira `active` na aprovação da selfie. */
function welMemberVerification(): IdentityVerification
{
    $user = User::factory()->create([
        'name' => 'Carlos Eduardo Lima',
        'role' => 'consumer',
        'status' => 'pending_kyc',
    ]);

    // Selfie-only: `document_type`/`document_number`/nome/nascimento ficam
    // NULOS no KYC do membro — é o que o KycSubmissionService grava (ele só
    // pede documento da performer). Preencher aqui mediria um fluxo que não
    // existe, e `selfie` nem é valor do enum.
    return $user->identityVerifications()->create([
        'provider' => 'manual',
        'provider_status' => 'pending',
        'status' => 'pending',
    ]);
}

function welApprove(IdentityVerification $verification): void
{
    app(KycService::class)->approve($verification);
}

// ─── Disparo nos dois papéis ────────────────────────────────────────────────

it('manda a carta quando o KYC da performer e aprovado', function () {
    Mail::fake();

    $verification = welPerformerVerification();
    welApprove($verification);

    Mail::assertSent(WelcomeFounderEmail::class, fn ($mail) => $mail->hasTo($verification->user->email));

    expect($verification->user->fresh()->welcome_email_sent_at)->not->toBeNull();
});

it('manda a carta quando o KYC do membro e aprovado', function () {
    Mail::fake();

    $verification = welMemberVerification();
    welApprove($verification);

    // O membro tem KYC obrigatório (nasce pending_kyc), então esta é a mesma
    // porta da performer — não há fallback pendurado na verificação de e-mail.
    Mail::assertSent(WelcomeFounderEmail::class, fn ($mail) => $mail->hasTo($verification->user->email));

    expect($verification->user->fresh()->status)->toBe('active')
        ->and($verification->user->fresh()->welcome_email_sent_at)->not->toBeNull();
});

// ─── Idempotência ───────────────────────────────────────────────────────────

it('nao manda a carta duas vezes quando o KYC e aprovado de novo', function () {
    Mail::fake();

    $verification = welPerformerVerification();

    welApprove($verification);
    welApprove($verification->fresh());

    Mail::assertSent(WelcomeFounderEmail::class, 1);
});

it('nao remanda a carta quando o job e reexecutado', function () {
    Mail::fake();

    $verification = welPerformerVerification();
    welApprove($verification);

    // O retry da fila reentrega o MESMO job. Sem a trava, um erro transitório
    // de rede no Resend viraria uma segunda apresentação pessoal.
    (new SendWelcomeEmail($verification->user->fresh()))->handle();

    Mail::assertSent(WelcomeFounderEmail::class, 1);
});

it('preserva o carimbo original em vez de reescrever', function () {
    Mail::fake();

    $verification = welPerformerVerification();
    welApprove($verification);

    $first = $verification->user->fresh()->welcome_email_sent_at;

    $this->travel(1)->hour();
    (new SendWelcomeEmail($verification->user->fresh()))->handle();

    // O carimbo diz QUANDO a carta saiu; reescrevê-lo num no-op apagaria a
    // única evidência da data do envio real.
    expect($verification->user->fresh()->welcome_email_sent_at->timestamp)->toBe($first->timestamp);
});

it('nao é escrivel por mass assignment', function () {
    $user = User::factory()->create(['role' => 'consumer']);

    // Mesma regra de discrete_mode e do 2FA: trava interna não entra por
    // payload. Um `update()` com a coluna no array tem que ser ignorado —
    // senão bastaria postá-la para nunca receber a carta (ou para reenviá-la).
    $user->update(['welcome_email_sent_at' => now()]);

    expect($user->fresh()->welcome_email_sent_at)->toBeNull();
});

// ─── Quem não recebe ────────────────────────────────────────────────────────

it('nao manda a carta para admin', function () {
    Mail::fake();

    $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

    (new SendWelcomeEmail($admin))->handle();

    Mail::assertNothingSent();
    expect($admin->fresh()->welcome_email_sent_at)->toBeNull();
});

it('nao quebra quando a conta sumiu entre o dispatch e o job', function () {
    Mail::fake();

    $user = User::factory()->create(['role' => 'consumer']);
    $job = new SendWelcomeEmail($user);

    $user->forceDelete();

    // A fila é assíncrona em produção: entre o dispatch e o handle a conta pode
    // ter sido encerrada. O job não pode explodir e entupir a fila de retries.
    $job->handle();

    Mail::assertNothingSent();
});

// ─── PRIVACIDADE: o envelope não denuncia a plataforma ──────────────────────

it('nao usa termo sensivel no assunto nem no remetente', function () {
    $user = User::factory()->create(['role' => 'consumer', 'name' => 'Carlos Lima']);
    $mail = new WelcomeFounderEmail($user);
    $envelope = $mail->envelope();

    // Envelope é o que aparece na LISTA da caixa de entrada, na notificação da
    // tela bloqueada e no e-mail corporativo de alguém. O corpo pode ser
    // pessoal; isto aqui, não. Decisão do PO.
    $forbidden = [
        'adulto', 'adult', 'sexo', 'sexual', 'sensual', 'erotico', 'erótico',
        'xxx', 'porn', 'nude', 'nudez', '+18', '18+', 'acompanhante', 'escort',
        'fetiche', 'putaria', 'safad',
        // Processo também vaza: "verificação aprovada" na tela bloqueada conta
        // que houve envio de documento a alguma plataforma.
        'kyc', 'verificac', 'verificaç', 'selfie', 'documento',
    ];

    $envelopeText = Str::lower($envelope->subject.' '.WelcomeFounderEmail::FROM_NAME);

    foreach ($forbidden as $term) {
        expect($envelopeText)->not->toContain($term);
    }

    expect($envelope->subject)->toBe('Bem-vindo ao Limen');
});

it('assina com o nome dos fundadores no endereco configurado', function () {
    $user = User::factory()->create(['role' => 'consumer']);
    $envelope = (new WelcomeFounderEmail($user))->envelope();

    // Endereço do config: SPF/DKIM já cobrem este domínio. Um remetente novo,
    // sem histórico de envio, cairia em spam — e a carta que ninguém lê não
    // cumpre a função nenhuma.
    expect($envelope->from->address)->toBe(config('mail.from.address'))
        ->and($envelope->from->name)->toBe('Robson & Bruno');
});

it('mantem o preheader neutro', function () {
    $user = User::factory()->create(['role' => 'consumer', 'name' => 'Carlos Lima']);

    $html = (new WelcomeFounderEmail($user))->render();

    // O preheader é o trecho que o cliente mostra ao LADO do assunto — é
    // envelope, não corpo, e vale a mesma regra.
    expect($html)->toContain('Uma palavra dos fundadores.');
});

// ─── Corpo ──────────────────────────────────────────────────────────────────

it('trata o destinatario pelo primeiro nome e assina pelos dois fundadores', function () {
    $user = User::factory()->create(['role' => 'consumer', 'name' => 'Carlos Eduardo Lima']);

    $html = (new WelcomeFounderEmail($user))->render();

    expect($html)->toContain('Olá, Carlos.')
        ->and($html)->not->toContain('Carlos Eduardo Lima')
        ->and($html)->toContain('Robson')
        ->and($html)->toContain('Bruno')
        ->and($html)->toContain('Fundadores do Limen');
});

it('nao carrega imagem remota', function () {
    $user = User::factory()->create(['role' => 'consumer']);

    $html = (new WelcomeFounderEmail($user))->render();

    // <img> remoto em e-mail é pixel de leitura: diz quando e de onde a pessoa
    // abriu. A auditoria de 20/07 já tirou um do header do vendor
    // (docs/PIXEL_AUDIT.md, item 5). Carta de fundador não rastreia leitor.
    expect($html)->not->toContain('<img');
});

it('manda a mesma carta para membro e performer', function () {
    $member = User::factory()->create(['role' => 'consumer', 'name' => 'Carlos Lima']);
    $performer = User::factory()->create(['role' => 'performer', 'name' => 'Carlos Lima']);

    $memberHtml = (new WelcomeFounderEmail($member))->render();
    $performerHtml = (new WelcomeFounderEmail($performer))->render();

    // Texto e CTA únicos, por decisão do PO: a carta é dos fundadores, não um
    // onboarding por papel. Com o mesmo primeiro nome dos dois lados, o HTML
    // tem que sair IDÊNTICO — é o que impede uma variação por papel de voltar
    // sem ninguém notar.
    expect($memberHtml)->toBe($performerHtml)
        ->and($memberHtml)->toContain('Explore o catálogo e descubra o que preparamos para você.')
        ->and($memberHtml)->toContain(route('catalog'))
        ->and($memberHtml)->not->toContain(route('performer.dashboard'));
});
