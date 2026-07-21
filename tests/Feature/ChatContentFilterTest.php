<?php

use App\Exceptions\ChatException;
use App\Services\InterestService;
use App\Support\ChatContentFilter;
use Illuminate\Support\Facades\DB;

/**
 * Filtro de termos proibidos no chat (Sprint 6).
 *
 * Dois alvos: combinar encontro presencial e levar a transação para fora da
 * plataforma. Os helpers (chatPerformer, chatUnlockedPair, grantChatAccess)
 * vêm de ChatPhase1Test.php.
 *
 * O que estes testes NÃO provam: que o filtro impede alguém de combinar
 * encontro. Ver o cabeçalho de config/chat_filters.php — quem quer desviar,
 * desvia. Aqui se prova o mecanismo: barra o que está na lista, deixa passar o
 * resto, não conta ao remetente o que casou e não vira oráculo de opt-out.
 */

// ─── Bloqueio e liberação ───────────────────────────────────────────────────

it('bloqueia mensagem com termo proibido (422) e não persiste nada', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 50);
    grantChatAccess($member, $conversation);

    $this->actingAs($member)
        ->postJson(route('chat.messages.store', $conversation->id), [
            'body' => 'vamos marcar num motel amanhã',
        ])
        ->assertStatus(422)
        ->assertJson([
            'reason' => ChatException::CONTENT_BLOCKED,
            'message' => 'Mensagem não permitida pela política de uso da plataforma.',
        ]);

    expect($conversation->messages()->count())->toBe(0);
});

it('permite mensagem normal (201)', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 50);
    grantChatAccess($member, $conversation);

    $this->actingAs($member)
        ->postJson(route('chat.messages.store', $conversation->id), [
            'body' => 'oi, gostei muito do seu perfil',
        ])
        ->assertStatus(201);

    expect($conversation->messages()->count())->toBe(1);
});

it('o filtro é case-insensitive', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 50);
    grantChatAccess($member, $conversation);

    $this->actingAs($member)
        ->postJson(route('chat.messages.store', $conversation->id), ['body' => 'MEU WHATSAPP É'])
        ->assertStatus(422);

    $this->actingAs($member)
        ->postJson(route('chat.messages.store', $conversation->id), ['body' => 'MeU WhAtSaPp'])
        ->assertStatus(422);
});

it('a resposta não revela qual termo casou', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 50);
    grantChatAccess($member, $conversation);

    $response = $this->actingAs($member)
        ->postJson(route('chat.messages.store', $conversation->id), [
            'body' => 'me chama no whatsapp',
        ])->assertStatus(422);

    // Dizer a palavra entrega o mapa da evasão: a pessoa reescreve trocando só
    // aquela e o filtro para de ver qualquer coisa.
    expect($response->getContent())->not->toContain('whatsapp')
        ->and($response->getContent())->not->toContain('term');
});

// ─── Normalização (desvio preguiçoso) ───────────────────────────────────────

it('normaliza acento, leet e alongamento', function () {
    expect(ChatContentFilter::blocks('qual seu endereço?'))->toBeTrue()
        ->and(ChatContentFilter::blocks('qual seu endereco?'))->toBeTrue()
        ->and(ChatContentFilter::blocks('me chama no wh4ts4pp'))->toBeTrue()
        ->and(ChatContentFilter::blocks('manda no zaaaap'))->toBeTrue();
});

it('a fronteira de palavra evita o falso positivo óbvio', function () {
    // 'fone' está na lista, mas não pode casar dentro de 'telefone'... e
    // 'telefone' TAMBÉM está na lista, então a mensagem cai de qualquer forma.
    // O que importa provar é que a fronteira funciona em palavra inocente:
    expect(ChatContentFilter::blocks('comprei um fone de ouvido novo'))->toBeTrue();
    expect(ChatContentFilter::blocks('adoro zapping de canal'))->toBeFalse();
    expect(ChatContentFilter::blocks('vou ao hotelaria curso'))->toBeFalse();
});

it('deixa passar as palavras comuns que a lista deliberadamente NÃO barra', function () {
    // 'conta', 'banco', 'encontro' e 'transferência' ficaram fora da lista
    // (config/chat_filters.php, bloco AMBÍGUOS). Este teste é o guarda dessa
    // decisão: religá-los quebra aqui, com o custo à vista, em vez de sair
    // barrando conversa cotidiana em produção sem ninguém perceber.
    expect(ChatContentFilter::blocks('me conta como foi seu dia'))->toBeFalse()
        ->and(ChatContentFilter::blocks('conta comigo pro que precisar'))->toBeFalse()
        ->and(ChatContentFilter::blocks('eu te encontro no chat amanhã'))->toBeFalse()
        ->and(ChatContentFilter::blocks('fica por minha conta'))->toBeFalse()
        ->and(ChatContentFilter::blocks('sentei no banco da praça'))->toBeFalse();
});

