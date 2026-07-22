<?php

use App\Exceptions\ChatException;
use App\Services\InterestService;
use App\Support\ChatContentFilter as F;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Filtro de conteúdo do chat — duas categorias (Sprint 6, revisado).
 *
 * TIPO 1 (legal): intermediação de encontro pago e transação fora do ledger.
 * TIPO 2 (conduta): ameaça e insulto DIRECIONADO.
 *
 * Metade destes testes prova o que o filtro NÃO barra, e essa metade é a que
 * importa manter: troca de contato, palavrão consensual e encontro sem valor
 * são conversa legítima aqui, e a versão anterior do filtro barrava os três.
 * Helpers (chatPerformer, chatUnlockedPair, grantChatAccess) em tests/Pest.php.
 */
beforeEach(function () {
    Cache::flush();
});

// ─── O que passou a ser PERMITIDO (o ponto da revisão) ──────────────────────

it('permite troca de contato — é legítima numa plataforma de conteúdo adulto', function () {
    expect(F::blocks('me chama no whatsapp'))->toBeFalse()
        ->and(F::blocks('meu telefone é 11999999999'))->toBeFalse()
        ->and(F::blocks('me segue no instagram'))->toBeFalse()
        ->and(F::blocks('manda no zap'))->toBeFalse()
        ->and(F::blocks('qual seu endereço de email?'))->toBeFalse()
        ->and(F::blocks('comprei um fone de ouvido novo'))->toBeFalse()
        ->and(F::blocks('meu celular novo tira foto linda'))->toBeFalse();
});

it('permite palavrão em contexto sexual consentido', function () {
    // É uma plataforma adulta: este É o vocabulário do produto.
    expect(F::blocks('que puta gostosa'))->toBeFalse()
        ->and(F::blocks('sua puta safada'))->toBeFalse()
        ->and(F::blocks('puta merda, que linda'))->toBeFalse()
        ->and(F::blocks('tá puta comigo?'))->toBeFalse()
        ->and(F::blocks('vou te comer toda'))->toBeFalse()
        ->and(F::blocks('sua vaca deliciosa'))->toBeFalse();
});

it('permite combinar encontro SEM valor monetário', function () {
    // A plataforma não controla a vida pessoal de adultos.
    expect(F::blocks('vamos num motel qualquer dia'))->toBeFalse()
        ->and(F::blocks('fiquei num hotel em paris nas férias'))->toBeFalse()
        ->and(F::blocks('podemos nos encontrar quando você quiser'))->toBeFalse()
        ->and(F::blocks('meu curso é presencial'))->toBeFalse();
});

it('permite as palavras comuns que derrubavam conversa antes', function () {
    expect(F::blocks('me conta como foi seu dia'))->toBeFalse()
        ->and(F::blocks('qual seu programa favorito na tv?'))->toBeFalse()
        ->and(F::blocks('vamos fazer um programa juntos no domingo'))->toBeFalse()
        ->and(F::blocks('comprei tokens no pix agora'))->toBeFalse()
        ->and(F::blocks('quanto custa o pacote de tokens?'))->toBeFalse();
});

// ─── TIPO 1 — risco legal ───────────────────────────────────────────────────

it('bloqueia frase inequívoca de programa pago', function () {
    expect(F::categoryOf('faço programa completo'))->toBe(F::LEGAL)
        ->and(F::categoryOf('você faz programa?'))->toBe(F::LEGAL)
        ->and(F::categoryOf('oferece GFE?'))->toBe(F::LEGAL);
});

it('bloqueia transação fora da plataforma', function () {
    expect(F::categoryOf('faz o pix fora da plataforma'))->toBe(F::LEGAL)
        ->and(F::categoryOf('me paga fora, sai mais barato'))->toBe(F::LEGAL)
        ->and(F::categoryOf('prefiro receber por fora do site'))->toBe(F::LEGAL)
        ->and(F::categoryOf('transfere fora daqui'))->toBe(F::LEGAL);
});

it('bloqueia encontro APENAS quando há valor monetário junto', function () {
    // O mesmo termo, com e sem dinheiro: é a regra inteira num par.
    expect(F::blocks('vamos num motel'))->toBeFalse()
        ->and(F::categoryOf('vamos num motel, 300 reais'))->toBe(F::LEGAL);

    expect(F::blocks('quero te encontrar'))->toBeFalse()
        ->and(F::categoryOf('quero te encontrar, qual seu valor?'))->toBe(F::LEGAL);

    expect(F::blocks('qual seu programa favorito'))->toBeFalse()
        ->and(F::categoryOf('quanto custa um programa?'))->toBe(F::LEGAL);

    expect(F::categoryOf('encontro presencial R$ 500'))->toBe(F::LEGAL);
});

