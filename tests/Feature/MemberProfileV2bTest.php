<?php

use App\Models\MemberGalleryPhoto;
use App\Models\User;
use App\Support\FanAlias;
use App\Support\MemberProfileOptions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Perfil do membro — 2ª leva de campos (feat/member-profile-v2-fields): título/
 * headline, peso (faixa), escolaridade, área, filhos, bebe, fuma, disponibilidade
 * e 2ª/3ª localização. Mesma disciplina da 1ª leva: opt-in, listas controladas,
 * fora do $fillable (forceFill de allowlist), zero PII.
 *
 * Helpers `v2b*` para o arquivo rodar isolado (sem colidir com MemberProfileV2Test).
 */
beforeEach(function () {
    Storage::fake('local');
});

function v2bPerformer(): User
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);
    $user->performerProfile()->create([
        'stage_name' => 'Perf '.Str::random(8),
        'slug' => 'perf-'.strtolower(Str::random(6)),
        'category' => 'mulheres', 'is_verified' => true, 'level' => 'iniciante', 'split_pct' => 65,
    ]);

    return $user->fresh();
}

function v2bMember(array $attrs = []): User
{
    return User::factory()->create(array_merge([
        'role' => 'consumer', 'status' => 'active',
        'email_verified_at' => now()->subDays(30), 'created_at' => now()->subDays(30),
    ], $attrs))->fresh();
}

function v2bPhoto(User $member): MemberGalleryPhoto
{
    $path = 'member-gallery/'.$member->id.'/'.Str::random(20).'.jpg';
    $fullPath = 'member-gallery/'.$member->id.'/'.Str::random(20).'.jpg';
    Storage::disk('local')->put($path, 'cropped-bytes');
    Storage::disk('local')->put($fullPath, 'full-bytes');

    $photo = new MemberGalleryPhoto;
    $photo->user_id = $member->id;
    $photo->path = $path;
    $photo->full_path = $fullPath;
    $photo->token = Str::random(48);
    $photo->full_token = Str::random(48);
    $photo->status = 'approved';
    $photo->is_primary = true;
    $photo->save();

    return $photo;
}

function v2bHandle(User $performer, User $member): string
{
    return FanAlias::handle($performer->performerProfile->id, $member->id);
}

// ═══ Persistência dos campos novos (forceFill de allowlist) ═════════════════

it('salva os campos novos e valida as listas controladas', function () {
    $member = v2bMember();
    $weight = MemberProfileOptions::weightValues()[5]; // um piso de faixa válido

    $this->actingAs($member)
        ->put(route('consumer.profile.public.update'), [
            'headline' => 'Construindo o que ainda não existe.',
            'profile_city' => 'São Paulo', 'profile_uf' => 'SP',
            'profile_city_2' => 'Campinas', 'profile_uf_2' => 'sp',
            'profile_city_3' => 'Rio de Janeiro', 'profile_uf_3' => 'RJ',
            'weight_kg' => $weight,
            'education' => 'pos',
            'occupation_area' => 'tecnologia',
            'children' => 'nao_tenho',
            'drinks' => 'socialmente',
            'smokes' => 'nao_fumo',
            'availability' => 'noites_e_fins',
        ])
        ->assertSessionHasNoErrors();

    $member->refresh();
    expect($member->headline)->toBe('Construindo o que ainda não existe.')
        ->and($member->profile_city_2)->toBe('Campinas')
        ->and($member->profile_uf_2)->toBe('SP') // normalizada para maiúscula
        ->and($member->profile_city_3)->toBe('Rio de Janeiro')
        ->and($member->weight_kg)->toBe($weight)
        ->and($member->education)->toBe('pos')
        ->and($member->occupation_area)->toBe('tecnologia')
        ->and($member->children)->toBe('nao_tenho')
        ->and($member->drinks)->toBe('socialmente')
        ->and($member->smokes)->toBe('nao_fumo')
        ->and($member->availability)->toBe('noites_e_fins');
});

