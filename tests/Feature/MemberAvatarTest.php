<?php

use App\Models\CsamHash;
use App\Models\PerformerProfile;
use App\Models\User;
use App\Services\CsamScanService;
use App\Services\MemberCatalogService;
use App\Services\PerceptualHashService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Foto de perfil do MEMBRO (fix/member-photo-and-crop). Até aqui o membro era
 * imageless. A foto reusa o MESMO pipeline da performer (ImageProcessingService +
 * anti-CSAM); estes testes provam o ciclo (subir/trocar/remover), a moderação, o
 * serving por token OPACO (não o member_id que o FanAlias esconde) e a exposição
 * consentida no catálogo.
 *
 * Helpers `mav*` para o arquivo rodar isolado (funções do Pest são globais).
 */
beforeEach(function () {
    Storage::fake('local');
});

function mavMember(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => 'consumer',
        'status' => 'active',
        'email_verified_at' => now()->subDays(30),
        'created_at' => now()->subDays(30),
    ], $overrides));
}

// ─── Ciclo: subir, trocar, remover ───────────────────────────────────────────

it('o membro sobe a foto de perfil e ela passa a existir no disco', function () {
    $member = mavMember();

    $this->actingAs($member)
        ->post(route('consumer.profile.photo'), ['file' => UploadedFile::fake()->image('me.jpg', 800, 800)])
        ->assertRedirect();

    $member->refresh();
    expect($member->avatar_path)->toBe("member-media/{$member->id}/avatar.jpg")
        ->and($member->avatar_token)->not->toBeNull();
    Storage::disk('local')->assertExists($member->avatar_path);
});

it('trocar a foto apaga a anterior e ROTACIONA o token (URLs assinadas antigas morrem)', function () {
    $member = mavMember();

    $this->actingAs($member)
        ->post(route('consumer.profile.photo'), ['file' => UploadedFile::fake()->image('first.jpg', 600, 600)]);
    $firstToken = $member->fresh()->avatar_token;

    $this->actingAs($member)
        ->post(route('consumer.profile.photo'), ['file' => UploadedFile::fake()->image('second.png', 600, 600)]);
    $member->refresh();

    // O caminho é fixo (avatar.jpg), mas o token muda — a URL antiga não resolve mais.
    expect($member->avatar_token)->not->toBe($firstToken);
    Storage::disk('local')->assertExists($member->avatar_path);
});

it('o membro remove a foto: caminho e token zerados, bytes apagados', function () {
    $member = mavMember();
    $this->actingAs($member)
        ->post(route('consumer.profile.photo'), ['file' => UploadedFile::fake()->image('me.jpg', 500, 500)]);
    $path = $member->fresh()->avatar_path;

    $this->actingAs($member)
        ->delete(route('consumer.profile.photo.destroy'))
        ->assertRedirect();

    $member->refresh();
    expect($member->avatar_path)->toBeNull()
        ->and($member->avatar_token)->toBeNull();
    Storage::disk('local')->assertMissing($path);
});

it('remover sem ter foto é no-op idempotente (não quebra)', function () {
    $member = mavMember();

    $this->actingAs($member)
        ->delete(route('consumer.profile.photo.destroy'))
        ->assertRedirect();

    expect($member->fresh()->avatar_path)->toBeNull();
});

// ─── Validação (mesma do avatar da performer) ────────────────────────────────

it('recusa arquivo não-imagem (mesma regra de mime do UploadMediaRequest)', function () {
    $member = mavMember();

    $this->actingAs($member)
        ->post(route('consumer.profile.photo'), ['file' => UploadedFile::fake()->create('payload.php', 10)])
        ->assertSessionHasErrors('file');

    expect($member->fresh()->avatar_path)->toBeNull();
});

// ─── Moderação: MESMO pipeline anti-CSAM ─────────────────────────────────────

