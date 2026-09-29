<?php

use App\Exceptions\ChatException;
use App\Exceptions\InsufficientBalanceException;
use App\Models\ContentUnlock;
use App\Models\PerformerContent;
use App\Models\PerformerProfile;
use App\Services\ChatService;
use App\Services\ContentVisibilityService;
use App\Services\PerformerContentService;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * PPV no chat — roadmap social, Onda 4 (§4.1). A performer manda uma peça do cofre
 * TRAVADA com preço; o membro paga para desbloquear (débito + crédito 80/20 + a MESMA
 * linha content_unlocks do cofre). Eixos: (1) dinheiro correto e idempotente; (2) só a
 * performer dona envia, só peça dela, preço válido; (3) só participante desbloqueia, a
 * dona não paga a própria; (4) mídia nunca vaza travada; (5) PPV é venda dirigida —
 * ignora o gate de tier. Prefixo `pv`.
 */
function pvContent(PerformerProfile $p, string $level = 'open', int $price = 20): PerformerContent
{
    return app(PerformerContentService::class)->publish(
        $p,
        UploadedFile::fake()->image('c.jpg', 640, 480),
        $level,
        $price,
    );
}

/** @return array{0: PerformerProfile, 1: \App\Models\User, 2: \App\Models\Conversation} */
function pvPair(): array
{
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer);

    return [$performer, $member, $conversation];
}

// ─── Dinheiro: cobra o membro, credita 80% à performer, cria o unlock ──────────────

it('desbloqueia PPV: cobra o membro, credita 80% à performer e grava o unlock', function () {
    [$performer, $member, $conversation] = pvPair();
    $performerUser = $performer->user;
    $content = pvContent($performer, 'open', 20);
    $chat = app(ChatService::class);
    $tokens = app(TokenService::class);

    $msg = $chat->sendPpvMessage($conversation, $performerUser, $content, 40);
    expect($msg->isPpv())->toBeTrue()
        ->and((int) $msg->ppv_price_tokens)->toBe(40);

    $tokens->credit($member, 200, 'purchase');
    $mBefore = $tokens->balance($member);

    $unlock = $chat->unlockPpvMessage($conversation, $member, $msg);

    expect($unlock)->toBeInstanceOf(ContentUnlock::class)
        ->and((int) $unlock->tokens_paid)->toBe(40)
        // Membro debitado exatamente o preço (saldo do membro é inteiro).
        ->and($tokens->balance($member))->toBe($mBefore - 40)
        // O membro passa a ver a peça (mesma linha do cofre).
        ->and(app(ContentVisibilityService::class)->hasUnlock($member, $content))->toBeTrue();

    // Crédito 80/20 da performer: 80% de 40 = 32, applied_rate congelado em 80.
    $walletId = DB::table('token_wallets')->where('user_id', $performerUser->id)->value('id');
    $credit = DB::table('token_ledger')
        ->where('wallet_id', $walletId)
        ->where('entry_type', 'ppv_message_credit')
        ->first();
    expect($credit)->not->toBeNull()
        ->and((float) $credit->amount)->toBe(32.0)
        ->and((int) $credit->applied_rate)->toBe(80);
});

it('não cobra duas vezes: desbloqueio é idempotente', function () {
    [$performer, $member, $conversation] = pvPair();
    $content = pvContent($performer, 'open', 20);
    $chat = app(ChatService::class);
    $tokens = app(TokenService::class);

    $msg = $chat->sendPpvMessage($conversation, $performer->user, $content, 30);
    $tokens->credit($member, 200, 'purchase');

    $first = $chat->unlockPpvMessage($conversation, $member, $msg);
    $afterFirst = $tokens->balance($member);

    $second = $chat->unlockPpvMessage($conversation, $member, $msg);

    expect($second->id)->toBe($first->id)
        ->and($tokens->balance($member))->toBe($afterFirst) // sem 2ª cobrança
        ->and(ContentUnlock::where('performer_content_id', $content->id)->where('user_id', $member->id)->count())->toBe(1);
});

it('recusa sem saldo: não cobra nem cria unlock', function () {
    [$performer, $member, $conversation] = pvPair();
    $content = pvContent($performer, 'open', 20);
    $chat = app(ChatService::class);
    $tokens = app(TokenService::class);

    // Preço no teto — o membro do par nunca tem tanto.
    $msg = $chat->sendPpvMessage($conversation, $performer->user, $content, 5000);
    $mBefore = $tokens->balance($member);

    expect(fn () => $chat->unlockPpvMessage($conversation, $member, $msg))
        ->toThrow(InsufficientBalanceException::class);

    expect($tokens->balance($member))->toBe($mBefore)
        ->and(ContentUnlock::where('performer_content_id', $content->id)->where('user_id', $member->id)->exists())->toBeFalse();
});

// ─── Envio: só a performer dona, só peça dela, preço válido ────────────────────────

