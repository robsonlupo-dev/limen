<?php

use App\Models\PerformerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Filtro de conteúdo nos textos livres do perfil da performer.
 *
 * `bio` e `looking_for` são publicados em /performers/{slug}, que é pública e
 * indexável — uma oferta de encontro pago escrita ali tem alcance MAIOR que a
 * mesma frase no chat privado. O filtro TIPO 1 (`legal`) do ChatContentFilter
 * passa a valer nos dois.
 *
 * O que estes testes travam, além do bloqueio: que TIPO 2 (conduta) continua
 * PASSANDO no perfil, que as isenções do filtro do chat valem inteiras aqui, e
 * que a validação não escreve no audit.
 *
 * Helpers locais com prefixo pcf*.
 */
function pcfPerformer(string $stageName = 'Ana'): PerformerProfile
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);

    return $user->performerProfile()->create([
        'stage_name' => $stageName,
        'slug' => PerformerProfile::generateSlug($stageName),
        'bio' => 'Bio original',
        'category' => 'mulheres',
        'is_verified' => true,
        'level' => 'iniciante',
        'split_pct' => 65,
    ]);
}

it('rejects a bio that offers a paid encounter', function () {
    $profile = pcfPerformer();

    $this->actingAs($profile->user)
        ->post(route('performer.profile.save'), [
            'stage_name' => 'Ana',
            'bio' => 'Atendo com carinho, programa completo para quem trata bem.',
        ])
        ->assertSessionHasErrors('bio');

    expect($profile->fresh()->bio)->toBe('Bio original');
});

it('rejects a looking_for that offers a paid encounter', function () {
    $profile = pcfPerformer();

    $this->actingAs($profile->user)
        ->post(route('performer.profile.save'), [
            'stage_name' => 'Ana',
            'looking_for' => 'Busco encontro presencial, 500 reais a diária.',
        ])
        ->assertSessionHasErrors('looking_for');

    expect($profile->fresh()->looking_for)->toBeNull();
});

it('rejects the ambiguous term only when money is in the same text', function () {
    $profile = pcfPerformer();

    // "vamos num motel" é vida pessoal de adultos e passa; com valor na mesma
    // frase vira intermediação. É a mesma regra do chat, não uma segunda.
    $this->actingAs($profile->user)
        ->post(route('performer.profile.save'), [
            'stage_name' => 'Ana',
            'looking_for' => 'Adoro um fim de semana em hotel bom.',
        ])
        ->assertSessionHasNoErrors();

    $this->actingAs($profile->user)
        ->post(route('performer.profile.save'), [
            'stage_name' => 'Ana',
            'looking_for' => 'Hotel, R$ 800 a noite.',
        ])
        ->assertSessionHasErrors('looking_for');
});

it('accepts consensual profanity in the bio, exactly as the chat does', function () {
    $profile = pcfPerformer();

    // Vocabulário do produto. Barrar isto seria a plataforma editando o tom de
    // voz de quem paga para estar nela.
    $this->actingAs($profile->user)
        ->post(route('performer.profile.save'), [
            'stage_name' => 'Ana',
            'bio' => 'Sou uma puta gostosa e sei disso.',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($profile->fresh()->bio)->toBe('Sou uma puta gostosa e sei disso.');
});

it('accepts an ordinary bio', function () {
    $profile = pcfPerformer();

    $this->actingAs($profile->user)
        ->post(route('performer.profile.save'), [
            'stage_name' => 'Ana',
            'bio' => 'Carioca, apaixonada por música e praia. Adoro uma boa conversa.',
            'looking_for' => 'Conexões honestas, sem pressa e com respeito.',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $profile->refresh();
    expect($profile->bio)->toContain('Carioca');
    expect($profile->looking_for)->toContain('Conexões honestas');
});

it('keeps letting contact details through, as the chat filter does', function () {
    $profile = pcfPerformer();

    // Isenção explícita do PO: troca de contato é legítima no produto. A regra
    // do perfil herda o filtro inteiro — não é uma lista mais dura.
    $this->actingAs($profile->user)
        ->post(route('performer.profile.save'), [
            'stage_name' => 'Ana',
            'bio' => 'Me acha no instagram tambem, @ana.',
        ])
        ->assertSessionHasNoErrors();
});

it('does not block type 2 conduct on the profile', function () {
    $profile = pcfPerformer();

    // Insulto direcionado é TIPO 2. No próprio perfil não há alvo — é
    // auto-sabotagem comercial, não vetor de ataque. Passa de propósito; se um
    // dia passar a barrar, é decisão de produto e este teste tem que mudar
    // junto, não silenciosamente.
    $this->actingAs($profile->user)
        ->post(route('performer.profile.save'), [
            'stage_name' => 'Ana',
            'bio' => 'Se voce e um otario nojento, nem me procure.',
        ])
        ->assertSessionHasNoErrors();
});

it('writes no audit entry when the filter rejects profile text', function () {
    $profile = pcfPerformer();
    $before = DB::table('audit_logs')->count();

    $this->actingAs($profile->user)
        ->post(route('performer.profile.save'), [
            'stage_name' => 'Ana',
            'bio' => 'programa completo, chama no direct',
        ])
        ->assertSessionHasErrors('bio');

    // No chat o audit existe porque a moderação age por REPETIÇÃO. Aqui é
    // feedback de formulário: o texto nem chega a ser persistido, e registrar
    // rascunho recusado enterraria o sinal que o audit do chat carrega.
    expect(DB::table('audit_logs')->count())->toBe($before);
});

it('blocks the offer on the onboarding door too, not just the edit screen', function () {
    $user = User::factory()->create(['role' => 'performer', 'status' => 'pending']);

    // As três portas que escrevem o perfil (edição, onboarding, API v1)
    // compartilham o UpdatePerformerProfileRequest. Gate que fecha uma porta só
    // não é gate — lição do documents.accepted.
    $this->actingAs($user)
        ->post(route('performer.onboarding.profile'), [
            'stage_name' => 'Nova',
            'bio' => 'fazer programa',
            'category' => 'mulheres',
            'rate_public' => 10,
            'rate_private' => 20,
            'rate_camera' => 30,
        ])
        ->assertSessionHasErrors('bio');
});
