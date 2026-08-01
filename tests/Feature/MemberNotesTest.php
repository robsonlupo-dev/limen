<?php

use App\Models\Follow;
use App\Models\MemberNote;
use App\Models\PerformerProfile;
use App\Models\User;
use App\Services\DeletionService;
use App\Services\MemberNoteService;
use App\Services\ProfileVisitService;
use App\Support\FanAlias;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * Notas privadas da performer sobre membros (Sprint 11).
 *
 * O eixo destes testes é o mesmo do produto: a nota é da PERFORMER, cola no
 * FanAlias do membro, e o membro NUNCA a vê. O id cru dele não pode vazar, o
 * conteúdo é cifrado em repouso, e o Hard Delete leva a nota nos dois sentidos.
 */
function mnPerformer(): PerformerProfile
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);

    return $user->performerProfile()->create([
        'stage_name' => 'Perf '.Str::random(8),
        'slug' => 'perf-'.strtolower(Str::random(6)),
        'category' => 'mulheres',
        'is_verified' => true,
        'level' => 'iniciante',
        'split_pct' => 65,
    ]);
}

/** Membro que CONTA para os pisos: 7+ dias e e-mail verificado. */
function mnMember(): User
{
    return User::factory()->create([
        'role' => 'consumer',
        'status' => 'active',
        'email_verified_at' => now()->subDays(30),
        'created_at' => now()->subDays(30),
    ])->fresh();
}

/**
 * Semeia `count` seguidores elegíveis (destrava o Piso de Anonimato) e devolve
 * a lista. O primeiro serve de alvo das notas.
 *
 * @return array<int, User>
 */
function mnFollowers(PerformerProfile $profile, int $count = 5): array
{
    return collect(range(1, $count))->map(function () use ($profile) {
        $member = mnMember();
        Follow::create([
            'user_id' => $member->id,
            'performer_profile_id' => $profile->id,
            'discrete_mode' => false,
        ]);

        return $member;
    })->all();
}

function mnHandle(PerformerProfile $profile, User $member): string
{
    return FanAlias::handle($profile->id, $member->id);
}

it('cria uma nota sobre um seguidor', function () {
    $profile = mnPerformer();
    $target = mnFollowers($profile)[0];

    $this->actingAs($profile->user)
        ->putJson(route('performer.notes.save', mnHandle($profile, $target)), [
            'content' => 'Gosta de vinho, aniversário em março.',
        ])
        ->assertOk()
        ->assertJson(['saved' => true]);

    $note = MemberNote::where('performer_profile_id', $profile->id)
        ->where('user_id', $target->id)
        ->sole();

    expect($note->content)->toBe('Gosta de vinho, aniversário em março.');
});

it('atualiza a nota existente sem criar linha nova', function () {
    $profile = mnPerformer();
    $target = mnFollowers($profile)[0];
    $handle = mnHandle($profile, $target);

    $this->actingAs($profile->user)
        ->putJson(route('performer.notes.save', $handle), ['content' => 'Primeira versão.'])
        ->assertOk();

    $this->actingAs($profile->user)
        ->putJson(route('performer.notes.save', $handle), ['content' => 'Segunda versão.'])
        ->assertOk();

    $notes = MemberNote::where('performer_profile_id', $profile->id)
        ->where('user_id', $target->id)
        ->get();

    expect($notes)->toHaveCount(1)
        ->and($notes->first()->content)->toBe('Segunda versão.');
});

it('deleta a nota, e o delete é idempotente', function () {
    $profile = mnPerformer();
    $target = mnFollowers($profile)[0];
    $handle = mnHandle($profile, $target);

    app(MemberNoteService::class)->upsert($profile, $target, 'Apagar depois.');

    $this->actingAs($profile->user)
        ->deleteJson(route('performer.notes.destroy', $handle))
        ->assertOk()
        ->assertJson(['deleted' => true]);

    expect(MemberNote::where('user_id', $target->id)->count())->toBe(0);

    // Segundo DELETE não explode nem vaza que já não existe — resposta uniforme.
    $this->actingAs($profile->user)
        ->deleteJson(route('performer.notes.destroy', $handle))
        ->assertOk()
        ->assertJson(['deleted' => true]);
});