it('só a performer dona envia PPV (o membro não vende)', function () {
    [$performer, $member, $conversation] = pvPair();
    $content = pvContent($performer, 'open', 20);

    expect(fn () => app(ChatService::class)->sendPpvMessage($conversation, $member, $content, 40))
        ->toThrow(ChatException::class);
});

it('a peça precisa ser da performer da conversa', function () {
    [$performer, , $conversation] = pvPair();
    $other = chatPerformer();
    $otherContent = pvContent($other, 'open', 20);

    expect(fn () => app(ChatService::class)->sendPpvMessage($conversation, $performer->user, $otherContent, 40))
        ->toThrow(ChatException::class);
});

it('rejeita preço fora do piso/passo/teto', function () {
    [$performer, , $conversation] = pvPair();
    $content = pvContent($performer, 'open', 20);
    $chat = app(ChatService::class);

    expect(fn () => $chat->sendPpvMessage($conversation, $performer->user, $content, 7))->toThrow(ChatException::class); // não múltiplo de 5
    expect(fn () => $chat->sendPpvMessage($conversation, $performer->user, $content, 3))->toThrow(ChatException::class); // abaixo do piso
    expect(fn () => $chat->sendPpvMessage($conversation, $performer->user, $content, 999999))->toThrow(ChatException::class); // acima do teto
});

// ─── Desbloqueio: só participante; a dona não paga a própria peça ──────────────────

it('endpoint: não-participante leva 404 ao desbloquear', function () {
    [$performer, , $conversation] = pvPair();
    $content = pvContent($performer, 'open', 20);
    $msg = app(ChatService::class)->sendPpvMessage($conversation, $performer->user, $content, 40);

    $outsider = chatMember(200);

    $this->actingAs($outsider)
        ->postJson(route('chat.ppv.unlock', [$conversation->id, $msg->id]))
        ->assertNotFound();
});

it('a performer não desbloqueia (paga) a própria peça', function () {
    [$performer, , $conversation] = pvPair();
    $content = pvContent($performer, 'open', 20);
    $msg = app(ChatService::class)->sendPpvMessage($conversation, $performer->user, $content, 40);

    expect(fn () => app(ChatService::class)->unlockPpvMessage($conversation, $performer->user, $msg))
        ->toThrow(ChatException::class);
});

// ─── Mídia nunca vaza travada; aparece após o desbloqueio ─────────────────────────

it('show: membro travado vê preview sem mídia; após desbloquear, vê a mídia', function () {
    [$performer, $member, $conversation] = pvPair();
    $content = pvContent($performer, 'open', 20);
    $chat = app(ChatService::class);

    // Fundos ANTES de abrir o acesso (openOrRenew cobra a janela) — senão o débito da
    // abertura estoura sem saldo. Depois o membro tem saldo para ler e desbloquear.
    app(TokenService::class)->credit($member, 200, 'purchase');
    // Acesso ao chat para o membro poder LER o thread (senão show devolve vazio).
    grantChatAccess($member, $conversation);

    $msg = $chat->sendPpvMessage($conversation, $performer->user, $content, 40);

    $locked = $this->actingAs($member)->get(route('chat.show', $conversation->id));
    $row = collect($locked->viewData('page')['props']['messages']['data'])->firstWhere('ppv', '!==', null);
    expect($row)->not->toBeNull()
        ->and($row['ppv']['state'])->toBe('locked')
        ->and($row['ppv']['media_url'])->toBeNull()
        ->and($row['ppv']['preview_url'])->not->toBeNull()
        ->and((int) $row['ppv']['price'])->toBe(40);

    // A mídia real (rota do conteúdo) nunca aparece no payload enquanto travado.
    expect(json_encode($locked->viewData('page')['props']['messages']['data']))
        ->not->toContain(route('content.image', $content->id));

    $chat->unlockPpvMessage($conversation, $member, $msg);

    $open = $this->actingAs($member)->get(route('chat.show', $conversation->id));
    $rowOpen = collect($open->viewData('page')['props']['messages']['data'])->firstWhere('ppv', '!==', null);
    expect($rowOpen['ppv']['state'])->toBe('unlocked')
        ->and($rowOpen['ppv']['media_url'])->not->toBeNull();
});

// ─── PPV é venda dirigida: ignora o gate de tier ──────────────────────────────────

it('PPV ignora o gate de tier: exclusivo é desbloqueável por não-Black', function () {
    [$performer, $member, $conversation] = pvPair();
    // Exclusivo normalmente exige Black+; via PPV a performer vende direto.
    $content = pvContent($performer, 'exclusive', 20);
    $chat = app(ChatService::class);
    app(TokenService::class)->credit($member, 200, 'purchase');

    $msg = $chat->sendPpvMessage($conversation, $performer->user, $content, 40);
    $unlock = $chat->unlockPpvMessage($conversation, $member, $msg);

    expect((int) $unlock->tokens_paid)->toBe(40)
        // Depois de comprar o PPV, o membro (sem Círculo) VÊ a peça exclusiva.
        ->and(app(ContentVisibilityService::class)->canView($member, $content))->toBeTrue();
});
