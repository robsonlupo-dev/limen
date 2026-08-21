<?php

use App\Models\ContentUnlock;
use App\Models\PerformerContent;
use App\Models\PerformerProfile;
use App\Models\Report;
use App\Models\Subscription;
use App\Models\TokenLedger;
use App\Models\TokenWallet;
use App\Models\User;
use App\Services\ContentUnlockService;
use App\Services\ContentVisibilityService;
use App\Services\DeletionService;
use App\Services\PerformerContentService;
use App\Services\TokenService;
use App\Support\FanAlias;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Conteúdo permanente pago (PR #135, M.4/M.13.13). Helpers locais (pc*) para o
 * arquivo rodar isolado. Revisão de segurança do plano e do código rodada.
 */
function pcPerformer(): PerformerProfile
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);

    return $user->performerProfile()->create([
        'stage_name' => 'Ana '.Str::random(6),
        'slug' => 'ana-'.strtolower(Str::random(6)),
        'category' => 'mulheres',
        'is_verified' => true,
    ]);
}

function pcMember(?string $tier = null): User
{
    $user = User::factory()->create(['role' => 'consumer', 'status' => 'active']);

    if ($tier !== null) {
        Subscription::factory()->for($user)->circle($tier)->create();
        $user->refresh();
    }

    return $user;
}

function pcFund(User $user, int $tokens): void
{
    app(TokenService::class)->credit($user, $tokens, 'purchase');
}

function pcPublish(PerformerProfile $profile, string $level, int $price = 20): PerformerContent
{
    return app(PerformerContentService::class)->publish(
        $profile,
        UploadedFile::fake()->image('c.jpg', 800, 600),
        $level,
        $price,
    );
}

function pcVis(): ContentVisibilityService
{
    return app(ContentVisibilityService::class);
}

function pcUnlock(User $member, PerformerContent $content): ContentUnlock
{
    return app(ContentUnlockService::class)->unlock($member, $content);
}

function pcBalance(User $user): int
{
    return app(TokenService::class)->balance($user);
}

// ─── Gate de tier por nível (M.13.13) ────────────────────────────────────────

it('premium virou compra avulsa — qualquer membro compra pagando o preço cheio (21/08/2026)', function () {
    $profile = pcPerformer();
    $content = pcPublish($profile, PerformerContent::LEVEL_PREMIUM);

    $free = pcMember();               // sem assinatura
    $insider = pcMember('insider');   // era "abaixo de Prestige"

    // Tier alcança para TODOS (premium = compra avulsa), e o Free CONSEGUE comprar.
    // Não é upsell de tier (só Exclusivo/FC Only têm upsell).
    expect(pcVis()->tierAllows($free, $content))->toBeTrue()
        ->and(pcVis()->tierAllows($insider, $content))->toBeTrue()
        ->and(pcVis()->canUnlock($free, $content))->toBeTrue()
        ->and(pcVis()->upsellTierLabel($free, $content))->toBeNull();
});

it('exclusivo so desbloqueia a partir de Black', function () {
    $content = pcPublish(pcPerformer(), PerformerContent::LEVEL_EXCLUSIVE);

    expect(pcVis()->tierAllows(pcMember('prestige'), $content))->toBeFalse()
        ->and(pcVis()->tierAllows(pcMember('black'), $content))->toBeTrue()
        ->and(pcVis()->tierAllows(pcMember('founders_circle'), $content))->toBeTrue();
});

it('fc_only so desbloqueia FC', function () {
    $content = pcPublish(pcPerformer(), PerformerContent::LEVEL_FC_ONLY);

    expect(pcVis()->tierAllows(pcMember('black'), $content))->toBeFalse()
        ->and(pcVis()->tierAllows(pcMember('founders_circle'), $content))->toBeTrue();
});

it('nao-assinante cai no fail-closed dos niveis TRAVADOS por tier (Exclusivo/FC)', function () {
    // Premium virou avulso; o fail-closed agora é dos níveis que SEGUEM travados.
    $content = pcPublish(pcPerformer(), PerformerContent::LEVEL_EXCLUSIVE);
    $nonSub = pcMember();

    expect(pcVis()->tierAllows($nonSub, $content))->toBeFalse()
        ->and(pcVis()->canView($nonSub, $content))->toBeFalse()
        ->and(pcVis()->canUnlock($nonSub, $content))->toBeFalse()
        ->and(pcVis()->upsellTierLabel($nonSub, $content))->toBe('Black');
});

