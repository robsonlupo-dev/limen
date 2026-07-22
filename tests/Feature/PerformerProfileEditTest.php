<?php

use App\Models\AuditLog;
use App\Models\Follow;
use App\Models\PerformerInterest;
use App\Models\PerformerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * Edição de perfil da performer ativa (/performer/perfil).
 *
 * Regra central: trocar o nome artístico regenera o slug, para o nome antigo
 * não ficar preso na URL pública. Helpers locais (prefixo ppe*).
 */
function ppePerformer(string $stageName = 'Ana', string $status = 'active'): PerformerProfile
{
    $user = User::factory()->create(['role' => 'performer', 'status' => $status]);

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

it('shows the active performer her own profile to edit', function () {
    $profile = ppePerformer();

    $this->actingAs($profile->user)
        ->get(route('performer.profile.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Performer/Profile/Edit')
            ->where('profile.stage_name', 'Ana')
            ->where('profile.bio', 'Bio original')
            ->where('profile.slug', $profile->slug)
        );
});

it('regenerates the slug when the stage name changes so the old name leaves the url', function () {
    $profile = ppePerformer('Ana');
    $oldSlug = $profile->slug;

    $this->actingAs($profile->user)
        ->post(route('performer.profile.save'), ['stage_name' => 'Bianca', 'bio' => 'Bio original'])
        ->assertRedirect();

    $profile->refresh();
    expect($profile->stage_name)->toBe('Bianca');
    expect($profile->slug)->not->toBe($oldSlug);
    expect($profile->slug)->toStartWith('bianca-');
    // O nome antigo não pode sobreviver em lugar nenhum da URL pública.
    expect($profile->slug)->not->toContain('ana');
});

it('keeps the slug when only the bio changes', function () {
    $profile = ppePerformer('Ana');
    $oldSlug = $profile->slug;

    $this->actingAs($profile->user)
        ->post(route('performer.profile.save'), ['stage_name' => 'Ana', 'bio' => 'Bio nova'])
        ->assertRedirect();

    $profile->refresh();
    expect($profile->bio)->toBe('Bio nova');
    // Renomear quebra links; salvar a bio não pode ter esse custo.
    expect($profile->slug)->toBe($oldSlug);
});

it('makes the old public url 404 after a rename', function () {
    $profile = ppePerformer('Ana');
    $oldSlug = $profile->slug;
    $member = User::factory()->create(['role' => 'consumer', 'status' => 'active']);

    // O perfil é alcançável pelo slug antigo antes do rename.
    $this->actingAs($member)->get(route('catalog.show', $oldSlug))->assertOk();

    $this->actingAs($profile->user)
        ->post(route('performer.profile.save'), ['stage_name' => 'Bianca'])
        ->assertRedirect();

    $this->actingAs($member)->get(route('catalog.show', $oldSlug))->assertNotFound();
    $this->actingAs($member)->get(route('catalog.show', $profile->fresh()->slug))->assertOk();
});

it('keeps followers and interests through a rename', function () {
    $profile = ppePerformer('Ana');
    $member = User::factory()->create(['role' => 'consumer', 'status' => 'active']);
    Follow::create(['user_id' => $member->id, 'performer_profile_id' => $profile->id]);
    PerformerInterest::create([
        'performer_profile_id' => $profile->id,
        'member_id' => $member->id,
        'status' => 'sent',
        'sent_at' => now(),
    ]);

    $this->actingAs($profile->user)
        ->post(route('performer.profile.save'), ['stage_name' => 'Bianca'])
        ->assertRedirect();

    // Follows e interesses referenciam o id, não o slug — o rename não os toca.
    expect(Follow::where('performer_profile_id', $profile->id)->count())->toBe(1);
    expect(PerformerInterest::where('performer_profile_id', $profile->id)->count())->toBe(1);
});

it('replaces the avatar and discards the previous file', function () {
    Storage::fake('local');
    $profile = ppePerformer();

    $this->actingAs($profile->user)
        ->post(route('performer.profile.photo'), ['file' => UploadedFile::fake()->image('first.jpg')])
        ->assertRedirect();

    $first = $profile->fresh()->avatar_path;
    Storage::disk('local')->assertExists($first);

    $this->actingAs($profile->user)
        ->post(route('performer.profile.photo'), ['file' => UploadedFile::fake()->image('second.png')])
        ->assertRedirect();

    $second = $profile->fresh()->avatar_path;
    Storage::disk('local')->assertExists($second);
    // Trocar jpg→png muda o caminho; sem o descarte o antigo ficaria órfão.
    Storage::disk('local')->assertMissing($first);
});

it('rejects an avatar that is not an accepted image', function () {
    Storage::fake('local');
    $profile = ppePerformer();

    $this->actingAs($profile->user)
        ->post(route('performer.profile.photo'), ['file' => UploadedFile::fake()->create('payload.php', 10)])
        ->assertSessionHasErrors('file');

    expect($profile->fresh()->avatar_path)->toBeNull();
});

it('ignores fields this screen does not offer', function () {
    $profile = ppePerformer();

    // A tela oferece nome e bio. Um POST forjado não pode mexer em tarifa ou
    // categoria por carona no mesmo request.
    $this->actingAs($profile->user)
        ->post(route('performer.profile.save'), [
            'stage_name' => 'Ana',
            'bio' => 'Bio nova',
            'category' => 'homens',
            'rate_public' => 1,
        ])
        ->assertRedirect();

    $profile->refresh();
    expect($profile->bio)->toBe('Bio nova');
    expect($profile->category)->toBe('mulheres');
});

it('rejects an empty stage name', function () {
    $profile = ppePerformer('Ana');

    $this->actingAs($profile->user)
        ->post(route('performer.profile.save'), ['stage_name' => ''])
        ->assertSessionHasErrors('stage_name');

    expect($profile->fresh()->stage_name)->toBe('Ana');
});

it('lets one performer edit only her own profile', function () {
    $mine = ppePerformer('Ana');
    $hers = ppePerformer('Carla');

    $this->actingAs($mine->user)
        ->post(route('performer.profile.save'), ['stage_name' => 'Bianca'])
        ->assertRedirect();

    // A rota resolve o perfil pela sessão; não há id no request para forjar.
    expect($hers->fresh()->stage_name)->toBe('Carla');
});

it('denies profile editing to a performer who is not active yet', function () {
    $pending = ppePerformer('Ana', status: 'pending');

    $this->actingAs($pending->user)->get(route('performer.profile.edit'))->assertForbidden();
    $this->actingAs($pending->user)
        ->post(route('performer.profile.save'), ['stage_name' => 'Bianca'])
        ->assertForbidden();

    expect($pending->fresh()->stage_name)->toBe('Ana');
});

it('denies profile editing to a member', function () {
    $member = User::factory()->create(['role' => 'consumer', 'status' => 'active']);

    $this->actingAs($member)->get(route('performer.profile.edit'))->assertForbidden();
});

it('denies profile editing to a guest', function () {
    $this->get(route('performer.profile.edit'))->assertRedirect(route('login'));
});

it('generates a slug for a performer who somehow never had one', function () {
    $profile = ppePerformer();
    $profile->forceFill(['slug' => null])->save();

    $this->actingAs($profile->user)
        ->post(route('performer.profile.save'), ['stage_name' => 'Ana', 'bio' => 'x'])
        ->assertRedirect();

    // Nome inalterado, mas sem slug: o perfil precisa passar a ter um.
    expect($profile->fresh()->slug)->toStartWith('ana-');
});

it('keeps slugs unique when two performers share a stage name', function () {
    $first = ppePerformer('Ana');
    $second = ppePerformer('Carla');

    $this->actingAs($second->user)
        ->post(route('performer.profile.save'), ['stage_name' => 'Ana'])
        ->assertRedirect();

    expect($second->fresh()->slug)->not->toBe($first->slug);
    expect(PerformerProfile::whereIn('slug', [$first->slug, $second->fresh()->slug])->count())->toBe(2);
});

it('refuses to rename into another performer stage name', function () {
    $victim = ppePerformer('Ana Prado');
    $impostor = ppePerformer('Bia');

    // O clone de identidade: a impostora fica verificada (o KYC é da identidade
    // legal, não do nome artístico), copia o avatar do catálogo público e passa
    // a receber as gorjetas da vítima.
    $this->actingAs($impostor->user)
        ->post(route('performer.profile.save'), ['stage_name' => 'Ana Prado'])
        ->assertSessionHasErrors('stage_name');

    expect($impostor->fresh()->stage_name)->toBe('Bia');
});

it('treats stage names as equal regardless of case and accents', function () {
    ppePerformer('Ana Prado');
    $impostor = ppePerformer('Bia');

    // A collation é utf8mb4_unicode_ci: variar caixa ou acento não pode abrir
    // uma brecha para o mesmo nome.
    foreach (['ana prado', 'ANA PRADO', 'Aná Prado'] as $variant) {
        $this->actingAs($impostor->user)
            ->post(route('performer.profile.save'), ['stage_name' => $variant])
            ->assertSessionHasErrors('stage_name');
    }

    expect($impostor->fresh()->stage_name)->toBe('Bia');
});

it('lets a performer save without changing her own name', function () {
    $profile = ppePerformer('Ana');

    // ignore() no próprio id: a unicidade não pode impedir que ela salve a bio
    // mantendo o nome dela mesma.
    $this->actingAs($profile->user)
        ->post(route('performer.profile.save'), ['stage_name' => 'Ana', 'bio' => 'Bio nova'])
        ->assertSessionHasNoErrors();

    expect($profile->fresh()->bio)->toBe('Bio nova');
});

it('refuses to register a performer with a taken stage name', function () {
    ppePerformer('Ana Prado');

    // O cadastro é a outra porta para o mesmo clone — mais lenta (precisa passar
    // no KYC), mas igualmente eficaz se ficar aberta.
    $this->post(route('register.store'), [
        'role' => 'performer',
        'name' => 'Fulana',
        'stage_name' => 'Ana Prado',
        'email' => 'nova@example.com',
        'password' => 'senha-forte-123',
        'password_confirmation' => 'senha-forte-123',
        'birthdate' => now()->subYears(25)->format('Y-m-d'),
        'terms_version' => '1.0',
        'lgpd_consent' => true,
    ])->assertSessionHasErrors('stage_name');
});

it('refuses to reuse the stage name of a performer who left', function () {
    $gone = ppePerformer('Ana Prado');
    $gone->delete();

    $impostor = ppePerformer('Bia');

    // Perfil soft-deleted continua contando: reciclar o nome de quem saiu é
    // clonar alguém que não está mais lá para reclamar.
    $this->actingAs($impostor->user)
        ->post(route('performer.profile.save'), ['stage_name' => 'Ana Prado'])
        ->assertSessionHasErrors('stage_name');
});

it('regenerates the slug on rename through the api too', function () {
    $profile = ppePerformer('Ana');
    $oldSlug = $profile->slug;

    // A API Sanctum é uma terceira superfície de rename. Se ela mantiver a regra
    // antiga, o nome antigo continua preso na URL por ali — a promessa de
    // privacidade do rename tem que valer em todas as portas.
    $this->actingAs($profile->user, 'sanctum')
        ->putJson(route('performer.profile.update'), ['stage_name' => 'Bianca'])
        ->assertOk();

    $profile->refresh();
    expect($profile->stage_name)->toBe('Bianca');
    expect($profile->slug)->not->toBe($oldSlug);
    expect($profile->slug)->toStartWith('bianca-');
});

it('records the rename in the audit log', function () {
    $profile = ppePerformer('Ana');

    $this->actingAs($profile->user)
        ->post(route('performer.profile.save'), ['stage_name' => 'Bianca'])
        ->assertRedirect();

    $log = AuditLog::where('action', 'performer_profile_updated')->sole();
    expect($log->user_id)->toBe($profile->user_id);
    expect($log->metadata['renamed'])->toBeTrue();
});
