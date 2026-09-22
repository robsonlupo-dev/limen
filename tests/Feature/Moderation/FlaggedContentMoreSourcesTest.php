<?php

use App\Exceptions\NicknameException;
use App\Models\ContentFlag;
use App\Models\User;
use App\Services\ContentFlagService;
use App\Services\MemberNicknameService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Sinais NÃO-chat na fila de conteúdo sinalizado (feat/flagged-content-more-sources,
 * Fase 4c-b): bio pública do membro e apelido. Só CONDUTA vira flag (ameaça/insulto
 * direcionado) — contato e risco legal são rejeitados pela validação, mas NÃO entram
 * na fila de reincidência, como no chat. Helpers com prefixo fcm*.
 */
function fcmMember(): User
{
    return User::factory()->create([
        'role' => 'consumer', 'status' => 'active', 'email_verified_at' => now(),
    ]);
}

// ─── ContentFlagService::recordFromText (o núcleo) ────────────────────────────

it('recordFromText só sinaliza a categoria CONDUTA (legal e limpo não)', function () {
    $user = fcmMember();
    $svc = app(ContentFlagService::class);

    $svc->recordFromText($user, ContentFlag::SOURCE_PROFILE_TEXT, 'vou te matar');       // conduta → flag
    $svc->recordFromText($user, ContentFlag::SOURCE_PROFILE_TEXT, 'faço programa completo'); // legal → não
    $svc->recordFromText($user, ContentFlag::SOURCE_PROFILE_TEXT, 'amo música e viagens'); // limpo → não

    $flags = ContentFlag::where('user_id', $user->id)->get();
    expect($flags)->toHaveCount(1)
        ->and($flags->first()->category)->toBe(ContentFlag::CATEGORY_CONDUCT)
        ->and($flags->first()->source)->toBe(ContentFlag::SOURCE_PROFILE_TEXT);
});

it('recordFromText deduplica a mesma regra na janela', function () {
    $user = fcmMember();
    $svc = app(ContentFlagService::class);

    $svc->recordFromText($user, ContentFlag::SOURCE_PROFILE_TEXT, 'vou te matar');
    $svc->recordFromText($user, ContentFlag::SOURCE_PROFILE_TEXT, 'vou te matar');

    expect(ContentFlag::where('user_id', $user->id)->count())->toBe(1);
});

// ─── Apelido (MemberNicknameService::set) ─────────────────────────────────────

it('apelido de CONDUTA gera flag (fonte nickname) e é rejeitado', function () {
    $member = fcmMember();

    expect(fn () => app(MemberNicknameService::class)->set($member, 'te mato'))
        ->toThrow(NicknameException::class);

    expect(ContentFlag::where('user_id', $member->id)->where('source', ContentFlag::SOURCE_NICKNAME)->count())->toBe(1);
    // Rejeitado de verdade: o apelido não foi salvo.
    expect($member->fresh()->nickname)->toBeNull();
});

it('apelido LIMPO é salvo e NÃO gera flag', function () {
    $member = fcmMember();

    app(MemberNicknameService::class)->set($member, 'Leozito');

    expect($member->fresh()->nickname)->toBe('Leozito')
        ->and(ContentFlag::count())->toBe(0);
});

// ─── Bio pública do membro (rota consumer.profile.public.update) ──────────────

it('bio de CONDUTA gera flag (fonte profile_text) e é rejeitada', function () {
    $member = fcmMember();

    $this->actingAs($member)
        ->put(route('consumer.profile.public.update'), ['bio' => 'vou te matar'])
        ->assertSessionHasErrors('bio');

    expect(ContentFlag::where('user_id', $member->id)->where('source', ContentFlag::SOURCE_PROFILE_TEXT)->count())->toBe(1);
    expect($member->fresh()->bio)->toBeNull();
});

it('bio de RISCO LEGAL é rejeitada mas NÃO gera flag', function () {
    $member = fcmMember();

    $this->actingAs($member)
        ->put(route('consumer.profile.public.update'), ['bio' => 'faço programa completo'])
        ->assertSessionHasErrors('bio');

    expect(ContentFlag::count())->toBe(0);
});

it('bio LIMPA é salva e NÃO gera flag', function () {
    $member = fcmMember();

    $this->actingAs($member)
        ->put(route('consumer.profile.public.update'), ['bio' => 'Amo música e viagens.'])
        ->assertSessionHasNoErrors();

    expect($member->fresh()->bio)->toBe('Amo música e viagens.')
        ->and(ContentFlag::count())->toBe(0);
});