// ─── Aberto: não-assinante paga, assinante grátis (M.13.13) ──────────────────

it('conteudo aberto e gratis para assinante e pago para nao-assinante', function () {
    $content = pcPublish(pcPerformer(), PerformerContent::LEVEL_OPEN, 10);

    $sub = pcMember('explorador');
    $nonSub = pcMember();

    // Assinante: vê grátis, sem linha de unlock, sem cobrança.
    expect(pcVis()->isFreeFor($sub, $content))->toBeTrue()
        ->and(pcVis()->canView($sub, $content))->toBeTrue()
        ->and(pcVis()->canUnlock($sub, $content))->toBeFalse();

    // Não-assinante: não vê grátis, precisa pagar.
    expect(pcVis()->isFreeFor($nonSub, $content))->toBeFalse()
        ->and(pcVis()->canView($nonSub, $content))->toBeFalse()
        ->and(pcVis()->canUnlock($nonSub, $content))->toBeTrue();

    pcFund($nonSub, 50);
    pcUnlock($nonSub, $content);
    expect(pcVis()->canView($nonSub->refresh(), $content))->toBeTrue()
        ->and(pcBalance($nonSub))->toBe(40);
});

// ─── Split 80/20 + ledger + applied_rate (M.13.6/M.13.7) ─────────────────────

it('desbloqueio aplica split 80/20 via policy, com entry_types e applied_rate congelado', function () {
    $profile = pcPerformer();
    $content = pcPublish($profile, PerformerContent::LEVEL_PREMIUM, 20);
    $member = pcMember('prestige');
    pcFund($member, 100);

    pcUnlock($member, $content);

    // Membro debitado 20 (spend_content); performer creditada 16 (content_credit).
    expect(pcBalance($member))->toBe(80)
        ->and(pcBalance($profile->user))->toBe(16);

    $spend = TokenLedger::where('entry_type', 'spend_content')->latest('id')->first();
    $credit = TokenLedger::where('entry_type', 'content_credit')->latest('id')->first();

    expect($spend->amount)->toBe(-20)
        ->and($spend->reference_type)->toBe(PerformerContent::class)
        ->and($spend->reference_id)->toBe($content->id)
        ->and($credit->amount)->toBe(16)
        ->and($credit->applied_rate)->toBe(80); // congelado
});

it('a receita de conteudo conta como ganho sacavel no payout (M.13.5)', function () {
    $profile = pcPerformer();
    $content = pcPublish($profile, PerformerContent::LEVEL_PREMIUM, 20);
    $member = pcMember('prestige');
    pcFund($member, 100);

    pcUnlock($member, $content); // performer recebe 16 (content_credit)

    // O sweep/on-demand do payout enxerga os 16 como devidos (content_credit está
    // no allowlist de ganho). Sem isso, a receita de conteúdo não seria sacável.
    expect(app(App\Services\PayoutService::class)->earningsOwed($profile->user))->toBe(16);
});

// ─── Desbloqueio permanente (não expira com a assinatura) ────────────────────

it('desbloqueio sobrevive ao fim da assinatura', function () {
    $content = pcPublish(pcPerformer(), PerformerContent::LEVEL_PREMIUM, 20);
    $member = pcMember('prestige');
    pcFund($member, 100);

    pcUnlock($member, $content);
    expect(pcVis()->canView($member, $content))->toBeTrue();

    // Assinatura vence: o tier some, mas o desbloqueio PAGO permanece.
    Subscription::where('user_id', $member->id)->update(['status' => 'canceled', 'current_period_end' => now()->subDay()]);
    $member->refresh();

    expect($member->activeCircle())->toBeNull()
        ->and(pcVis()->hasUnlock($member, $content))->toBeTrue()
        ->and(pcVis()->canView($member, $content))->toBeTrue();
});

// ─── Piso 5 + passo 5 na PUBLICAÇÃO (M.4) ────────────────────────────────────

it('recusa preco abaixo do piso ou fora do passo de 5', function () {
    $profile = pcPerformer();

    expect(fn () => pcPublish($profile, PerformerContent::LEVEL_PREMIUM, 3))
        ->toThrow(App\Exceptions\ContentException::class);
    expect(fn () => pcPublish($profile, PerformerContent::LEVEL_PREMIUM, 7))
        ->toThrow(App\Exceptions\ContentException::class);

    // 5 e 25 passam.
    expect(pcPublish($profile, PerformerContent::LEVEL_PREMIUM, 5)->price_tokens)->toBe(5)
        ->and(pcPublish($profile, PerformerContent::LEVEL_PREMIUM, 25)->price_tokens)->toBe(25);
});

