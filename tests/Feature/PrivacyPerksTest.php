<?php

use App\Models\Conversation;
use App\Models\Follow;
use App\Models\Message;
use App\Models\PerformerProfile;
use App\Models\ProfileVisit;
use App\Models\Subscription;
use App\Models\User;
use App\Services\ChatAccessService;
use App\Services\ChatService;
use App\Services\InterestService;
use App\Services\PrivacyPerkService;
use App\Services\ProfileVisitService;
use App\Services\TokenService;
use App\Support\FanAlias;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Perks de privacidade de Black / Founders Circle: Ghost Mode (visita
 * invisível), Status Invisível (presença não exposta) e Read Receipts
 * (confirmação de leitura desligável).
 *
 * A regra vive no PrivacyPerkService; estes testes cobrem os PONTOS DE
 * APLICAÇÃO, que é onde o perk se perde na prática — um toggle que grava a
 * preferência mas não muda o que a performer vê é pior do que não ter perk.
 */

// ─── Helpers ────────────────────────────────────────────────────────────────

function perkPerformer(): PerformerProfile
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);

    return $user->performerProfile()->create([
        'stage_name' => 'Perf '.Str::random(4),
        'slug' => 'perf-'.strtolower(Str::random(6)),
        'category' => 'mulheres',
        'is_verified' => true,
        'level' => 'iniciante',
        'split_pct' => 65,
    ]);
}

function perkMember(?string $circleSlug = null): User
{
    $member = User::factory()->create([
        'role' => 'consumer',
        'status' => 'active',
        'email_verified_at' => now()->subDays(30),
        'created_at' => now()->subDays(30),
    ]);

    if ($circleSlug !== null) {
        Subscription::factory()->circle($circleSlug)->create([
            'user_id' => $member->id,
            'status' => 'active',
            'current_period_end' => now()->addMonth(),
        ]);
    }

    return $member->fresh();
}

/**
 * Seguidores que CONTAM para o Piso de Anonimato (conta antiga + e-mail
 * verificado). Sem eles o painel de visitantes fica escondido, como a tela de
 * seguidores.
 *
 * @return array<int,User>
 */
function perkFollowers(PerformerProfile $profile, int $count): array
{
    return collect(range(1, $count))->map(function () use ($profile) {
        $member = perkMember();
        Follow::create([
            'user_id' => $member->id,
            'performer_profile_id' => $profile->id,
            'discrete_mode' => false,
        ]);

        return $member;
    })->all();
}

/** Performer com o piso de seguidores já destravado. */
function perkVisiblePerformer(): PerformerProfile
{
    $profile = perkPerformer();
    perkFollowers($profile, 5);

    return $profile;
}

/**
 * N membros distintos visitando o perfil — o painel também exige o piso em
 * VISITANTES, não só em seguidores.
 *
 * @return array<int,User>
 */
function perkVisitors(PerformerProfile $profile, int $count): array
{
    return collect(range(1, $count))->map(function () use ($profile) {
        $member = perkMember();
        test()->actingAs($member)->get(route('catalog.show', $profile->slug))->assertOk();

        return $member;
    })->all();
}

/** Par com conversa aberta e acesso pago — o cenário onde read_at é marcado. */
function perkChatPair(PerformerProfile $performer, ?string $circleSlug = null): array
{
    $member = perkMember($circleSlug);
    app(TokenService::class)->credit($member, 100, 'purchase');
    Follow::create(['user_id' => $member->id, 'performer_profile_id' => $performer->id]);

    $interest = app(InterestService::class)->send($performer, $member);
    app(InterestService::class)->unlock($member, $interest);

    $conversation = Conversation::where('member_id', $member->id)
        ->where('performer_profile_id', $performer->id)
        ->sole();

    // Assinante tem chat livre; sem Círculo é preciso comprar a janela.
    if ($circleSlug === null) {
        app(ChatAccessService::class)->openOrRenew($conversation, $member, (string) Str::uuid());
    }

    return [$member->fresh(), $conversation];
}

// ─── Feature 1 — Ghost Mode ─────────────────────────────────────────────────