it('a foto do membro passa pelo anti-CSAM: um match bloqueia e nada é gravado', function () {
    config(['csam.enabled' => true]);
    $member = mavMember();

    // Semeia o phash do que o pipeline vai produzir (o scan roda nos bytes JÁ
    // processados). Processa uma vez para obter o hash e semeia a lista.
    $processed = app(\App\Services\ImageProcessingService::class)
        ->process(UploadedFile::fake()->image('bad.jpg', 512, 512), 512, 512, crop: true);
    $hash = app(PerceptualHashService::class)->hash(file_get_contents($processed));
    @unlink($processed);
    CsamHash::create(['hash' => $hash, 'source' => 'test', 'added_at' => now()]);

    // Bloqueia GRACIOSAMENTE (422 com erro de validação, não 500) — mesma
    // disciplina dos outros caminhos de imagem; nada é gravado.
    $this->actingAs($member)
        ->post(route('consumer.profile.photo'), ['file' => UploadedFile::fake()->image('bad.jpg', 512, 512)])
        ->assertSessionHasErrors('file');

    $member->refresh();
    expect($member->avatar_path)->toBeNull();
    // A conta é sinalizada para review, igual aos outros 6 caminhos de imagem.
    expect($member->fresh()->csam_flagged_at)->not->toBeNull();
});

// ─── Serving por token OPACO (nunca o member_id) ─────────────────────────────

it('a URL da foto é servida pelo avatar_token, NUNCA pelo user_id (FanAlias intacto)', function () {
    $member = mavMember();
    $this->actingAs($member)
        ->post(route('consumer.profile.photo'), ['file' => UploadedFile::fake()->image('me.jpg', 400, 400)]);
    $member->refresh();

    $url = $member->avatarUrl();

    expect($url)->toContain($member->avatar_token)
        ->and($url)->not->toContain('user_id')
        ->and($url)->not->toContain('profile_id');

    // A rota assinada entrega os bytes; token inexistente → 404 (sem oráculo).
    $this->get($url)->assertOk();
    $this->get(route('member.media', ['token' => 'nao-existe']))->assertStatus(403); // sem assinatura
});

it('member.media exige assinatura válida (sem ela, 403)', function () {
    $member = mavMember();
    $this->actingAs($member)
        ->post(route('consumer.profile.photo'), ['file' => UploadedFile::fake()->image('me.jpg', 400, 400)]);

    // URL sem assinatura → o middleware `signed` recusa.
    $this->get('/membro/midia?token='.$member->fresh()->avatar_token)->assertStatus(403);
});

// ─── Exposição CONSENTIDA no catálogo de membros (decisão do PO) ──────────────

it('o catálogo de membros expõe a foto (avatar_url por token), ou silhueta se não há', function () {
    $performerUser = User::factory()->create(['role' => 'performer', 'status' => 'active']);
    $performer = $performerUser->performerProfile()->create([
        'stage_name' => 'Perf '.\Illuminate\Support\Str::random(8),
        'slug' => 'perf-'.strtolower(\Illuminate\Support\Str::random(6)),
        'category' => 'mulheres', 'is_verified' => true, 'level' => 'iniciante', 'split_pct' => 65,
    ]);

    $withPhoto = mavMember();
    $this->actingAs($withPhoto)
        ->post(route('consumer.profile.photo'), ['file' => UploadedFile::fake()->image('me.jpg', 400, 400)]);
    $withoutPhoto = mavMember();

    $rows = app(MemberCatalogService::class)->page($performer)->getCollection();
    $urls = $rows->pluck('avatar_url');
    $token = $withPhoto->fresh()->avatar_token;

    // Um card com foto (URL por token, nunca o user_id) e um com silhueta (null).
    $withUrl = $urls->filter()->values();
    expect($withUrl)->toHaveCount(1)
        ->and($withUrl->first())->toContain($token)
        ->and($withUrl->first())->not->toContain('user_id')
        ->and($urls->filter(fn ($u) => $u === null)->count())->toBe(1);
});

// ─── Cadastro: foto OPCIONAL, pode pular ─────────────────────────────────────