it('não confunde data e hora com valor', function () {
    // Um \d+ genérico como sinal de dinheiro barraria isto — por isso o sinal
    // tem que ser explicitamente monetário.
    expect(F::blocks('vamos nos encontrar dia 15'))->toBeFalse()
        ->and(F::blocks('te encontro às 20h'))->toBeFalse();
});

it('cachê sozinho não bloqueia — show na plataforma é conversa legítima', function () {
    expect(F::blocks('qual seu cachê para uma live?'))->toBeFalse();
});

// ─── TIPO 2 — conduta ───────────────────────────────────────────────────────

it('bloqueia ameaça explícita', function () {
    expect(F::categoryOf('vou te matar'))->toBe(F::CONDUCT)
        ->and(F::categoryOf('te processo se não responder'))->toBe(F::CONDUCT)
        ->and(F::categoryOf('sei onde você mora'))->toBe(F::CONDUCT);
});

it('bloqueia sextorsão', function () {
    // O vetor de ameaça mais próprio desta plataforma.
    expect(F::categoryOf('vou vazar suas fotos'))->toBe(F::CONDUCT)
        ->and(F::categoryOf('vou mostrar pro seu marido'))->toBe(F::CONDUCT)
        ->and(F::categoryOf('vou te expor'))->toBe(F::CONDUCT);
});

it('não barra "vou te" seguido de coisa normal', function () {
    // 'vou te' veio na spec como padrão de ameaça; barrá-lo solto mataria o
    // produto funcionando.
    expect(F::blocks('vou te ligar depois'))->toBeFalse()
        ->and(F::blocks('vou te mandar uma foto'))->toBeFalse()
        ->and(F::blocks('vou te comer inteira'))->toBeFalse();
});

it('bloqueia insulto DIRECIONADO', function () {
    expect(F::categoryOf('sua puta nojenta'))->toBe(F::CONDUCT)
        ->and(F::categoryOf('você é uma vaca'))->toBe(F::CONDUCT)
        ->and(F::categoryOf('vc é uma idiota'))->toBe(F::CONDUCT);
});

it('o qualificador consensual desarma o insulto direcionado', function () {
    // "sua puta safada" é dirty talk; "sua puta nojenta" não é. A diferença
    // está no qualificador, não na palavra.
    expect(F::blocks('sua puta safada'))->toBeFalse()
        ->and(F::categoryOf('sua puta nojenta'))->toBe(F::CONDUCT);
});

it('risco legal vence conduta quando a mensagem dispara as duas', function () {
    // A categoria mais grave é a que deve ser reportada.
    expect(F::categoryOf('sua puta nojenta, faz programa completo?'))->toBe(F::LEGAL);
});

// ─── Bypasses fechados na revisão de segurança ──────────────────────────────

it('fecha o bypass de zero-width e fullwidth', function () {
    // Os dois achados da revisão: o ZWSP virava espaço real (quebrando a
    // palavra) e o fullwidth era DESCARTADO pelo Str::ascii (a mensagem
    // inteira normalizava para vazio e não casava nada).
    expect(F::blocks("faz progr\u{200B}ama completo"))->toBeTrue()
        ->and(F::blocks('ｆａｚ ｐｒｏｇｒａｍａ ｃｏｍｐｌｅｔｏ'))->toBeTrue();
});

it('mantém acento, leet, alongamento e caixa cobertos', function () {
    expect(F::blocks('FAZ PROGRAMA COMPLETO'))->toBeTrue()
        ->and(F::blocks('faz pr0gr4m4 completo'))->toBeTrue()
        ->and(F::blocks('faz programaaaa completo'))->toBeTrue()
        ->and(F::categoryOf('cachê de R$ 500 pro programa'))->toBe(F::LEGAL);
});

it('respeita o desligamento por config', function () {
    config(['chat_filters.enabled' => false]);

    expect(F::blocks('faz programa completo'))->toBeFalse();
});

// ─── Resposta HTTP ──────────────────────────────────────────────────────────

it('devolve 422 com a mensagem de risco legal', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 50);
    grantChatAccess($member, $conversation);

    $this->actingAs($member)
        ->postJson(route('chat.messages.store', $conversation->id), [
            'body' => 'faz programa completo?',
        ])
        ->assertStatus(422)
        ->assertJson([
            'reason' => ChatException::CONTENT_BLOCKED,
            'message' => 'Esta mensagem não é permitida pois sugere transação fora da plataforma '
                .'ou encontro mediante pagamento, o que viola os Termos de Uso.',
        ]);

    expect($conversation->messages()->count())->toBe(0);
});

it('devolve 422 com a mensagem de conduta', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 50);
    grantChatAccess($member, $conversation);

    $this->actingAs($member)
        ->postJson(route('chat.messages.store', $conversation->id), ['body' => 'vou te matar'])
        ->assertStatus(422)
        ->assertJson([
            'reason' => ChatException::CONDUCT_BLOCKED,
            'message' => 'Esta mensagem foi bloqueada por violar nossa política de conduta.',
        ]);
});

