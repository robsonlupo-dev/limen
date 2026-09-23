<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Assistente de conclusão do perfil (feat/member-profile-wizard, etapa 3). A
 * ESCRITA reusa endpoints já cobertos (MemberProfileV2/V2bTest para os campos,
 * a galeria para o opt-in visível); aqui travamos só o que é novo: a rota GUIADA
 * renderiza o assistente, pré-preenchida com o que já existe, e é do membro.
 */
function wizMember(array $attrs = []): User
{
    return User::factory()->create(array_merge(['role' => 'consumer', 'status' => 'active'], $attrs));
}

it('renderiza o assistente para o membro, com valores e opções', function () {
    $member = wizMember();
    $member->forceFill([
        'headline' => 'Amo boas conversas.',
        'profile_city' => 'São Paulo', 'profile_uf' => 'SP',
        'weight_kg' => 65,
    ])->save();

    $this->actingAs($member)
        ->get(route('consumer.profile.complete'))
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p
            ->component('Consumer/Profile/Complete')
            // Pré-preenche o que já existe.
            ->where('public_profile.headline', 'Amo boas conversas.')
            ->where('public_profile.profile_city', 'São Paulo')
            ->where('public_profile.weight_kg', 65)
            // Listas controladas para os selects/chips do assistente.
            ->has('publicProfileOptions.weights')
            ->has('publicProfileOptions.education')
            ->has('publicProfileOptions.seeking')
            ->where('publicProfileOptions.max_seeking', 6)
            // Passo final usa o estado atual do opt-in mestre.
            ->where('profile_visible', false)
            ->etc());
});

it('expoe o apelido atual para o passo inicial', function () {
    $member = wizMember();
    $member->forceFill(['nickname' => 'Leo', 'nickname_normalized' => 'leo'])->save();

    $this->actingAs($member)
        ->get(route('consumer.profile.complete'))
        ->assertInertia(fn (Assert $p) => $p->where('nickname', 'Leo')->etc());
});

it('exige login', function () {
    $this->get(route('consumer.profile.complete'))->assertRedirect(route('login'));
});
