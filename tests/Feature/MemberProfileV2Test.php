<?php

use App\Models\MemberGalleryPhoto;
use App\Models\User;
use App\Support\AgeBand;
use App\Support\FanAlias;
use App\Support\MemberProfileOptions;
use App\Support\ProfileTextGuard;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Perfil do membro v2 (feat/member-profile-v2): campos públicos opt-in, faixa
 * etária derivada, guarda de contato na bio, e a foto de duas variantes
 * (enquadrada no card / completa no lightbox). Trava os invariantes de
 * privacidade novos: nada aparece sem o membro preencher/consentir, e nenhum
 * campo de PII involuntário vaza.
 *
 * Helpers `v2*` para o arquivo rodar isolado.
 */
beforeEach(function () {
    Storage::fake('local');
});

function v2Performer(): User
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);
    $user->performerProfile()->create([
        'stage_name' => 'Perf '.Str::random(8),
        'slug' => 'perf-'.strtolower(Str::random(6)),
        'category' => 'mulheres', 'is_verified' => true, 'level' => 'iniciante', 'split_pct' => 65,
    ]);

    return $user->fresh();
}

function v2Member(array $attrs = []): User
{
    return User::factory()->create(array_merge([
        'role' => 'consumer', 'status' => 'active',
        'email_verified_at' => now()->subDays(30), 'created_at' => now()->subDays(30),
    ], $attrs))->fresh();
}

function v2Photo(User $member, string $status = 'approved', bool $primary = false): MemberGalleryPhoto
{
    $path = 'member-gallery/'.$member->id.'/'.Str::random(20).'.jpg';
    $fullPath = 'member-gallery/'.$member->id.'/'.Str::random(20).'.jpg';
    Storage::disk('local')->put($path, 'cropped-bytes');
    Storage::disk('local')->put($fullPath, 'full-bytes');

    $photo = new MemberGalleryPhoto;
    $photo->user_id = $member->id;
    $photo->path = $status === 'rejected' ? '' : $path;
    $photo->full_path = $status === 'rejected' ? '' : $fullPath;
    $photo->token = Str::random(48);
    $photo->full_token = Str::random(48);
    $photo->status = $status;
    $photo->is_primary = $primary;
    $photo->save();

    return $photo;
}

function v2Handle(User $performer, User $member): string
{
    return FanAlias::handle($performer->performerProfile->id, $member->id);
}

// ═══ AgeBand: derivação e limites ═══════════════════════════════════════════

it('deriva a faixa etária correta do birthdate, nos limites', function () {
    expect(AgeBand::for(now()->subYears(24)))->toBe('18-24')
        ->and(AgeBand::for(now()->subYears(25)))->toBe('25-30')
        ->and(AgeBand::for(now()->subYears(30)))->toBe('25-30')
        ->and(AgeBand::for(now()->subYears(31)))->toBe('31-40')
        ->and(AgeBand::for(now()->subYears(45)))->toBe('41-50')
        ->and(AgeBand::for(now()->subYears(60)))->toBe('50+')
        ->and(AgeBand::for(null))->toBeNull();
});

// ═══ ProfileTextGuard: bio barra contato/conduta ════════════════════════════

it('a bio barra telefone, e-mail, @ e rede social — inclusive com espaços/leet', function () {
    // Telefone disfarçado.
    expect(ProfileTextGuard::blocks('me liga 11 99999 8888'))->toBeTrue()
        // E-mail / arroba.
        ->and(ProfileTextGuard::blocks('meu contato joao@gmail.com'))->toBeTrue()
        // URL.
        ->and(ProfileTextGuard::blocks('meu site joao.com.br'))->toBeTrue()
        // Rede social direta.
        ->and(ProfileTextGuard::blocks('me segue no instagram'))->toBeTrue()
        // Evasão por espaços.
        ->and(ProfileTextGuard::blocks('chama no z a p'))->toBeTrue()
        ->and(ProfileTextGuard::blocks('me acha no i n s t a'))->toBeTrue()
        // Evasão por alongamento.
        ->and(ProfileTextGuard::blocks('me chama no zaaaap'))->toBeTrue();
});

it('a bio limpa passa', function () {
    expect(ProfileTextGuard::blocks('Gosto de viajar, vinho e boas conversas.'))->toBeFalse()
        ->and(ProfileTextGuard::blocks(''))->toBeFalse();
});

it('o endpoint recusa a bio com contato (erro de validação no campo bio)', function () {
    $member = v2Member();

    $this->actingAs($member)
        ->from(route('consumer.profile.edit'))
        ->put(route('consumer.profile.public.update'), ['bio' => 'me chama no zap 11999998888'])
        ->assertSessionHasErrors('bio');

    expect($member->fresh()->bio)->toBeNull();
});

// ═══ Persistência dos campos públicos (forceFill de allowlist) ══════════════

