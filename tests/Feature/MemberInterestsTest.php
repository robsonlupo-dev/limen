<?php

use App\Models\Follow;
use App\Models\PerformerProfile;
use App\Models\User;
use App\Services\DeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Interesses e "o que estou buscando" do MEMBRO (Sprint 9).
 *
 * Os interesses vivem na junção `member_interest`, pelo mesmo motivo das tags
 * da performer (R8 do backlog: whereJsonContains não usa índice) e sobre o
 * MESMO conjunto de slugs, que é o que torna o cruzamento de afinidade do
 * Sprint 10 possível.
 *
 * O que estes testes travam: a semântica da escrita (sync idempotente, limpeza
 * deliberada), o teto de 8, o filtro TIPO 1 no `seeking`, o expurgo no Hard
 * Delete — que NÃO sai pela FK, porque `users` é soft-delete — e, sobretudo, a
 * regra de privacidade: nada disso chega à performer.
 *
 * Helpers locais com prefixo mi*.
 */
function miMember(): User
{
    return User::factory()->create(['role' => 'consumer', 'status' => 'active']);
}

function miPerformer(): PerformerProfile
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

// ─── CRUD ────────────────────────────────────────────────────────────────────

it('renders the member profile screen with the current selection', function () {
    $member = miMember();
    $member->syncInterests(['fitness', 'arte']);
    $member->update(['seeking' => 'Conversa boa, sem pressa.']);

    $this->actingAs($member)
        ->get(route('consumer.profile.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Consumer/Profile/Edit')
            ->where('profile.interests', fn ($i) => collect($i)->sort()->values()->all() === ['arte', 'fitness'])
            ->where('profile.seeking', 'Conversa boa, sem pressa.')
            ->etc()
        );
});