it('barra a FRASE que carrega o sinal de verdade', function () {
    expect(ChatContentFilter::blocks('me passa sua conta bancária'))->toBeTrue()
        ->and(ChatContentFilter::blocks('faz o pix fora da plataforma'))->toBeTrue()
        ->and(ChatContentFilter::blocks('prefiro transferência bancária'))->toBeTrue()
        // 'pix' sozinho é o meio de pagamento da PRÓPRIA plataforma: barrá-lo
        // quebraria a conversa sobre a compra legítima de tokens.
        ->and(ChatContentFilter::blocks('comprei tokens no pix agora'))->toBeFalse();
});

it('respeita o desligamento por config', function () {
    config(['chat_filters.enabled' => false]);

    expect(ChatContentFilter::blocks('vamos num motel'))->toBeFalse();
});

// ─── Audit ──────────────────────────────────────────────────────────────────

it('registra o bloqueio sem expor o termo nem o corpo', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 50);
    grantChatAccess($member, $conversation);

    $this->actingAs($member)
        ->postJson(route('chat.messages.store', $conversation->id), [
            'body' => 'meu whatsapp é 11999999999, me chama',
        ])->assertStatus(422);

    $log = DB::table('audit_logs')->where('action', 'chat.message_blocked')->first();

    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBe($member->id);

    $metadata = json_decode((string) $log->metadata, true);

    // O termo confere contra o digest (a operação consegue calibrar a lista)...
    expect($metadata['term_hash'])->toBe(ChatContentFilter::digest('whatsapp'))
        // ...mas nem o termo nem o CORPO ficam legíveis. audit_logs é lido por
        // admin e sobrevive ao Hard Delete: copiar a mensagem para cá criaria
        // uma segunda cópia do conteúdo do chat fora do soft-delete do LGPD.
        ->and($metadata)->not->toHaveKey('body')
        ->and((string) $log->metadata)->not->toContain('whatsapp')
        ->and((string) $log->metadata)->not->toContain('11999999999');
});

it('o digest do termo não é reversível por tabela', function () {
    // A lista de termos está no repo: um sha256 puro seria revertido em
    // segundos. HMAC com a APP_KEY fecha isso — ver ClientFingerprint.
    expect(ChatContentFilter::digest('motel'))
        ->not->toBe(hash('sha256', 'motel'));
});

// ─── O que o filtro NÃO pode virar: oráculo do opt-out ──────────────────────

it('a performer recebe a MESMA resposta para membro com e sem opt-out', function () {
    $performer = chatPerformer();

    [$normal, , $interestNormal] = chatUnlockedPair($performer, balance: 50);
    [$optedOut, , $interestOptedOut] = chatUnlockedPair($performer, balance: 50);

    // Membro sai do Interesse: os envios da performer passam a ser mascarados
    // (202 sem entregar nada) para não vazar o opt-out.
    app(InterestService::class)->setOptOut($optedOut, true);

    $blocked = ['body' => 'me chama no whatsapp'];

    $a = $this->actingAs($performer->user)
        ->postJson(route('chat.performer.start', $interestNormal->id), $blocked);

    $b = $this->actingAs($performer->user)
        ->postJson(route('chat.performer.start', $interestOptedOut->id), $blocked);

    // Se o filtro rodasse DEPOIS da máscara, o suprimido devolveria 202 e o
    // normal 422 — e o par de respostas viraria oráculo do opt-out, que é o
    // que INTEREST_ANONYMITY_FLOOR.md proíbe. Filtrando antes, o termo barrado
    // devolve 422 para os dois: a resposta depende só do texto que a própria
    // performer escreveu.
    expect($a->status())->toBe(422)
        ->and($b->status())->toBe($a->status())
        ->and($b->json('reason'))->toBe($a->json('reason'));
});

it('a performer também é filtrada no envio pela linha de Interesse', function () {
    $performer = chatPerformer();
    [, , $interest] = chatUnlockedPair($performer, balance: 50);

    $this->actingAs($performer->user)
        ->postJson(route('chat.performer.start', $interest->id), ['body' => 'vamos num motel'])
        ->assertStatus(422)
        ->assertJson(['reason' => ChatException::CONTENT_BLOCKED]);
});