it('salva os campos públicos e valida as listas controladas', function () {
    $member = v2Member();
    $seeking = MemberProfileOptions::seekingSlugs()[0];
    $interest = MemberProfileOptions::interestSlugs()[0];
    $height = MemberProfileOptions::heightValues()[0];

    $this->actingAs($member)
        ->put(route('consumer.profile.public.update'), [
            'bio' => 'Amo música e viagens.',
            'public_seeking' => [$seeking],
            'public_interests' => [$interest],
            'profile_city' => 'São Paulo',
            'profile_uf' => 'SP',
            'marital_status' => 'solteiro',
            'height_cm' => $height,
            'show_age_band' => true,
        ])
        ->assertSessionHasNoErrors();

    $member->refresh();
    expect($member->bio)->toBe('Amo música e viagens.')
        ->and($member->public_seeking)->toBe([$seeking])
        ->and($member->public_interests)->toBe([$interest])
        ->and($member->profile_city)->toBe('São Paulo')
        ->and($member->profile_uf)->toBe('SP')
        ->and($member->marital_status)->toBe('solteiro')
        ->and($member->height_cm)->toBe($height)
        ->and($member->show_age_band)->toBeTrue();
});

it('recusa slug fora da lista controlada e UF inválida', function () {
    $member = v2Member();

    $this->actingAs($member)
        ->from(route('consumer.profile.edit'))
        ->put(route('consumer.profile.public.update'), [
            'public_seeking' => ['nao_existe'],
            'profile_uf' => 'ZZ',
        ])
        ->assertSessionHasErrors(['public_seeking.0', 'profile_uf']);
});

it('os campos públicos NÃO entram por mass assignment (fora do $fillable)', function () {
    $member = v2Member();

    // fill() só grava o que está no $fillable; bio/etc não estão.
    $member->fill(['bio' => 'injetado']);
    expect($member->bio)->toBeNull();
});

// ═══ Payload do perfil: só o que foi preenchido, zero PII ═══════════════════

it('o perfil expõe os campos públicos preenchidos e OMITE os vazios', function () {
    $performer = v2Performer();
    $seeking = MemberProfileOptions::seekingSlugs()[0];
    $member = v2Member(['profile_visible' => true]);
    $member->forceFill([
        'bio' => 'Curto arte e vinho.',
        'public_seeking' => [$seeking],
        'profile_city' => 'Rio de Janeiro', 'profile_uf' => 'RJ',
        // interesses, estado civil, altura, faixa: NÃO preenchidos.
    ])->save();
    v2Photo($member, 'approved', true);

    $this->actingAs($performer)
        ->get(route('performer.members.profile', v2Handle($performer, $member)))
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p
            ->component('Performer/MemberProfile')
            ->where('member.bio', 'Curto arte e vinho.')
            ->where('member.city_label', 'Rio de Janeiro, RJ')
            ->has('member.seeking', 1)
            // Não preenchidos → vazios/null (a tela não renderiza).
            ->where('member.interests', [])
            ->where('member.marital_status', null)
            ->where('member.height', null)
            ->etc());
});

it('a faixa etária só aparece quando o membro optou por mostrá-la', function () {
    $performer = v2Performer();
    $member = v2Member(['profile_visible' => true, 'birthdate' => now()->subYears(28)]);
    v2Photo($member, 'approved', true);

    // Sem opt-in → null.
    $this->actingAs($performer)
        ->get(route('performer.members.profile', v2Handle($performer, $member)))
        ->assertInertia(fn (Assert $p) => $p->where('member.age_band', null));

    // Com opt-in → a faixa derivada (nunca a idade/data exata).
    $member->forceFill(['show_age_band' => true])->save();
    $this->actingAs($performer)
        ->get(route('performer.members.profile', v2Handle($performer, $member)))
        ->assertInertia(fn (Assert $p) => $p->where('member.age_band', '25-30'));
});

it('o perfil v2 não vaza birthdate, nome, e-mail nem tier', function () {
    $performer = v2Performer();
    $member = v2Member([
        'profile_visible' => true, 'show_age_band' => true,
        'birthdate' => '1990-05-17', 'name' => 'Fulano Real', 'email' => 'segredo@x.com',
    ]);
    v2Photo($member, 'approved', true);

    $response = $this->actingAs($performer)
        ->get(route('performer.members.profile', v2Handle($performer, $member)));

    $response->assertInertia(fn (Assert $p) => $p
        ->missing('member.birthdate')->missing('member.name')
        ->missing('member.email')->missing('member.lifestyle_tier')->etc());
    expect($response->getContent())->not->toContain('Fulano Real')
        ->and($response->getContent())->not->toContain('segredo@x.com')
        ->and($response->getContent())->not->toContain('1990-05-17');
});

// ═══ Card do catálogo: selo, faixa, cidade, contagem ════════════════════════

