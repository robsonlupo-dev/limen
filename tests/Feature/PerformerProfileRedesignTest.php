<?php

use App\Models\PerformerContent;
use App\Models\PerformerProfile;
use App\Models\PerformerVoiceIntro;
use App\Models\User;
use App\Services\PerformerContentService;
use App\Services\TokenService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Redesenho do perfil público da performer (feat/performer-profile-redesign).
 * O redesenho é layout e leitura — nenhuma regra de acesso/preço muda. Estes
 * testes provam que a tela RENDERIZA em todas as combinações de dado (com/sem
 * capa, avatar, voz, conteúdo), que o selo de verificada só lista critérios
 * REAIS, e que os bytes de conteúdo pago continuam nunca expostos.
 *
 * Helpers `ppr*` para rodar isolado. Vue testado por FONTE (sem Vitest).
 */
beforeEach(function () {
    config(['csam.enabled' => false]); // o pipeline de imagem não é o alvo aqui
    Storage::fake('local');            // avatar/cover
});

function pprPerformer(bool $cover = true, bool $avatar = true): PerformerProfile
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);
    $profile = $user->performerProfile()->create([
        'stage_name' => 'Ana '.Str::random(6),
        'slug' => 'ana-'.strtolower(Str::random(6)),
        'category' => 'mulheres',
        'is_verified' => true,
        'rate_public' => 60, 'rate_private' => 120, 'rate_camera' => 20,
    ]);
    $fill = [];
    if ($cover) $fill['cover_path'] = "performer-media/{$user->id}/cover.jpg";
    if ($avatar) $fill['avatar_path'] = "performer-media/{$user->id}/avatar.jpg";
    if ($fill) $profile->forceFill($fill)->save();

    return $profile->fresh();
}

function pprMember(): User
{
    return User::factory()->create([
        'role' => 'consumer', 'status' => 'active',
        'email_verified_at' => now()->subDays(30), 'created_at' => now()->subDays(30),
    ]);
}

function pprApproveVoice(PerformerProfile $profile): void
{
    Storage::fake(\App\Services\VoiceIntroStore::DISK);
    $path = $profile->id.'/'.Str::random(16).'.mp3';
    Storage::disk(\App\Services\VoiceIntroStore::DISK)->put($path, 'MP3');
    $intro = new PerformerVoiceIntro;
    $intro->performer_profile_id = $profile->id;
    $intro->status = 'approved';
    $intro->path = $path;
    $intro->duration_seconds = 14;
    $intro->save();
}

// ─── Render em todas as combinações de dado ──────────────────────────────────

it('o perfil do MEMBRO renderiza com capa e avatar', function () {
    $this->actingAs(pprMember())
        ->get(route('catalog.show', pprPerformer(cover: true, avatar: true)->slug))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('Catalog/Show'));
});

it('o perfil do MEMBRO renderiza SEM capa e SEM avatar (fallbacks não quebram)', function () {
    $this->actingAs(pprMember())
        ->get(route('catalog.show', pprPerformer(cover: false, avatar: false)->slug))
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('Catalog/Show')
            ->where('performer.cover_url', null)
            ->where('performer.avatar_url', null));
});

it('o perfil do VISITANTE renderiza com e sem voz aprovada', function () {
    // Sem voz: URL null → a faixa de voz some.
    $noVoice = pprPerformer();
    $this->get(route('performers.public.show', $noVoice->slug))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('Performers/Show')->where('performer.voice_intro_url', null));

    // Com voz aprovada: URL presente → a faixa de voz aparece.
    $withVoice = pprPerformer();
    pprApproveVoice($withVoice);
    $this->get(route('performers.public.show', $withVoice->fresh()->slug))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->whereNot('performer.voice_intro_url', null));
});

it('o perfil renderiza com e sem conteúdo (a aba Conteúdo sem peça é convite, não zero)', function () {
    // Sem conteúdo: renderiza; a prop vem vazia (a tela mostra o convite).
    $empty = pprPerformer();
    $this->actingAs(pprMember())
        ->get(route('catalog.show', $empty->slug))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->where('contents', []));

    // Com conteúdo aberto: a peça acessível aparece com bytes.
    $withContent = pprPerformer();
    app(PerformerContentService::class)->publish($withContent, UploadedFile::fake()->image('c.jpg', 800, 600), 'open', 5);
    $this->actingAs(pprMember())
        ->get(route('catalog.show', $withContent->fresh()->slug))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->has('contents', 1));
});

// ─── Bytes de conteúdo PAGO nunca expostos ───────────────────────────────────

it('conteúdo bloqueado por tier chega SEM bytes no prop (paywall server-side intacto)', function () {
    $profile = pprPerformer();
    // Premium: bloqueado para um membro Free — deve chegar sem image_url/video_url.
    app(PerformerContentService::class)->publish($profile, UploadedFile::fake()->image('c.jpg', 800, 600), 'premium', 30);

    $this->actingAs(pprMember())
        ->get(route('catalog.show', $profile->fresh()->slug))
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->where('contents.0.locked', true)
            ->where('contents.0.image_url', null)
            ->where('contents.0.video_url', null));
});

// ─── Selo de verificada: painel clicável, só critérios REAIS (item 8) ─────────

it('o cabeçalho torna o selo de verificada clicável e monta o painel de verificação', function () {
    $hero = file_get_contents(resource_path('js/Components/Profile/ProfileHero.vue'));
    expect($hero)
        ->toContain("emit('open-verified')")
        ->toContain('<VerifiedBadge :category="performer.category" />');

    foreach (['js/Pages/Catalog/Show.vue', 'js/Pages/Performers/Show.vue'] as $page) {
        $src = file_get_contents(resource_path($page));
        expect($src)
            ->toContain('VerificationPanel')
            ->toContain('@open-verified="showVerified = true"');
    }
});

it('o painel de verificação lista SÓ critérios reais (KYC + voz), sem inventar conteúdo revisado', function () {
    $src = file_get_contents(resource_path('js/Components/Profile/VerificationPanel.vue'));

    // Critérios REAIS que o sistema executa (KYC Didit + moderação humana da voz).
    expect($src)
        ->toContain('Documento de identidade conferido')
        ->toContain('Identidade confirmada por biometria')
        ->toContain('Maioridade confirmada')
        ->toContain('Apresentação de voz revisada por uma pessoa');

    // O item da voz é CONDICIONAL (só quando há intro aprovada).
    expect($src)->toContain('props.hasVoice');

    // NÃO inventa "conteúdo revisado" (a checagem de conteúdo é anti-CSAM
    // automática, não revisão humana — afirmá-la seria inventar critério).
    expect(strtolower($src))->not->toContain('conteúdo revisado');
});

// ─── A voz é a assinatura: faixa (band), não pílula ───────────────────────────

it('os perfis usam a VOZ como faixa de destaque (variant band), com o nome da performer', function () {
    foreach (['js/Pages/Catalog/Show.vue', 'js/Pages/Performers/Show.vue'] as $page) {
        $src = file_get_contents(resource_path($page));
        expect($src)
            ->toContain('variant="band"')
            ->toContain(':performer-name="performer.stage_name"');
    }
});