it('nao registra visita de membro Black', function () {
    $performer = perkPerformer();
    $member = perkMember('black');

    $this->actingAs($member)->get(route('catalog.show', $performer->slug))->assertOk();

    expect(ProfileVisit::count())->toBe(0);
});

it('nao registra visita de membro Founders Circle', function () {
    $performer = perkPerformer();
    $member = perkMember('founders_circle');

    $this->actingAs($member)->get(route('catalog.show', $performer->slug))->assertOk();

    expect(ProfileVisit::count())->toBe(0);
});

it('registra visita de membro Explorador', function () {
    $performer = perkPerformer();
    $member = perkMember('explorador');

    $this->actingAs($member)->get(route('catalog.show', $performer->slug))->assertOk();

    $visit = ProfileVisit::sole();
    expect($visit->visitor_id)->toBe($member->id)
        ->and($visit->performer_profile_id)->toBe($performer->id);
});

it('registra visita de membro sem assinatura nenhuma', function () {
    $performer = perkPerformer();
    $member = perkMember();

    $this->actingAs($member)->get(route('catalog.show', $performer->slug))->assertOk();

    expect(ProfileVisit::count())->toBe(1);
});

it('registra visita tambem pela rota publica do perfil', function () {
    $performer = perkPerformer();
    $member = perkMember();

    $this->actingAs($member)->get(route('performers.public.show', $performer->slug))->assertOk();

    expect(ProfileVisit::count())->toBe(1);
});

it('nao registra visita de visitante anonimo', function () {
    $performer = perkPerformer();

    $this->get(route('performers.public.show', $performer->slug))->assertOk();

    expect(ProfileVisit::count())->toBe(0);
});

it('nao registra a performer visitando o proprio perfil', function () {
    $performer = perkPerformer();

    $this->actingAs($performer->user)->get(route('performers.public.show', $performer->slug))->assertOk();

    expect(ProfileVisit::count())->toBe(0);
});

it('deduplica recargas da mesma pagina numa janela curta', function () {
    $performer = perkPerformer();
    $member = perkMember();

    $this->actingAs($member)->get(route('catalog.show', $performer->slug))->assertOk();
    $this->actingAs($member)->get(route('catalog.show', $performer->slug))->assertOk();
    $this->actingAs($member)->get(route('catalog.show', $performer->slug))->assertOk();

    expect(ProfileVisit::count())->toBe(1);

    // Passada a janela, uma visita nova é visita nova.
    $this->travel(ProfileVisitService::DEDUPE_MINUTES + 1)->minutes();
    $this->actingAs($member)->get(route('catalog.show', $performer->slug))->assertOk();

    expect(ProfileVisit::count())->toBe(2);
});

it('a resposta do perfil e identica com e sem Ghost Mode', function () {
    $performer = perkPerformer();
    $ghost = perkMember('black');
    $plain = perkMember('explorador');

    // Se a página mudasse conforme a visita foi gravada, o perk seria
    // detectável de fora — bastaria comparar as duas respostas.
    $a = $this->actingAs($ghost)->get(route('catalog.show', $performer->slug));
    $b = $this->actingAs($plain)->get(route('catalog.show', $performer->slug));

    expect($a->status())->toBe($b->status());
});