// ─── Idempotência / saldo / self-unlock ──────────────────────────────────────

it('desbloqueio e idempotente e nao cobra duas vezes', function () {
    $content = pcPublish(pcPerformer(), PerformerContent::LEVEL_PREMIUM, 20);
    $member = pcMember('prestige');
    pcFund($member, 100);

    $a = pcUnlock($member, $content);
    $b = pcUnlock($member, $content);

    expect($a->id)->toBe($b->id)
        ->and(pcBalance($member))->toBe(80) // cobrado UMA vez
        ->and(ContentUnlock::where('performer_content_id', $content->id)->count())->toBe(1);
});

it('saldo insuficiente barra o desbloqueio sem cobrar', function () {
    $content = pcPublish(pcPerformer(), PerformerContent::LEVEL_PREMIUM, 20);
    $member = pcMember('prestige');
    pcFund($member, 5);

    expect(fn () => pcUnlock($member, $content))->toThrow(App\Exceptions\InsufficientBalanceException::class);
    expect(pcBalance($member))->toBe(5)
        ->and(ContentUnlock::count())->toBe(0);
});

it('a propria performer nao desbloqueia (nem e cobrada) a propria peca', function () {
    $profile = pcPerformer();
    $content = pcPublish($profile, PerformerContent::LEVEL_PREMIUM, 20);
    $owner = $profile->user;
    pcFund($owner, 100);

    expect(fn () => pcUnlock($owner, $content))->toThrow(App\Exceptions\ContentException::class);
    expect(pcVis()->canView($owner, $content))->toBeTrue() // dona sempre vê
        ->and(pcBalance($owner))->toBe(100);
});

// ─── member_id não vaza; FC Only revela FC (M.13.10) ─────────────────────────

it('unlockers usam FanAlias e nunca vazam member_id; fc_only revela FC', function () {
    $profile = pcPerformer();
    $fcContent = pcPublish($profile, PerformerContent::LEVEL_FC_ONLY, 20);
    $premiumContent = pcPublish($profile, PerformerContent::LEVEL_PREMIUM, 20);

    $fc = pcMember('founders_circle');
    pcFund($fc, 100);
    pcUnlock($fc, $fcContent);
    pcUnlock($fc, $premiumContent);

    $svc = app(PerformerContentService::class);
    $fcUnlockers = $svc->unlockersFor($profile, $fcContent);
    $premUnlockers = $svc->unlockersFor($profile, $premiumContent);

    // FanAlias, nunca id do membro.
    expect($fcUnlockers[0])->toHaveKeys(['fan', 'member_handle'])
        ->and($fcUnlockers[0])->not->toHaveKey('user_id')
        ->and($fcUnlockers[0]['fan'])->toBe(FanAlias::label($profile->id, $fc->id))
        ->and(json_encode($fcUnlockers))->not->toContain((string) $fc->id);

    // fc_only revela FC; premium NÃO revela tier.
    expect($fcUnlockers[0]['tier_revealed'])->toBe('founders_circle')
        ->and($premUnlockers[0]['tier_revealed'])->toBeNull();
});

it('ContentUnlock esconde user_id na serializacao', function () {
    $content = pcPublish(pcPerformer(), PerformerContent::LEVEL_PREMIUM, 20);
    $member = pcMember('prestige');
    pcFund($member, 100);
    $unlock = pcUnlock($member, $content);

    expect($unlock->toArray())->not->toHaveKey('user_id');
});

// ─── Preview: bloqueado não recebe URL (blur ≠ paywall) ──────────────────────

it('presenter nao entrega image_url para quem nao alcanca', function () {
    $content = pcPublish(pcPerformer(), PerformerContent::LEVEL_PREMIUM, 20);
    $nonSub = pcMember();

    $locked = App\Support\ContentPresenter::one($content, $nonSub);
    expect($locked['locked'])->toBeTrue()
        ->and($locked['image_url'])->toBeNull()
        ->and($locked['price_tokens'])->toBe(20);

    $prestige = pcMember('prestige');
    pcFund($prestige, 100);
    pcUnlock($prestige, $content);
    $unlocked = App\Support\ContentPresenter::one($content->fresh(), $prestige);
    expect($unlocked['locked'])->toBeFalse()
        ->and($unlocked['image_url'])->not->toBeNull();
});

