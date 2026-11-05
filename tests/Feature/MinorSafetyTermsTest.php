<?php

use App\Exceptions\NicknameException;
use App\Exceptions\ReferralLinkException;
use App\Models\PerformerProfile;
use App\Models\User;
use App\Services\MemberNicknameService;
use App\Services\ReferralLinkService;
use App\Support\ChatContentFilter;
use App\Support\ProfileTextGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;

uses(RefreshDatabase::class);

// ─── Proteção à infância (fix/minor-safety-terms) ────────────────────────────
//
// Antes deste PR NÃO havia filtro contra termos que sexualizam menor em nenhuma
// superfície pública de texto — slug, apelido, nome artístico e bio. O filtro
// vive no ChatContentFilter (categoria MINOR, a mais grave) e as quatro portas
// passam por ele.

it('classifica termo que sexualiza menor como MINOR — a categoria mais grave', function () {
    expect(ChatContentFilter::categoryOf('ninfetinha'))->toBe(ChatContentFilter::MINOR);
    expect(ChatContentFilter::blocks('ninfeta'))->toBeTrue();
    expect(ChatContentFilter::blocks('lolitinha'))->toBeTrue();
    expect(ChatContentFilter::blocks('pedofilia'))->toBeTrue();
});

it('pega declinação e desvio (leet, separador, repetição, caixa)', function () {
    foreach (['ninfeta', 'ninfetinha', 'ninfetas', 'n1nfeta', 'n-i-n-f-e-t-a', 'NINFETINHA', 'niiinfeta'] as $t) {
        expect(ChatContentFilter::blocks($t))->toBeTrue("deveria barrar: {$t}");
    }
});

it('NÃO barra palavra inocente (sem falso-positivo)', function () {
    foreach (['praia', 'bella', 'cristina', 'ninfa', 'estrela', 'rainha', 'morena'] as $t) {
        expect(ChatContentFilter::blocks($t))->toBeFalse("barrou errado: {$t}");
    }
});

it('termos AMBÍGUOS ficam liberados por padrão e só barram com o jurídico ligado', function () {
    config(['chat_filters.minors.include_pending' => false]);
    expect(ChatContentFilter::blocks('novinha'))->toBeFalse();

    config(['chat_filters.minors.include_pending' => true]);
    expect(ChatContentFilter::blocks('novinha'))->toBeTrue();
    expect(ChatContentFilter::blocks('colegial'))->toBeTrue();
});

it('o nome artístico barra conteúdo proibido (buraco que estava aberto)', function () {
    $blocked = Validator::make(
        ['stage_name' => 'ninfetinha'],
        ['stage_name' => PerformerProfile::stageNameRules()],
    );
    expect($blocked->fails())->toBeTrue();

    $ok = Validator::make(
        ['stage_name' => 'Bella Rosa'],
        ['stage_name' => PerformerProfile::stageNameRules()],
    );
    expect($ok->fails())->toBeFalse();
});

it('o slug de link personalizado barra o termo', function () {
    $user = User::factory()->create();

    expect(fn () => app(ReferralLinkService::class)->create($user, 'ninfetinha'))
        ->toThrow(ReferralLinkException::class);
});

it('o apelido do membro barra o termo', function () {
    expect(fn () => app(MemberNicknameService::class)->validate('ninfetinha'))
        ->toThrow(NicknameException::class);
});

it('a bio do perfil barra o termo', function () {
    expect(ProfileTextGuard::blocks('me chama de ninfetinha'))->toBeTrue();
    expect(ProfileTextGuard::blocks('gosto de praia e música'))->toBeFalse();
});