it('entrega a mensagem normal (201)', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 50);
    grantChatAccess($member, $conversation);

    $this->actingAs($member)
        ->postJson(route('chat.messages.store', $conversation->id), [
            'body' => 'oi gata, me chama no whatsapp que a gente conversa melhor',
        ])
        ->assertStatus(201);

    expect($conversation->messages()->count())->toBe(1);
});

it('a resposta não revela a regra que casou', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 50);
    grantChatAccess($member, $conversation);

    $response = $this->actingAs($member)
        ->postJson(route('chat.messages.store', $conversation->id), ['body' => 'faz programa completo'])
        ->assertStatus(422);

    // A mensagem diz a CATEGORIA violada (é o que o usuário de boa-fé precisa),
    // nunca o termo — esse continua só no audit, em HMAC.
    expect($response->getContent())->not->toContain('rule')
        ->and($response->getContent())->not->toContain('programa completo');
});

// ─── Audit ──────────────────────────────────────────────────────────────────

it('registra categoria e flag de moderação sem o corpo da mensagem', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 50);
    grantChatAccess($member, $conversation);

    $this->actingAs($member)
        ->postJson(route('chat.messages.store', $conversation->id), [
            'body' => 'sua puta nojenta, me manda 11999999999',
        ])->assertStatus(422);

    $log = DB::table('audit_logs')->where('action', 'chat.message_blocked')->sole();
    $metadata = json_decode((string) $log->metadata, true);

    expect($log->user_id)->toBe($member->id)
        ->and($metadata['category'])->toBe(F::CONDUCT)
        // Só conduta vai para a fila humana; risco legal é barrado e contado.
        ->and($metadata['flagged_for_review'])->toBeTrue()
        // 'puta' vem antes de 'nojenta' na lista, então é ele quem casa — o
        // qualificador 'nojenta' é o que deixou de desarmar, não o que casou.
        ->and($metadata['rule_hash'])->toBe(F::digest('direcionado: puta'))
        // Nem o corpo nem a regra em claro. audit_logs sobrevive ao Hard
        // Delete: copiar a mensagem para cá seria uma 2ª cópia do conteúdo
        // privado do chat, fora do soft-delete do LGPD em `messages`.
        ->and($metadata)->not->toHaveKey('body')
        ->and((string) $log->metadata)->not->toContain('nojenta')
        ->and((string) $log->metadata)->not->toContain('11999999999');
});

it('risco legal NÃO é marcado para moderação', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 50);
    grantChatAccess($member, $conversation);

    $this->actingAs($member)
        ->postJson(route('chat.messages.store', $conversation->id), ['body' => 'faz programa completo'])
        ->assertStatus(422);

    $metadata = json_decode(
        (string) DB::table('audit_logs')->where('action', 'chat.message_blocked')->sole()->metadata,
        true,
    );

    expect($metadata['category'])->toBe(F::LEGAL)
        ->and($metadata['flagged_for_review'])->toBeFalse();
});

it('deduplica o audit por usuário e regra — enumerar a lista não enterra a trilha', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 50);
    grantChatAccess($member, $conversation);

    for ($i = 0; $i < 5; $i++) {
        $this->actingAs($member)
            ->postJson(route('chat.messages.store', $conversation->id), ['body' => 'faz programa completo'])
            ->assertStatus(422);
    }

    expect(DB::table('audit_logs')->where('action', 'chat.message_blocked')->count())->toBe(1);
});

it('o digest da regra não é reversível por tabela', function () {
    // A lista está no repo: sha256 puro seria revertido em segundos.
    expect(F::digest('programa completo'))->not->toBe(hash('sha256', 'programa completo'));
});

// ─── O que o filtro NÃO pode virar: oráculo do opt-out ──────────────────────

it('a performer recebe a MESMA resposta para membro com e sem opt-out', function () {
    $performer = chatPerformer();

    [, , $interestNormal] = chatUnlockedPair($performer, balance: 50);
    [$optedOut, , $interestOptedOut] = chatUnlockedPair($performer, balance: 50);

    app(InterestService::class)->setOptOut($optedOut, true);

    $blocked = ['body' => 'faz programa completo'];

    $a = $this->actingAs($performer->user)
        ->postJson(route('chat.performer.start', $interestNormal->id), $blocked);

    $b = $this->actingAs($performer->user)
        ->postJson(route('chat.performer.start', $interestOptedOut->id), $blocked);

    // Se o filtro rodasse DEPOIS da máscara, o suprimido daria 202 e o normal
    // 422 — o par viraria oráculo do opt-out (INTEREST_ANONYMITY_FLOOR.md).
    expect($a->status())->toBe(422)
        ->and($b->status())->toBe($a->status())
        ->and($b->json('reason'))->toBe($a->json('reason'));
});