it('o cadastro do membro aceita foto opcional e a processa pelo pipeline', function () {
    $this->post(route('register.store'), [
        'tipo' => 'membro',
        'role' => 'consumer',
        'name' => 'Com Foto',
        'email' => 'comfoto@example.com',
        'password' => 'Password1',
        'password_confirmation' => 'Password1',
        'birthdate' => now()->subYears(25)->format('Y-m-d'),
        'cpf' => '529.982.247-25',
        'accept_terms' => true,
        'lgpd_consent' => true,
        'preferred_world' => 'mulheres',
        'avatar' => UploadedFile::fake()->image('me.jpg', 500, 500),
    ])->assertRedirect();

    $user = User::where('email', 'comfoto@example.com')->first();
    expect($user->avatar_path)->not->toBeNull();
    Storage::disk('local')->assertExists($user->avatar_path);
});

it('uma foto que bate no anti-CSAM no cadastro NÃO derruba o registro (conta criada, sem foto, sinalizada)', function () {
    config(['csam.enabled' => true]);
    $processed = app(\App\Services\ImageProcessingService::class)
        ->process(UploadedFile::fake()->image('bad.jpg', 512, 512), 512, 512, crop: true);
    CsamHash::create(['hash' => app(PerceptualHashService::class)->hash(file_get_contents($processed)), 'source' => 'test', 'added_at' => now()]);
    @unlink($processed);

    $this->post(route('register.store'), [
        'tipo' => 'membro', 'role' => 'consumer', 'name' => 'CSAM Reg',
        'email' => 'csamreg@example.com', 'password' => 'Password1', 'password_confirmation' => 'Password1',
        'birthdate' => now()->subYears(25)->format('Y-m-d'), 'cpf' => '529.982.247-25',
        'accept_terms' => true, 'lgpd_consent' => true, 'preferred_world' => 'mulheres',
        'avatar' => UploadedFile::fake()->image('bad.jpg', 512, 512),
    ])->assertRedirect(); // registro segue, não 500

    $user = User::where('email', 'csamreg@example.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->avatar_path)->toBeNull()          // foto não entrou
        ->and($user->csam_flagged_at)->not->toBeNull(); // conta sinalizada para moderação
});

it('o cadastro do membro SEM foto funciona normalmente (pular é o caminho normal)', function () {
    $this->post(route('register.store'), [
        'tipo' => 'membro',
        'role' => 'consumer',
        'name' => 'Sem Foto',
        'email' => 'semfoto@example.com',
        'password' => 'Password1',
        'password_confirmation' => 'Password1',
        'birthdate' => now()->subYears(25)->format('Y-m-d'),
        'cpf' => '529.982.247-25',
        'accept_terms' => true,
        'lgpd_consent' => true,
        'preferred_world' => 'mulheres',
    ])->assertRedirect();

    $user = User::where('email', 'semfoto@example.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->avatar_path)->toBeNull();
});

// ─── Problema 2: capa e avatar não deformam (fallback object-contain) ─────────

it('o AVATAR do perfil usa object-contain (o rosto nunca é cortado)', function () {
    // feat/performer-profile-redesign: capa e avatar migraram para o ProfileHero,
    // dona única do cabeçalho dos dois perfis públicos. A CAPA virou FAIXA (banner)
    // com object-cover e teto de altura; o AVATAR 1:1 segue object-contain para o
    // rosto nunca ser cortado (imagem fora de 1:1 aparece inteira).
    $src = file_get_contents(resource_path('js/Components/Profile/ProfileHero.vue'));
    expect($src)->toContain('object-contain')      // avatar
        ->and($src)->toContain('object-cover');    // capa como banner (deliberado)
});

it('a prévia de edição da performer espelha o display público (object-contain)', function () {
    $src = file_get_contents(resource_path('js/Pages/Performer/Profile/Edit.vue'));
    // As prévias de avatar/capa na tela de edição mostram a imagem inteira, o
    // mesmo que o visitante vê — não center-crop.
    expect(substr_count($src, 'object-contain'))->toBeGreaterThanOrEqual(2);
});