it('o card traz selo/faixa/cidade/contagem quando consentidos, e não quando não', function () {
    $performer = v2Performer();
    $member = v2Member([
        'profile_visible' => true, 'show_age_band' => true,
        'birthdate' => now()->subYears(35), 'age_verified_at' => now(),
        'profile_city' => 'Curitiba', 'profile_uf' => 'PR',
    ]);
    v2Photo($member, 'approved', true);
    v2Photo($member, 'approved', false);

    $rows = app(\App\Services\MemberCatalogService::class)->page($performer->performerProfile)->getCollection();
    $card = $rows->firstWhere('member_handle', v2Handle($performer, $member));

    expect($card['is_verified'])->toBeTrue()
        ->and($card['age_band'])->toBe('31-40')
        ->and($card['city_label'])->toBe('Curitiba, PR')
        ->and($card['photo_count'])->toBe(2);
});

it('membro sem KYC/faixa/cidade não expõe selo/faixa/cidade no card', function () {
    $performer = v2Performer();
    $member = v2Member(['profile_visible' => true, 'age_verified_at' => null]);

    $rows = app(\App\Services\MemberCatalogService::class)->page($performer->performerProfile)->getCollection();
    $card = $rows->firstWhere('member_handle', v2Handle($performer, $member));

    expect($card['is_verified'])->toBeFalse()
        ->and($card['age_band'])->toBeNull()
        ->and($card['city_label'])->toBeNull()
        ->and($card['photo_count'])->toBe(0);
});

// ═══ Foto de duas variantes: card=enquadrada, lightbox=completa ══════════════

it('o perfil serve a variante enquadrada (url) e a completa (full_url) por tokens distintos', function () {
    $performer = v2Performer();
    $member = v2Member(['profile_visible' => true]);
    $photo = v2Photo($member, 'approved', true);

    $this->actingAs($performer)
        ->get(route('performer.members.profile', v2Handle($performer, $member)))
        ->assertInertia(fn (Assert $p) => $p
            ->where('member.photos.0.url', fn ($u) => str_contains($u, $photo->token))
            ->where('member.photos.0.full_url', fn ($u) => str_contains($u, $photo->full_token))
            ->etc());
});

it('a rota de mídia serve a variante certa por token e gateia por approved+profile_visible', function () {
    $member = v2Member(['profile_visible' => true]);
    $photo = v2Photo($member, 'approved', true);

    // Enquadrada (token) e completa (full_token) servem os bytes de cada variante.
    $this->get($photo->mediaUrl())->assertOk();
    $this->get($photo->fullMediaUrl())->assertOk();

    // Desligar o perfil → REVOGA na hora as duas variantes para não-dono.
    $member->forceFill(['profile_visible' => false])->save();
    $this->get($photo->mediaUrl())->assertNotFound();
    $this->get($photo->fullMediaUrl())->assertNotFound();
});

it('a variante completa de foto PENDING não vaza para não-dono', function () {
    $member = v2Member(['profile_visible' => true]);
    $photo = v2Photo($member, 'pending');

    $this->get($photo->fullMediaUrl())->assertNotFound();
});

// ═══ Upload: gera as duas variantes; o recorte do cliente vira o card ════════

it('o upload com recorte grava a variante enquadrada E a completa, arquivos distintos', function () {
    $member = v2Member();

    $this->actingAs($member)->post(route('consumer.gallery.store'), [
        'file' => UploadedFile::fake()->image('original.jpg', 1000, 1200),
        'cropped' => UploadedFile::fake()->image('recorte.jpg', 720, 960),
    ])->assertSessionHasNoErrors();

    $photo = MemberGalleryPhoto::where('user_id', $member->id)->latest('id')->first();

    expect($photo)->not->toBeNull()
        ->and($photo->path)->not->toBe('')
        ->and($photo->full_path)->not->toBe('')
        ->and($photo->path)->not->toBe($photo->full_path)
        ->and($photo->full_token)->not->toBeNull();
    Storage::disk('local')->assertExists($photo->path);
    Storage::disk('local')->assertExists($photo->full_path);
});

it('o upload SEM recorte ainda gera as duas variantes (servidor recorta no centro)', function () {
    $member = v2Member();

    $this->actingAs($member)->post(route('consumer.gallery.store'), [
        'file' => UploadedFile::fake()->image('original.jpg', 1000, 1200),
    ])->assertSessionHasNoErrors();

    $photo = MemberGalleryPhoto::where('user_id', $member->id)->latest('id')->first();
    expect($photo->path)->not->toBe('')->and($photo->full_path)->not->toBe('');
    Storage::disk('local')->assertExists($photo->path);
    Storage::disk('local')->assertExists($photo->full_path);
});

// ═══ Gate de rota: o perfil público é do MEMBRO; a página é da PERFORMER ═════

it('a performer não pode salvar o perfil público de membro (rota é do membro)', function () {
    $performer = v2Performer();

    $this->actingAs($performer)
        ->put(route('consumer.profile.public.update'), ['bio' => 'x'])
        ->assertForbidden();
});
