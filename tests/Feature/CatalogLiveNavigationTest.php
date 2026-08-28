<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * feat/catalog-live-navigation — o card do catálogo passa a levar SEMPRE ao PERFIL
 * (o corpo do card, ao vivo ou não); só o SELO "Ao vivo" entra na transmissão. A
 * prévia de hover ganhou atraso menor (intenção de ~200ms) e não roda em toque.
 *
 * A navegação do card é client-side (Inertia <Link>) — o projeto não tem Vitest,
 * então os invariantes de navegação são travados por FONTE (como
 * MicroInteractions/LiveBroadcastControls). O único ponto testável no servidor é que
 * o perfil de quem está ao vivo CARREGA os dados que o botão "Ao vivo — assistir"
 * usa (is_live + a flag da feature).
 */
function clnMember(): User
{
    return User::factory()->create([
        'role' => 'consumer',
        'status' => 'active',
        'email_verified_at' => now()->subDays(30),
        'created_at' => now()->subDays(30),
    ]);
}

function clnLivePerformer(): User
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);
    $user->performerProfile()->create([
        'stage_name' => 'Perf '.Str::random(8),
        'slug' => 'perf-'.strtolower(Str::random(6)),
        'category' => 'mulheres',
        'is_verified' => true,
        'level' => 'iniciante',
        'split_pct' => 65,
    ]);
    $user->performerProfile->forceFill(['is_live' => true])->save();

    return $user->fresh();
}

function clnCard(): string
{
    return file_get_contents(resource_path('js/Components/PerformerCard.vue'));
}

// ── Servidor: o perfil de quem está ao vivo carrega os dados do botão ─────────

it('o perfil de quem esta ao vivo carrega is_live + a flag da feature (botao de entrar)', function () {
    config(['features.live_enabled' => true]);
    $performer = clnLivePerformer();
    $member = clnMember();

    $this->actingAs($member)
        ->get(route('catalog.show', $performer->performerProfile->slug))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Catalog/Show')
            ->where('performer.is_live', true)
            ->where('features.live_enabled', true)
        );
});

it('o perfil de quem esta ao vivo tem o botao "Ao vivo — assistir" que leva a live', function () {
    $show = file_get_contents(resource_path('js/Pages/Catalog/Show.vue'));

    // Link para a transmissão, gateado por is_live + a flag, em destaque (min-h 44px).
    expect($show)
        ->toContain("route('live.show', performer.slug)")
        ->toContain('performer.is_live && features.live_enabled')
        ->toContain('Ao vivo — assistir');
});

// ── Card: o CORPO leva ao PERFIL, o SELO leva à LIVE ──────────────────────────

it('o corpo do card leva ao PERFIL (catalog.show), nunca direto a live', function () {
    $card = clnCard();

    // O link que cobre o card usa profileHref = catalog.show.
    expect($card)
        ->toContain("const profileHref = computed(() => route('catalog.show', props.performer.slug))")
        ->toContain(':href="profileHref"');

    // Não há mais interceptação de clique que entrava direto na live pelo corpo.
    expect($card)
        ->not->toContain('onImageClick')
        ->not->toContain("router.visit(route('live.show'");
});

it('o selo AO VIVO e um Link para a transmissao, com alvo >=44px', function () {
    $card = clnCard();

    expect($card)
        ->toContain("route('live.show', performer.slug)")
        ->toContain('v-if="showLive"')
        ->toContain('Entrar na transmissão ao vivo') // aria-label do selo
        ->toContain('min-h-[44px]');                  // alvo de toque confortável
});

// ── Prévia de hover: atraso menor, cancela ao sair, nunca em toque ────────────

it('a previa de hover tem atraso de intencao entre 150 e 300ms', function () {
    $card = clnCard();

    expect($card)->toMatch('/HOVER_INTENT_MS\s*=\s*\d+/');
    preg_match('/HOVER_INTENT_MS\s*=\s*(\d+)/', $card, $m);
    expect((int) $m[1])->toBeGreaterThanOrEqual(150)->toBeLessThanOrEqual(300);
});

it('a previa nao roda em TOQUE e cancela imediatamente ao sair do card', function () {
    $card = clnCard();

    // Só em hover REAL (desktop): nada roda no mobile → sem estado preso após o tap.
    expect($card)
        ->toContain('function hoverCapable')
        ->toContain('(hover: hover) and (pointer: fine)')
        ->toContain('!hoverCapable()');

    // stopPreview mata a intenção pendente na hora que o mouse sai.
    expect($card)->toContain('clearTimeout(intentTimer)');
});

it('a previa WebRTC usa um <video> v-show (sempre montado) — nao sofre o bug preto do #206', function () {
    // O bug do #206 era faixa anexada a um <video> que o Vue DESMONTAVA (v-if). Aqui
    // o elemento é v-show (fica sempre no DOM), então o track anexa a um alvo estável.
    expect(clnCard())->toContain('v-show="webrtcActive && showLive"');
});