it('saves the interests and the seeking text', function () {
    $member = miMember();

    $this->actingAs($member)
        ->put(route('consumer.profile.update'), [
            'interests' => ['fitness', 'viajante', 'conversa'],
            'seeking' => 'Alguém para conversar sobre viagem.',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $member->refresh();

    expect($member->interestSlugs())->toEqualCanonicalizing(['fitness', 'viajante', 'conversa']);
    expect($member->seeking)->toBe('Alguém para conversar sobre viagem.');
});

it('replaces the interest set instead of appending to it', function () {
    $member = miMember();
    $member->syncInterests(['fitness', 'viajante', 'luxo']);

    $this->actingAs($member)
        ->put(route('consumer.profile.update'), ['interests' => ['fitness', 'yoga']])
        ->assertRedirect();

    expect($member->fresh()->interestSlugs())->toEqualCanonicalizing(['fitness', 'yoga']);
});

it('clears every interest when the screen posts an empty selection', function () {
    $member = miMember();
    $member->syncInterests(['fitness', 'viajante']);

    // Desmarcar tudo é escolha deliberada, não "campo ausente". O controller
    // distingue os dois com array_key_exists — com `! empty` os interesses
    // antigos sobreviveriam à limpeza, e é a única operação que o titular não
    // consegue refazer por outro caminho.
    $this->actingAs($member)
        ->put(route('consumer.profile.update'), ['interests' => []])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($member->fresh()->interestSlugs())->toBe([]);
});

it('stores an emptied seeking as null, not as an empty string', function () {
    $member = miMember();
    $member->update(['seeking' => 'Algo.']);

    $this->actingAs($member)
        ->put(route('consumer.profile.update'), ['seeking' => '   '])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    // Uma representação só para "não preenchido": duas ('' e null) fariam o
    // cruzamento do Sprint 10 tratar as duas, e uma seria esquecida.
    expect($member->fresh()->seeking)->toBeNull();
});

it('leaves the fields untouched when the request omits them', function () {
    $member = miMember();
    $member->syncInterests(['fitness', 'viajante']);
    $member->update(['seeking' => 'Original.']);

    $this->actingAs($member)
        ->put(route('consumer.profile.update'), [])
        ->assertRedirect();

    $member->refresh();

    expect($member->interestSlugs())->toEqualCanonicalizing(['fitness', 'viajante']);
    expect($member->seeking)->toBe('Original.');
});

it('is idempotent: re-posting the same selection writes nothing new', function () {
    $member = miMember();
    $member->syncInterests(['fitness', 'arte']);
    $idsBefore = $member->interests()->pluck('id')->sort()->values()->all();

    $member->syncInterests(['arte', 'fitness']);

    // O diff existe para não queimar ids nem sujar o binlog a cada gravação de
    // perfil que nem toca em interesse. Ids iguais provam que nada foi
    // reinserido.
    expect($member->fresh()->interests()->pluck('id')->sort()->values()->all())->toBe($idsBefore);
});

// ─── Limites e validação ─────────────────────────────────────────────────────

it('rejects more than the maximum number of interests', function () {
    $member = miMember();
    $tooMany = array_slice(PerformerProfile::allTags(), 0, User::MAX_INTERESTS + 1);

    $this->actingAs($member)
        ->put(route('consumer.profile.update'), ['interests' => $tooMany])
        ->assertSessionHasErrors('interests');

    expect($member->fresh()->interestSlugs())->toBe([]);
});

it('accepts exactly the maximum number of interests', function () {
    $member = miMember();
    $exactly = array_slice(PerformerProfile::allTags(), 0, User::MAX_INTERESTS);

    $this->actingAs($member)
        ->put(route('consumer.profile.update'), ['interests' => $exactly])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($member->fresh()->interestSlugs())->toHaveCount(User::MAX_INTERESTS);
});

it('shares the slug set with the performer tags', function () {
    $member = miMember();

    // O cruzamento de afinidade do Sprint 10 mede interseção. Um slug válido de
    // um lado só e o casamento nasceria com buracos silenciosos — por isso a
    // validação lê PerformerProfile::allTags(), e não uma lista própria.
    $this->actingAs($member)
        ->put(route('consumer.profile.update'), ['interests' => ['fitness', 'nao_existe']])
        ->assertSessionHasErrors('interests.1');

    expect($member->fresh()->interestSlugs())->toBe([]);
});

it('rejects the same interest twice instead of hitting the unique index', function () {
    $member = miMember();

    // Sem `distinct` na regra isto viraria Duplicate entry (500) no índice único
    // (user_id, tag_slug) em vez de erro de validação.
    $this->actingAs($member)
        ->put(route('consumer.profile.update'), ['interests' => ['fitness', 'fitness']])
        ->assertSessionHasErrors();

    expect($member->fresh()->interestSlugs())->toBe([]);
});

it('rejects a seeking text longer than the cap', function () {
    $member = miMember();

    $this->actingAs($member)
        ->put(route('consumer.profile.update'), ['seeking' => str_repeat('a', 1001)])
        ->assertSessionHasErrors('seeking');
});

// ─── Filtro de conteúdo TIPO 1 ───────────────────────────────────────────────

it('rejects a seeking text that offers a paid encounter', function () {
    $member = miMember();

    // TIPO 1 (`legal`) do ChatContentFilter. A razão aqui não é alcance — o
    // campo não é publicado — e sim o destino: o texto vai alimentar o
    // cruzamento de afinidade, e uma oferta de encontro pago viraria critério
    // de PAREAMENTO. Isso é pior do que publicá-la.
    $this->actingAs($member)
        ->put(route('consumer.profile.update'), ['seeking' => 'procuro programa, pago 300 reais'])
        ->assertSessionHasErrors('seeking');

    expect($member->fresh()->seeking)->toBeNull();
});

it('lets the seeking text keep what the filter deliberately allows', function () {
    $member = miMember();

    // As ressalvas do filtro valem inteiras (CLAUDE.md): encontro SEM valor
    // monetário passa, e `programa` sozinho é palavra comum. Barrar isso
    // derrubaria quem escreveu de boa-fé, que é o custo que o PO recusou.
    foreach (['Quero conhecer alguém para um jantar.', 'Meu programa favorito é maratonar série.'] as $text) {
        $this->actingAs($member)
            ->put(route('consumer.profile.update'), ['seeking' => $text])
            ->assertSessionHasNoErrors();

        expect($member->fresh()->seeking)->toBe($text);
    }
});

it('does not apply the conduct category to the seeking text', function () {
    $member = miMember();

    // Só TIPO 1, como no perfil da performer: num campo sobre si mesmo não há
    // alvo, e barrar conduta seria a plataforma editando o tom de voz de quem
    // escreve sobre o próprio desejo.
    $this->actingAs($member)
        ->put(route('consumer.profile.update'), ['seeking' => 'Quero uma puta gostosa.'])
        ->assertSessionHasNoErrors();
});

// ─── Privacidade: nada disso chega à performer ───────────────────────────────

/**
 * Varre a estrutura inteira atrás de uma agulha, em chave OU em valor. É o
 * formato do guard: asserção por caminho conhecido só cobre o que já existe, e
 * o risco aqui é a prop NOVA que alguém acrescenta sem saber da regra.
 */
function miContains(mixed $haystack, string $needle): bool
{
    if (is_array($haystack)) {
        foreach ($haystack as $key => $value) {
            if (is_string($key) && str_contains($key, $needle)) {
                return true;
            }
            if (miContains($value, $needle)) {
                return true;
            }
        }

        return false;
    }

    return is_string($haystack) && str_contains($haystack, $needle);
}

it('never exposes the member interests or seeking to the performer', function () {
    $profile = miPerformer();
    $member = miMember();

    // Slug e frase escolhidos para serem inconfundíveis na varredura.
    $member->syncInterests(['striptease', 'submissa']);
    $member->update(['seeking' => 'SEGREDO-DO-MEMBRO']);

    Follow::create([
        'user_id' => $member->id,
        'performer_profile_id' => $profile->id,
        'discrete_mode' => false,
    ]);

    // As superfícies em que a performer vê membro hoje. A lista pode estar
    // fechada pelo Piso de Anonimato — a asserção é de AUSÊNCIA, então vale
    // igual, e o teste continua válido quando o piso destravar.
    foreach ([route('performer.followers'), route('performer.dashboard')] as $url) {
        $response = $this->actingAs($profile->user)->get($url)->assertOk();
        $props = $response->viewData('page')['props'] ?? [];

        // Guard contra asserção vazia: um `props` vazio (rota que mudou de
        // forma, redirect engolido) faria todas as asserções abaixo passarem
        // sem ter olhado nada, e o teste viraria uma luz verde sem função.
        expect($props)->not->toBeEmpty();

        expect(miContains($props, 'SEGREDO-DO-MEMBRO'))->toBeFalse()
            ->and(miContains($props, 'seeking'))->toBeFalse()
            ->and(miContains($props, 'striptease'))->toBeFalse()
            ->and(miContains($props, 'submissa'))->toBeFalse();
    }
});

it('never exposes the member interests or seeking on the public catalog', function () {
    $profile = miPerformer();
    $member = miMember();
    $member->syncInterests(['striptease', 'submissa']);
    $member->update(['seeking' => 'SEGREDO-DO-MEMBRO']);

    // A performer tem as MESMAS tags. O catálogo público expõe as dela — é
    // vitrine — e é justamente por isso que a varredura por slug não serve
    // aqui: o que se prova é que a página não muda por causa do membro.
    $profile->syncTags(['striptease', 'submissa']);

    foreach ([route('performers.public'), route('performers.public.show', $profile->slug)] as $url) {
        $response = $this->get($url)->assertOk();
        $props = $response->viewData('page')['props'] ?? [];

        // Mesmo guard do teste acima, e aqui ele tem uma segunda função:
        // provar que a página REALMENTE carregou as tags da performer. Se
        // `striptease` não aparecesse nem para ela, a varredura estaria olhando
        // para o vazio.
        expect(miContains($props, 'striptease'))->toBeTrue();

        expect(miContains($props, 'SEGREDO-DO-MEMBRO'))->toBeFalse()
            ->and(miContains($props, 'seeking'))->toBeFalse();
    }
});

it('keeps the member profile screen behind the member gate', function () {
    $profile = miPerformer();

    // `role:consumer` no grupo: a performer não tem perfil de membro para
    // editar, e uma rota de membro aberta a ela seria a superfície que a regra
    // de privacidade acabou de fechar, pela porta dos fundos.
    $this->actingAs($profile->user)
        ->get(route('consumer.profile.edit'))
        ->assertForbidden();
});

it('keeps the member profile screen behind auth', function () {
    $this->get(route('consumer.profile.edit'))->assertRedirect(route('login'));
});

it('does not write the field contents into the audit log', function () {
    $member = miMember();

    $this->actingAs($member)
        ->put(route('consumer.profile.update'), [
            'interests' => ['striptease'],
            'seeking' => 'SEGREDO-DO-MEMBRO',
        ])
        ->assertRedirect();

    $entry = DB::table('audit_logs')
        ->where('user_id', $member->id)
        ->where('action', 'member_profile_updated')
        ->first();

    expect($entry)->not->toBeNull();

    // Só quais campos mudaram, nunca o valor: o audit_logs está FORA do alcance
    // do scrub do Hard Delete, então gravar o conteúdo faria dele uma segunda
    // cópia sobrevivente. Mesma razão pela qual o filtro do chat nunca registra
    // o corpo da mensagem.
    $metadata = $entry->metadata ?? '';
    expect($metadata)->not->toContain('SEGREDO-DO-MEMBRO')
        ->and($metadata)->not->toContain('striptease');
});

// ─── Hard Delete ─────────────────────────────────────────────────────────────

it('purges the interests and the seeking text on hard delete', function () {
    $member = miMember();
    $member->syncInterests(['fitness', 'viajante']);
    $member->update(['seeking' => 'SEGREDO-DO-MEMBRO']);

    app(DeletionService::class)->executeDeletion($member);

    // DELETE real, não soft: a FK cascadeOnDelete de member_interest NÃO
    // dispara, porque `users` é soft-delete e a linha do usuário continua na
    // tabela. Sem purgeMemberInterests() os interesses sobreviveriam.
    expect(DB::table('member_interest')->where('user_id', $member->id)->count())->toBe(0);

    expect(DB::table('users')->where('id', $member->id)->value('seeking'))->toBeNull();
});

it('records the interest purge in the deletion summary', function () {
    $member = miMember();
    $member->syncInterests(['fitness', 'viajante', 'arte']);

    $log = app(DeletionService::class)->executeDeletion($member);

    // O resumo é a prova de conformidade do expurgo — um passo que roda mas não
    // se conta não serve à auditoria.
    expect($log->data_summary['member_interests'])->toBe(3);
});

// ─── A razão de ser da junção ────────────────────────────────────────────────

it('lets an interest be filtered through an indexed join instead of a json scan', function () {
    $withInterest = miMember();
    $withInterest->syncInterests(['fitness']);
    $withoutInterest = miMember();
    $withoutInterest->syncInterests(['gourmet']);

    // A direção INVERSA ("quem se interessa por X") é a que o cruzamento de
    // afinidade do Sprint 10 vai percorrer, e é exatamente onde um json[] em
    // `users` viraria full scan. Este whereHas entra pelo índice de tag_slug.
    $found = User::query()
        ->whereHas('interests', fn ($q) => $q->where('tag_slug', 'fitness'))
        ->pluck('id')
        ->all();

    expect($found)->toBe([$withInterest->id]);
});