it('nunca vaza o id, nome ou e-mail do membro no payload', function () {
    $profile = mnPerformer();
    $target = mnFollowers($profile)[0];
    $target->update(['name' => 'Fulano Silva', 'email' => 'fulano@example.com']);

    app(MemberNoteService::class)->upsert($profile, $target, 'Nota qualquer.');

    // Tela de notas
    $notesResponse = $this->actingAs($profile->user)->get(route('performer.notes.index'));
    $notesResponse->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Performer/Notes')
            ->where('notes.data.0.member_handle', mnHandle($profile, $target))
            ->where('notes.data.0.label', FanAlias::label($profile->id, $target->id, 'Membro #'))
        );

    foreach ([$notesResponse, $this->actingAs($profile->user)->get(route('performer.followers'))] as $response) {
        $payload = $response->getContent();
        expect($payload)->not->toContain('Fulano Silva')
            ->and($payload)->not->toContain('fulano@example.com')
            // O id cru do membro não pode aparecer como valor de campo do membro.
            ->and($payload)->not->toContain('"user_id":'.$target->id)
            ->and($payload)->not->toContain('"member_id":'.$target->id);
    }
});

it('guarda o conteúdo cifrado no banco, legível só pelo cast', function () {
    $profile = mnPerformer();
    $target = mnFollowers($profile)[0];

    app(MemberNoteService::class)->upsert($profile, $target, 'Segredo em claro.');

    // O valor cru no banco não é o texto — é o blob do Crypt.
    $raw = DB::table('member_notes')
        ->where('performer_profile_id', $profile->id)
        ->where('user_id', $target->id)
        ->value('content');

    expect($raw)->not->toContain('Segredo em claro.')
        ->and(Crypt::decryptString($raw))->toBe('Segredo em claro.')
        // E o cast do model devolve o claro.
        ->and(MemberNote::where('user_id', $target->id)->value('content'))->toBe('Segredo em claro.');
});

it('rejeita handle inválido com 404', function () {
    $profile = mnPerformer();
    mnFollowers($profile); // piso destravado, mas o handle abaixo não é de ninguém

    $this->actingAs($profile->user)
        ->putJson(route('performer.notes.save', 'deadbeefdeadbeef'), ['content' => 'x'])
        ->assertNotFound();

    expect(MemberNote::count())->toBe(0);
});

it('rejeita conteúdo vazio com 422', function () {
    $profile = mnPerformer();
    $target = mnFollowers($profile)[0];

    $this->actingAs($profile->user)
        ->putJson(route('performer.notes.save', mnHandle($profile, $target)), ['content' => ''])
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('content');
});

it('não deixa anotar abaixo do Piso de Anonimato', function () {
    $profile = mnPerformer();
    // Só 4 seguidores elegíveis: a lista fica escondida, então o handle não
    // resolve — mesmo corte do Interesse.
    $target = mnFollowers($profile, 4)[0];

    $this->actingAs($profile->user)
        ->putJson(route('performer.notes.save', mnHandle($profile, $target)), ['content' => 'x'])
        ->assertNotFound();

    expect(MemberNote::count())->toBe(0);
});

it('não deixa uma performer tocar a nota de outra (handle é por par)', function () {
    $mine = mnPerformer();
    $other = mnPerformer();

    // O mesmo membro segue as duas.
    $shared = mnMember();
    Follow::create(['user_id' => $shared->id, 'performer_profile_id' => $mine->id, 'discrete_mode' => false]);
    Follow::create(['user_id' => $shared->id, 'performer_profile_id' => $other->id, 'discrete_mode' => false]);
    mnFollowers($mine, 4); // completa o piso de cada perfil
    mnFollowers($other, 4);

    // Cada performer anota sobre o mesmo membro — linhas SEPARADAS por perfil.
    app(MemberNoteService::class)->upsert($mine, $shared, 'Nota da minha.');
    app(MemberNoteService::class)->upsert($other, $shared, 'Nota da outra.');

    // O handle da OUTRA performer não resolve para MIM: é derivado por par.
    $this->actingAs($mine->user)
        ->putJson(route('performer.notes.save', mnHandle($other, $shared)), ['content' => 'invasão'])
        ->assertNotFound();

    // A nota da outra ficou intacta.
    expect(MemberNote::where('performer_profile_id', $other->id)->where('user_id', $shared->id)->value('content'))
        ->toBe('Nota da outra.');
});