it('recusa slug fora da lista, peso fora da faixa e UF extra inválida', function () {
    $member = v2bMember();

    $this->actingAs($member)
        ->from(route('consumer.profile.edit'))
        ->put(route('consumer.profile.public.update'), [
            'education' => 'nao_existe',
            'weight_kg' => 42, // não é piso de faixa (40,45,...)
            'profile_uf_2' => 'ZZ',
        ])
        ->assertSessionHasErrors(['education', 'weight_kg', 'profile_uf_2']);
});

it('o headline barra contato (guarda de texto), como a bio', function () {
    $member = v2bMember();

    $this->actingAs($member)
        ->from(route('consumer.profile.edit'))
        ->put(route('consumer.profile.public.update'), ['headline' => 'me chama no zap 11999998888'])
        ->assertSessionHasErrors('headline');

    expect($member->fresh()->headline)->toBeNull();
});

it('os campos novos NÃO entram por mass assignment (fora do $fillable)', function () {
    $member = v2bMember();

    $member->fill(['weight_kg' => 80, 'education' => 'doutorado', 'headline' => 'x']);
    expect($member->weight_kg)->toBeNull()
        ->and($member->education)->toBeNull()
        ->and($member->headline)->toBeNull();
});

// ═══ Payload do perfil: rótulos, peso em faixa, 3 localizações ══════════════

it('o perfil expõe os campos novos com rótulo, peso em faixa e as 3 localizações', function () {
    $performer = v2bPerformer();
    $member = v2bMember(['profile_visible' => true]);
    $member->forceFill([
        'headline' => 'Amo boas conversas.',
        'profile_city' => 'São Paulo', 'profile_uf' => 'SP',
        'profile_city_2' => 'Campinas', 'profile_uf_2' => 'SP',
        'profile_city_3' => 'Santos', 'profile_uf_3' => 'SP',
        'weight_kg' => 65,
        'education' => 'pos',
        'occupation_area' => 'tecnologia',
        'children' => 'nao_tenho',
        'drinks' => 'socialmente',
        'smokes' => 'nao_fumo',
        'availability' => 'noites_e_fins',
    ])->save();
    v2bPhoto($member);

    $this->actingAs($performer)
        ->get(route('performer.members.profile', v2bHandle($performer, $member)))
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p
            ->component('Performer/MemberProfile')
            ->where('member.headline', 'Amo boas conversas.')
            // Peso vem como FAIXA, nunca o valor exato.
            ->where('member.weight', '65–69 kg')
            // Rótulos (não slugs) dos selects controlados.
            ->where('member.education', 'Pós-graduação')
            ->where('member.occupation_area', 'Tecnologia')
            ->where('member.children', 'Não tenho')
            ->where('member.drinks', 'Socialmente')
            ->where('member.smokes', 'Não fumo')
            ->where('member.availability', 'Noites e fins de semana')
            // 3 localizações, na ordem, e city_label = a 1ª (compat).
            ->where('member.city_label', 'São Paulo, SP')
            ->where('member.locations', ['São Paulo, SP', 'Campinas, SP', 'Santos, SP'])
            ->etc());
});

it('o perfil OMITE os campos novos não preenchidos', function () {
    $performer = v2bPerformer();
    $member = v2bMember(['profile_visible' => true]);
    v2bPhoto($member);

    $this->actingAs($performer)
        ->get(route('performer.members.profile', v2bHandle($performer, $member)))
        ->assertInertia(fn (Assert $p) => $p
            ->where('member.headline', null)
            ->where('member.weight', null)
            ->where('member.education', null)
            ->where('member.occupation_area', null)
            ->where('member.children', null)
            ->where('member.drinks', null)
            ->where('member.smokes', null)
            ->where('member.availability', null)
            ->where('member.locations', [])
            ->etc());
});