it('mostra visitantes recentes no painel da performer sob pseudonimo', function () {
    $performer = perkVisiblePerformer();
    $visitors = perkVisitors($performer, 5);
    $member = $visitors[4];

    $this->actingAs($performer->user)
        ->get(route('performer.dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('visitorsVisible', true)
            ->has('visitors', 5)
            // O mais recente primeiro.
            ->where('visitors.0.fan', FanAlias::label($performer->id, $member->id)));
});

it('nunca entrega o id do membro na lista de visitantes', function () {
    $performer = perkVisiblePerformer();
    $member = perkVisitors($performer, 5)[4];

    $response = $this->actingAs($performer->user)->get(route('performer.dashboard'));

    // Só o pseudônimo e a hora saem daqui. Checar o id por substring seria
    // frágil (a data carrega dígitos), então a garantia é a forma: nenhuma
    // chave além destas duas, e o alias não é derivável de volta ao id.
    $visitors = $response->viewData('page')['props']['visitors'];
    expect(array_keys($visitors[0]))->toBe(['fan', 'visited_at'])
        ->and($visitors[0]['fan'])->toBe(FanAlias::label($performer->id, $member->id))
        ->and($visitors[0]['fan'])->not->toBe('Fã #'.$member->id);
});

it('o membro com Ghost Mode nao aparece no painel da performer', function () {
    $performer = perkVisiblePerformer();
    perkVisitors($performer, 5);
    $ghost = perkMember('black');

    $this->actingAs($ghost)->get(route('catalog.show', $performer->slug))->assertOk();

    $response = $this->actingAs($performer->user)->get(route('performer.dashboard'));

    $aliases = collect($response->viewData('page')['props']['visitors'])->pluck('fan');
    expect($aliases)->toHaveCount(5)
        ->and($aliases)->not->toContain(FanAlias::label($performer->id, $ghost->id));
});

it('o painel so mostra visitas das ultimas 24h', function () {
    $performer = perkVisiblePerformer();
    perkVisitors($performer, 5);

    $this->travel(ProfileVisitService::RECENT_HOURS + 1)->hours();

    $this->actingAs($performer->user)
        ->get(route('performer.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->has('visitors', 0));
});

// --- Piso de Anonimato sobre o painel de visitantes --------------------------
//
// A lista de visitantes é uma superfície NOVA de exposição do membro à
// performer, e não estava coberta pelo piso. Sem estes cortes, uma performer
// nova manda o link para uma pessoa e o painel identifica exatamente ela.

it('esconde o painel de visitantes abaixo do piso de seguidores', function () {
    $performer = perkPerformer(); // sem seguidores
    perkVisitors($performer, 5);

    $this->actingAs($performer->user)
        ->get(route('performer.dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('visitorsVisible', false)
            ->has('visitors', 0));
});

it('esconde o painel com poucos visitantes distintos, mesmo com o piso de seguidores destravado', function () {
    $performer = perkVisiblePerformer();
    perkVisitors($performer, 4);

    $this->actingAs($performer->user)
        ->get(route('performer.dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('visitorsVisible', false)
            ->has('visitors', 0));
});

it('contas de vespera nao destravam o piso de visitantes', function () {
    // Regressão. O piso de visitantes contava TODO visitante distinto, enquanto
    // o de seguidores já exigia 7 dias de conta + e-mail verificado. A performer
    // com o piso de seguidores destravado criava 4 contas, visitava o próprio
    // perfil com cada uma e o painel abria: o quinto alias, o único que ela não
    // plantou, era o visitante real — identificado por eliminação, casando o
    // horário de cada visita própria com a linha correspondente.
    $performer = perkVisiblePerformer();

    $puppets = collect(range(1, 4))->map(fn () => User::factory()->create([
        'role' => 'consumer',
        'status' => 'active',
        'email_verified_at' => null,   // e-mail nunca verificado
        'created_at' => now(),         // criada agora
    ]))->all();

    foreach ($puppets as $puppet) {
        $this->actingAs($puppet)->get(route('catalog.show', $performer->slug))->assertOk();
    }

    $real = perkMember(); // 30 dias de conta, e-mail verificado
    $this->actingAs($real)->get(route('catalog.show', $performer->slug))->assertOk();

    // 5 visitantes distintos, mas 1 só elegível: abaixo do piso.
    $this->actingAs($performer->user)
        ->get(route('performer.dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('visitorsVisible', false)
            ->has('visitors', 0));

    // As visitas dos puppets FORAM gravadas — o furo era no contador do piso,
    // não na gravação. Se algum dia passarem a não gravar, este teste vira
    // verde pelo motivo errado.
    expect(ProfileVisit::where('performer_profile_id', $performer->id)->count())->toBe(5);
});

it('conta de vespera aparece na lista depois que o piso destrava', function () {
    // O outro lado da regra: elegibilidade decide DESTRAVAR, não filtrar. Com o
    // piso já satisfeito por contas elegíveis, o visitante recém-criado aparece
    // na lista normalmente — senão o corte viraria um filtro permanente e a
    // performer perderia visitas legítimas de gente que acabou de se cadastrar.
    $performer = perkVisiblePerformer();
    perkVisitors($performer, 5); // 5 elegíveis: piso destravado

    $novato = User::factory()->create([
        'role' => 'consumer',
        'status' => 'active',
        'email_verified_at' => null,
        'created_at' => now(),
    ]);
    $this->actingAs($novato)->get(route('catalog.show', $performer->slug))->assertOk();

    $response = $this->actingAs($performer->user)->get(route('performer.dashboard'));
    $aliases = collect($response->viewData('page')['props']['visitors'])->pluck('fan');

    expect($aliases)->toHaveCount(6)
        ->and($aliases)->toContain(FanAlias::label($performer->id, $novato->id));
});

it('recarregar nao infla o piso de visitantes distintos', function () {
    $performer = perkVisiblePerformer();
    $member = perkMember();

    // Cinco visitas do MESMO membro não podem destravar um painel que exige
    // cinco pessoas — senão o piso cai com uma aba aberta.
    foreach (range(1, 5) as $i) {
        $this->actingAs($member)->get(route('catalog.show', $performer->slug))->assertOk();
        $this->travel(ProfileVisitService::DEDUPE_MINUTES + 1)->minutes();
    }

    $this->actingAs($performer->user)
        ->get(route('performer.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('visitorsVisible', false));
});

// --- Modo Discreto ----------------------------------------------------------

it('nao registra visita de membro em Modo Discreto', function () {
    $performer = perkPerformer();
    $member = perkMember('black');
    $member->discrete_mode = true;
    $member->save();

    $this->actingAs($member->fresh())->get(route('catalog.show', $performer->slug))->assertOk();

    expect(ProfileVisit::count())->toBe(0);
});

it('o discreto que lapsou o tier continua fora da lista de visitantes', function () {
    // Regra 3 do CLAUDE.md: perder o tier NÃO desativa o Modo Discreto. Sem a
    // checagem de discrete_mode, o lapso de pagamento reexporia o membro por
    // esta superfície — ele deixa de ser elegível ao Ghost Mode e voltaria a
    // ser gravado.
    $performer = perkPerformer();
    $member = perkMember('black');
    $member->discrete_mode = true;
    $member->save();

    Subscription::where('user_id', $member->id)->update(['current_period_end' => now()->subDay()]);

    $member = $member->fresh();
    expect($member->hasGhostMode())->toBeFalse(); // tier lapsado, perk indisponível

    $this->actingAs($member)->get(route('catalog.show', $performer->slug))->assertOk();

    expect(ProfileVisit::count())->toBe(0); // ...e ainda assim não é registrado
});

// --- Retenção ---------------------------------------------------------------

it('ligar o Ghost Mode apaga as visitas ja registradas', function () {
    $performer = perkPerformer();
    $member = perkMember('black');

    // Desliga, visita (fica registrado), religa.
    $this->actingAs($member)
        ->patch(route('consumer.settings.privacy'), ['perk' => 'ghost_mode', 'enabled' => false]);
    $this->actingAs($member->fresh())->get(route('catalog.show', $performer->slug))->assertOk();
    expect(ProfileVisit::count())->toBe(1);

    $this->actingAs($member->fresh())
        ->patch(route('consumer.settings.privacy'), ['perk' => 'ghost_mode', 'enabled' => true])
        ->assertRedirect();

    // Sem isto o perk levaria até 24h para fazer efeito no painel da performer.
    expect(ProfileVisit::count())->toBe(0);
});

it('o expurgo por retencao apaga visitas antigas e preserva as recentes', function () {
    $performer = perkPerformer();
    $member = perkMember();
    $this->actingAs($member)->get(route('catalog.show', $performer->slug))->assertOk();

    $this->travel(ProfileVisitService::RETENTION_DAYS + 1)->days();
    $other = perkMember();
    $this->actingAs($other)->get(route('catalog.show', $performer->slug))->assertOk();

    $this->artisan('visits:purge')->assertSuccessful();

    expect(ProfileVisit::count())->toBe(1)
        ->and(ProfileVisit::sole()->visitor_id)->toBe($other->id);
});

it('o Black que desliga o Ghost Mode volta a ser registrado', function () {
    $performer = perkPerformer();
    $member = perkMember('black');

    $this->actingAs($member)
        ->patch(route('consumer.settings.privacy'), ['perk' => 'ghost_mode', 'enabled' => false])
        ->assertRedirect();

    $this->actingAs($member->fresh())->get(route('catalog.show', $performer->slug))->assertOk();

    expect(ProfileVisit::count())->toBe(1);
});

// ─── Feature 2 — Status Invisível ───────────────────────────────────────────
//
// Não há presença de membro no produto (nenhum presence channel, nenhum
// indicador de "membro online" em tela) — `is_live` é flag da PERFORMER. Estes
// testes travam o GATE e o contrato que ele publica, para que qualquer
// implementação futura de presença tenha que consultá-lo antes de expor alguém.

it('Black e FC tem status invisivel por padrao', function () {
    expect(perkMember('black')->hasInvisibleStatus())->toBeTrue()
        ->and(perkMember('founders_circle')->hasInvisibleStatus())->toBeTrue();
});

it('tiers inferiores nao tem status invisivel', function () {
    expect(perkMember('explorador')->hasInvisibleStatus())->toBeFalse()
        ->and(perkMember('prestige')->hasInvisibleStatus())->toBeFalse()
        ->and(perkMember()->hasInvisibleStatus())->toBeFalse();
});

it('publica o gate de invisibilidade para o front', function () {
    $this->actingAs(perkMember('black'))
        ->get(route('consumer.settings'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.is_invisible', true)
            ->where('auth.user.privacy.invisible_status', true)
            ->where('auth.user.privacy.eligible', true));

    $this->actingAs(perkMember('explorador'))
        ->get(route('consumer.settings'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.is_invisible', false)
            ->where('auth.user.privacy.eligible', false));
});

it('nao existe canal de presenca expondo membro', function () {
    // Guarda de regressão: se um presence channel entrar sem consultar o gate,
    // este teste cai e obriga a decisão a passar por aqui.
    $channels = file_get_contents(base_path('routes/channels.php'));

    expect($channels)->not->toContain('presence-');
});

// ─── Feature 3 — Read Receipts ──────────────────────────────────────────────

/** O que a performer VÊ: read_at das mensagens dela na tela dela. */
function perkPerformerSeesReadReceipt(PerformerProfile $performer, Conversation $conversation): bool
{
    $response = test()->actingAs($performer->user)->get(route('chat.show', $conversation->id));
    $messages = $response->viewData('page')['props']['messages']['data'];

    return collect($messages)
        ->where('sender_id', $performer->user_id)
        ->contains(fn ($m) => $m['read_at'] !== null);
}

it('nao confirma leitura do membro FC para a performer, por padrao', function () {
    $performer = perkPerformer();
    [$member, $conversation] = perkChatPair($performer, 'founders_circle');
    app(ChatService::class)->sendMessage($conversation, $performer->user, 'oi');

    $this->actingAs($member)->get(route('chat.show', $conversation->id))->assertOk();

    expect(perkPerformerSeesReadReceipt($performer, $conversation))->toBeFalse();
});

it('nao confirma leitura do membro Black para a performer, por padrao', function () {
    $performer = perkPerformer();
    [$member, $conversation] = perkChatPair($performer, 'black');
    app(ChatService::class)->sendMessage($conversation, $performer->user, 'oi');

    $this->actingAs($member)->get(route('chat.show', $conversation->id))->assertOk();

    expect(perkPerformerSeesReadReceipt($performer, $conversation))->toBeFalse();
});

it('confirma leitura para tier inferior', function () {
    $performer = perkPerformer();
    [$member, $conversation] = perkChatPair($performer, 'explorador');
    app(ChatService::class)->sendMessage($conversation, $performer->user, 'oi');

    $this->actingAs($member)->get(route('chat.show', $conversation->id))->assertOk();

    expect(perkPerformerSeesReadReceipt($performer, $conversation))->toBeTrue();
});

it('o perk esconde a confirmacao sem quebrar o contador do proprio membro', function () {
    // A marcação continua acontecendo: `read_at` alimenta TAMBÉM o unread_count
    // da caixa do membro. Não marcar deixaria o Black com a própria lista de
    // conversas eternamente em negrito — o perk cobraria dele um preço que não
    // é o dele. O gate é de entrega, não de escrita.
    $performer = perkPerformer();
    [$member, $conversation] = perkChatPair($performer, 'black');
    app(ChatService::class)->sendMessage($conversation, $performer->user, 'oi');

    $this->actingAs($member)->get(route('chat.show', $conversation->id))->assertOk();

    $this->actingAs($member)
        ->get(route('chat.index'))
        ->assertInertia(fn (Assert $page) => $page->where('conversations.data.0.unread_count', 0));

    // ...e mesmo assim a performer não vê confirmação nenhuma.
    expect(perkPerformerSeesReadReceipt($performer, $conversation))->toBeFalse();
});

it('o gate e do LEITOR, nao do remetente', function () {
    $performer = perkPerformer();
    [$member, $conversation] = perkChatPair($performer, 'black');

    // O Black ENVIA. A performer lê — e a leitura DELA continua confirmada
    // para ele: quem desligou receipts foi o membro, e desligar esconde a
    // leitura DELE, não a da contraparte.
    $this->actingAs($member)
        ->postJson(route('chat.messages.store', $conversation->id), ['body' => 'do membro'])
        ->assertStatus(201);

    $this->actingAs($performer->user)->get(route('chat.show', $conversation->id))->assertOk();

    expect(Message::where('sender_id', $member->id)->sole()->read_at)->not->toBeNull();

    $response = $this->actingAs($member->fresh())->get(route('chat.show', $conversation->id));
    $mine = collect($response->viewData('page')['props']['messages']['data'])
        ->firstWhere('sender_id', $member->id);
    expect($mine['read_at'])->not->toBeNull();
});

it('o Black que liga a confirmacao de leitura volta a confirmar', function () {
    $performer = perkPerformer();
    [$member, $conversation] = perkChatPair($performer, 'black');
    app(ChatService::class)->sendMessage($conversation, $performer->user, 'oi');

    $this->actingAs($member)
        ->patch(route('consumer.settings.privacy'), ['perk' => 'read_receipts_enabled', 'enabled' => true])
        ->assertRedirect();

    $this->actingAs($member->fresh())->get(route('chat.show', $conversation->id))->assertOk();

    expect(perkPerformerSeesReadReceipt($performer, $conversation))->toBeTrue();
});

it('so entrega read_at das proprias mensagens', function () {
    $performer = perkPerformer();
    [$member, $conversation] = perkChatPair($performer, 'explorador');
    app(ChatService::class)->sendMessage($conversation, $performer->user, 'da performer');

    $response = $this->actingAs($member)->get(route('chat.show', $conversation->id));

    // A mensagem é da performer: o membro não recebe o read_at dela (que seria
    // o registro do próprio membro tendo lido).
    $messages = $response->viewData('page')['props']['messages']['data'];
    expect($messages[0]['sender_id'])->toBe($performer->user_id)
        ->and($messages[0]['read_at'])->toBeNull();
});

// ─── Toggles e autorização ──────────────────────────────────────────────────

it('tier inferior nao consegue ativar ghost mode', function () {
    $member = perkMember('explorador');

    $this->actingAs($member)
        ->patch(route('consumer.settings.privacy'), ['perk' => 'ghost_mode', 'enabled' => true])
        ->assertForbidden();

    expect($member->fresh()->ghost_mode)->toBeNull();
});

it('membro sem assinatura nao consegue ativar nenhum perk', function () {
    $member = perkMember();

    foreach ([['ghost_mode', true], ['invisible_status', true], ['read_receipts_enabled', false]] as [$perk, $value]) {
        $this->actingAs($member)
            ->patch(route('consumer.settings.privacy'), ['perk' => $perk, 'enabled' => $value])
            ->assertForbidden();
    }

    $member = $member->fresh();
    expect($member->ghost_mode)->toBeNull()
        ->and($member->invisible_status)->toBeNull()
        ->and($member->read_receipts_enabled)->toBeNull();
});

it('desligar o perk e sempre permitido, mesmo sem o tier', function () {
    // Ativou como Black e depois lapsou: nunca pode ficar preso no modo.
    $member = perkMember();
    $member->ghost_mode = true;
    $member->save();

    $this->actingAs($member)
        ->patch(route('consumer.settings.privacy'), ['perk' => 'ghost_mode', 'enabled' => false])
        ->assertRedirect();

    expect($member->fresh()->ghost_mode)->toBeFalse();
});

it('o toggle de ghost mode persiste entre sessoes', function () {
    $performer = perkPerformer();
    $member = perkMember('black');

    $this->actingAs($member)
        ->patch(route('consumer.settings.privacy'), ['perk' => 'ghost_mode', 'enabled' => false])
        ->assertRedirect();

    // Sessão nova: o valor veio do banco, não da sessão anterior.
    auth()->logout();
    $this->actingAs(User::find($member->id))
        ->get(route('catalog.show', $performer->slug))
        ->assertOk();

    expect(User::find($member->id)->ghost_mode)->toBeFalse()
        ->and(ProfileVisit::count())->toBe(1);
});

it('a escolha explicita sobrevive ao lapso do tier', function () {
    $member = perkMember('black');

    $this->actingAs($member)
        ->patch(route('consumer.settings.privacy'), ['perk' => 'ghost_mode', 'enabled' => true])
        ->assertRedirect();

    // Assinatura vence: quem ESCOLHEU continuar invisível continua invisível.
    Subscription::where('user_id', $member->id)->update(['current_period_end' => now()->subDay()]);

    expect(User::find($member->id)->hasGhostMode())->toBeTrue();
});

it('recusa nome de perk fora da allowlist', function () {
    $member = perkMember('black');

    // Sem a allowlist, o nome do campo viria do request e viraria escrita
    // arbitrária numa coluna de `users`.
    $this->actingAs($member)
        ->patch(route('consumer.settings.privacy'), ['perk' => 'role', 'enabled' => true])
        ->assertSessionHasErrors('perk');

    expect($member->fresh()->role)->toBe('consumer');
});

it('recusa perk ou valor ausente', function () {
    $member = perkMember('black');

    $this->actingAs($member)
        ->patch(route('consumer.settings.privacy'), ['enabled' => true])
        ->assertSessionHasErrors('perk');

    $this->actingAs($member)
        ->patch(route('consumer.settings.privacy'), ['perk' => 'ghost_mode'])
        ->assertSessionHasErrors('enabled');
});

it('nao aceita os perks por mass assignment no registro', function () {
    // Mesma regra do discrete_mode: privilégio de tier não entra por formulário.
    $user = new User();
    $user->fill(['ghost_mode' => true, 'invisible_status' => true, 'read_receipts_enabled' => false]);

    expect($user->ghost_mode)->toBeNull()
        ->and($user->invisible_status)->toBeNull()
        ->and($user->read_receipts_enabled)->toBeNull();
});

it('a performer nao tem perks de privacidade de membro', function () {
    $performer = perkPerformer();

    expect($performer->user->hasGhostMode())->toBeFalse()
        ->and($performer->user->hasReadReceipts())->toBeTrue()
        ->and(app(PrivacyPerkService::class)->isEligible($performer->user))->toBeFalse();
});

it('aplicar o mesmo valor duas vezes e idempotente', function () {
    $member = perkMember('black');
    $perks = app(PrivacyPerkService::class);

    $perks->apply($member, PrivacyPerkService::GHOST_MODE, false);
    $updatedAt = $member->fresh()->updated_at;

    $this->travel(1)->minute();
    $perks->apply($member->fresh(), PrivacyPerkService::GHOST_MODE, false);

    expect($member->fresh()->updated_at->timestamp)->toBe($updatedAt->timestamp);
});

// ─── LGPD ───────────────────────────────────────────────────────────────────

it('o encerramento de conta apaga o historico de visitas do titular', function () {
    $performer = perkPerformer();
    $member = perkMember();
    $this->actingAs($member)->get(route('catalog.show', $performer->slug))->assertOk();

    expect(ProfileVisit::where('visitor_id', $member->id)->count())->toBe(1);

    app(App\Services\DeletionService::class)->requestDeletion($member);
    app(App\Services\DeletionService::class)->executeDeletion($member->fresh(), 'test');

    expect(ProfileVisit::where('visitor_id', $member->id)->count())->toBe(0);
});