// ─── Serving por endpoint (404 sem acesso, 200 com) ──────────────────────────

it('serving nega 404 sem acesso e entrega os bytes com desbloqueio', function () {
    $content = pcPublish(pcPerformer(), PerformerContent::LEVEL_PREMIUM, 20);
    $member = pcMember('prestige');
    pcFund($member, 100);

    $this->actingAs($member)->get("/conteudo/{$content->id}/imagem")->assertNotFound();

    pcUnlock($member, $content);
    $this->actingAs($member)->get("/conteudo/{$content->id}/imagem")
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg');
});

it('endpoint de unlock: 403 para tier insuficiente, 200 no sucesso', function () {
    // Premium virou avulso; o tier gate agora só barra Exclusivo/FC Only.
    $content = pcPublish(pcPerformer(), PerformerContent::LEVEL_EXCLUSIVE, 20);

    $prestige = pcMember('prestige'); // abaixo de Black → barrado
    pcFund($prestige, 100);
    $this->actingAs($prestige)->postJson("/conteudo/{$content->id}/desbloquear")->assertStatus(403);

    $black = pcMember('black');
    pcFund($black, 100);
    $this->actingAs($black)->postJson("/conteudo/{$content->id}/desbloquear")->assertOk();
    expect(pcVis()->canView($black->refresh(), $content))->toBeTrue();
});

// ─── Serving para de entregar quando a performer sai do ar (B1) ──────────────

it('conteudo de performer suspensa para de ser servido mesmo para quem desbloqueou', function () {
    $profile = pcPerformer();
    $content = pcPublish($profile, PerformerContent::LEVEL_PREMIUM, 20);
    $member = pcMember('prestige');
    pcFund($member, 100);
    pcUnlock($member, $content);
    expect(pcVis()->canView($member, $content))->toBeTrue();

    // Moderação suspende a conta da performer (status fora do $fillable → forceFill).
    $profile->user->forceFill(['status' => 'suspended'])->save();
    $content->load('performerProfile.user');

    expect(pcVis()->canView($member->fresh(), $content))->toBeFalse();
});

// ─── Reportabilidade (princípio nº 1) ────────────────────────────────────────

it('quem desbloqueou pode denunciar; a peca fica denunciavel', function () {
    $content = pcPublish(pcPerformer(), PerformerContent::LEVEL_PREMIUM, 20);
    $member = pcMember('prestige');
    pcFund($member, 100);

    // Sem acesso: não é visível para denúncia.
    expect(Report::visibleTo($content, $member))->toBeFalse();

    pcUnlock($member, $content);
    expect(Report::visibleTo($content->fresh(), $member))->toBeTrue();
});

it('denuncia em aberto congela a remocao manual', function () {
    $profile = pcPerformer();
    $content = pcPublish($profile, PerformerContent::LEVEL_PREMIUM, 20);
    $reporter = pcMember('prestige');

    Report::open($reporter, $content, 'underage_content', 'x');

    expect(fn () => app(PerformerContentService::class)->remove($profile, $content))
        ->toThrow(App\Exceptions\ContentException::class);
    expect(PerformerContent::find($content->id))->not->toBeNull();
});

// ─── Hard Delete nos dois sentidos ───────────────────────────────────────────

it('hard delete leva desbloqueios do membro e peças da performer, preservando o ledger', function () {
    $profile = pcPerformer();
    $content = pcPublish($profile, PerformerContent::LEVEL_PREMIUM, 20);
    $member = pcMember('prestige');
    pcFund($member, 100);
    pcUnlock($member, $content);

    // Encerramento do MEMBRO: some o desbloqueio dele; o ledger fica.
    app(DeletionService::class)->executeDeletion($member->fresh());
    expect(ContentUnlock::where('user_id', $member->id)->count())->toBe(0)
        ->and(TokenLedger::where('entry_type', 'spend_content')->count())->toBe(1);

    // Encerramento da PERFORMER: some a peça (e cascata de unlocks).
    app(DeletionService::class)->executeDeletion($profile->user->fresh());
    expect(PerformerContent::where('performer_profile_id', $profile->id)->count())->toBe(0);
});