it('o Hard Delete do MEMBRO apaga as notas escritas sobre ele', function () {
    $a = mnPerformer();
    $b = mnPerformer();

    $target = mnMember();
    Follow::create(['user_id' => $target->id, 'performer_profile_id' => $a->id, 'discrete_mode' => false]);
    Follow::create(['user_id' => $target->id, 'performer_profile_id' => $b->id, 'discrete_mode' => false]);

    app(MemberNoteService::class)->upsert($a, $target, 'Nota da A sobre o membro.');
    app(MemberNoteService::class)->upsert($b, $target, 'Nota da B sobre o membro.');

    expect(MemberNote::where('user_id', $target->id)->count())->toBe(2);

    app(DeletionService::class)->executeDeletion($target->fresh());

    expect(MemberNote::where('user_id', $target->id)->count())->toBe(0);
});

it('o Hard Delete da PERFORMER apaga as notas que ela escreveu', function () {
    $profile = mnPerformer();
    $followers = mnFollowers($profile, 3);

    foreach ($followers as $i => $member) {
        app(MemberNoteService::class)->upsert($profile, $member, "Nota {$i}.");
    }

    expect(MemberNote::where('performer_profile_id', $profile->id)->count())->toBe(3);

    app(DeletionService::class)->executeDeletion($profile->user->fresh());

    expect(MemberNote::where('performer_profile_id', $profile->id)->count())->toBe(0);
});

it('o membro nunca alcança as rotas de notas', function () {
    $profile = mnPerformer();
    $target = mnFollowers($profile)[0];
    app(MemberNoteService::class)->upsert($profile, $target, 'Nota secreta sobre você.');

    // O próprio membro anotado, logado, não tem porta para as notas da performer.
    $this->actingAs($target)
        ->get(route('performer.notes.index'))
        ->assertForbidden();

    $this->actingAs($target)
        ->putJson(route('performer.notes.save', mnHandle($profile, $target)), ['content' => 'apagar'])
        ->assertForbidden();

    // E o conteúdo não aparece quando o membro vê o perfil público da performer.
    $public = $this->actingAs($target)->get(route('catalog.show', $profile->slug));
    expect($public->getContent())->not->toContain('Nota secreta sobre você.');
});

it('exige autenticação', function () {
    $this->get(route('performer.notes.index'))->assertRedirect(route('login'));
});

it('mostra a nota inline no painel de seguidores', function () {
    $profile = mnPerformer();
    $target = mnFollowers($profile)[0];
    app(MemberNoteService::class)->upsert($profile, $target, 'Nota visível no card.');

    $handle = mnHandle($profile, $target);

    $this->actingAs($profile->user)
        ->get(route('performer.followers'))
        ->assertOk()
        ->assertInertia(function (Assert $page) use ($handle) {
            $followers = collect($page->toArray()['props']['followers']['data']);
            $row = $followers->firstWhere('member_handle', $handle);
            expect($row['note'])->toBe('Nota visível no card.');
        });
});

/**
 * Caminho do VISITANTE: um membro que VISITOU (sem seguir) pode ser anotado,
 * resolvido pelo painel de visitantes — o outro conjunto que a performer vê.
 */
it('resolve o handle de um visitante que não segue', function () {
    test()->travelTo(Carbon::parse('2026-07-21 15:00', ProfileVisitService::DISPLAY_TIMEZONE));

    $profile = mnPerformer();
    mnFollowers($profile, 5); // destrava o piso de seguidores (pré-requisito do painel)

    // 5 visitantes elegíveis e distintos, todos na mesma faixa horária (k=3 ok).
    $visitors = collect(range(1, 5))->map(function () use ($profile) {
        $member = mnMember();
        test()->actingAs($member)->get(route('catalog.show', $profile->slug))->assertOk();

        return $member;
    })->all();

    $target = $visitors[0];

    // Confirma que o alvo NÃO segue — a resolução tem de vir do painel.
    expect(Follow::where('user_id', $target->id)->where('performer_profile_id', $profile->id)->exists())
        ->toBeFalse();

    test()->actingAs($profile->user)
        ->putJson(route('performer.notes.save', mnHandle($profile, $target)), ['content' => 'Veio pelo catálogo.'])
        ->assertOk();

    expect(MemberNote::where('performer_profile_id', $profile->id)->where('user_id', $target->id)->value('content'))
        ->toBe('Veio pelo catálogo.');

    test()->travelBack();
});
