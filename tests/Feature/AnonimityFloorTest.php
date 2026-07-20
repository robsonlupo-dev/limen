<?php

use App\Models\Follow;
use App\Models\PerformerProfile;
use App\Models\Subscription;
use App\Models\User;
use App\Services\FollowService;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

// ─── Helpers ────────────────────────────────────────────────────────────────
// Nomes próprios: os helpers de InterestSystemTest são funções globais.

function floorPerformer(): PerformerProfile
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);

    return $user->performerProfile()->create([
        'stage_name' => 'Perf ' . Str::random(4),
        'slug' => 'perf-' . strtolower(Str::random(6)),
        'category' => 'mulheres',
        'is_verified' => true,
        'level' => 'iniciante',
        'split_pct' => 65,
    ]);
}

/**
 * Membro. Nasce com 30 dias de conta por padrão: só contas antigas contam para
 * o piso, e os testes que não são sobre sybil precisam de seguidores que valham.
 * Passe $ageDays para exercitar o corte de idade.
 */
function floorMember(bool $discrete = false, int $ageDays = 30, bool $verified = true): User
{
    $member = User::factory()->create([
        'role' => 'consumer',
        'status' => 'active',
        'created_at' => now()->subDays($ageDays),
        'email_verified_at' => $verified ? now()->subDays($ageDays) : null,
    ]);

    if ($discrete) {
        $member->discrete_mode = true;
        $member->save();
    }

    return $member;
}

/** @return array<int, User> */
function followersFor(PerformerProfile $profile, int $count, bool $discrete = false, int $ageDays = 30, bool $verified = true): array
{
    return collect(range(1, $count))->map(function () use ($profile, $discrete, $ageDays, $verified) {
        $member = floorMember($discrete, $ageDays, $verified);
        Follow::create([
            'user_id' => $member->id,
            'performer_profile_id' => $profile->id,
            'discrete_mode' => $discrete,
        ]);

        return $member;
    })->all();
}

/** Membro com Círculo ativo do tier pedido. */
function memberOfCircle(string $slug): User
{
    $member = floorMember();
    Subscription::factory()->circle($slug)->create([
        'user_id' => $member->id,
        'status' => 'active',
        'current_period_end' => now()->addMonth(),
    ]);

    return $member;
}

function followersPage(PerformerProfile $profile)
{
    return test()->actingAs($profile->user)->get(route('performer.followers'));
}

// ─── Piso de Anonimato ───────────────────────────────────────────────────────

it('abaixo do piso a performer nao ve lista nenhuma, e sabe por que', function () {
    $profile = floorPerformer();
    followersFor($profile, 4);

    followersPage($profile)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Performer/Followers')
        ->where('below_floor', true)
        ->where('total_followers_label', 'Menos de 5')
        ->where('followers.data', [])
        ->where('floor_message', 'Para proteger o anonimato dos membros Limen, a lista de seguidores fica visível a partir de 5 seguidores.')
    );
});

it('a partir do piso a lista aparece, anonimizada', function () {
    $profile = floorPerformer();
    $members = followersFor($profile, 5);

    $response = followersPage($profile);

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('below_floor', false)
        ->where('total_followers_label', '5+')
        ->has('followers.data', 5)
        ->where('floor_message', null)
    );

    // Continua sem PII: só "Membro #id".
    $response->assertSee('Membro #' . $members[0]->id, false);
    expect($response->getContent())->not->toContain($members[0]->email);
});

it('nenhum id vaza no payload quando esta abaixo do piso', function () {
    $profile = floorPerformer();
    $members = followersFor($profile, 3);

    $response = followersPage($profile);

    // O ponto do piso: o id não pode estar em lugar nenhum da resposta, nem
    // escondido num campo que a UI não usa.
    foreach ($members as $member) {
        expect($response->getContent())->not->toContain('Membro #' . $member->id);
    }
});

// ─── Mitigação de sybil: idade de conta no piso ──────────────────────────────
//
// O ataque: a performer registra contas de consumidor, segue a si mesma com
// elas e destrava a lista. O próximo seguidor de verdade fica sozinho entre
// nomes que ela mesma plantou — o piso teria virado decoração. Contas de
// véspera são baratas; esperar uma semana por cada uma, muito menos.

it('conta nova nao conta para o piso', function () {
    $profile = floorPerformer();
    followersFor($profile, 5, ageDays: 3);

    followersPage($profile)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('below_floor', true)
        ->where('followers.data', [])
        // A faixa é exibição e conta todo mundo: "5+" com a lista escondida é
        // um estado legítimo, não uma inconsistência.
        ->where('total_followers_label', '5+')
    );
});

it('conta com exatamente 7 dias ja conta para o piso', function () {
    $profile = floorPerformer();
    followersFor($profile, 5, ageDays: 7);

    followersPage($profile)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('below_floor', false)
        ->has('followers.data', 5)
    );
});

it('conta com 8 dias conta para o piso', function () {
    $profile = floorPerformer();
    followersFor($profile, 5, ageDays: 8);

    followersPage($profile)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('below_floor', false)
        ->has('followers.data', 5)
    );
});

it('conta com 6 dias ainda nao conta: o corte e no dia 7', function () {
    $profile = floorPerformer();
    followersFor($profile, 5, ageDays: 6);

    followersPage($profile)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('below_floor', true)
        ->where('followers.data', [])
    );
});

it('4 contas novas mais 1 antiga fica abaixo do piso', function () {
    $profile = floorPerformer();
    followersFor($profile, 4, ageDays: 1);   // as sybils da performer
    followersFor($profile, 1);               // o membro real

    // Este é o ataque literal: sem o corte de idade, o membro real apareceria
    // sozinho entre 4 contas que a própria performer criou.
    followersPage($profile)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('below_floor', true)
        ->where('followers.data', [])
    );
});

it('4 contas novas mais 5 antigas libera a lista', function () {
    $profile = floorPerformer();
    followersFor($profile, 4, ageDays: 1);
    followersFor($profile, 5);

    // As 5 antigas bastam sozinhas: o piso é atingido por elas.
    followersPage($profile)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('below_floor', false)
        ->has('followers.data', 9)   // e as novas aparecem na lista
    );
});

it('conta nova aparece na lista quando o piso foi atingido por contas antigas', function () {
    $profile = floorPerformer();
    followersFor($profile, 5);
    $novato = followersFor($profile, 1, ageDays: 0)[0];

    // O corte de idade vale para DESTRAVAR, não para exibir: quem seguiu hoje é
    // um seguidor legítimo como qualquer outro depois que a lista abriu.
    $response = followersPage($profile);

    $response->assertInertia(fn (Assert $page) => $page->where('below_floor', false));
    expect($response->getContent())->toContain('Membro #' . $novato->id);
});

it('conta nova nao empurra o piso: some ao envelhecer o resto', function () {
    $profile = floorPerformer();
    followersFor($profile, 4);              // antigas: uma a menos que o piso
    followersFor($profile, 10, ageDays: 2); // dez novas não substituem a quinta

    followersPage($profile)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('below_floor', true)
        ->where('followers.data', [])
    );
});

it('performer nao envia Interesse enquanto o piso so tem contas novas', function () {
    $profile = floorPerformer();
    $sybils = followersFor($profile, 5, ageDays: 1);

    // O envio usa a MESMA fonte da tela. Se discordassem, varrer ids e ler
    // 404-vs-201 reconstruiria a lista que a tela esconde.
    foreach ($sybils as $sybil) {
        $this->actingAs($profile->user)
            ->postJson(route('performer.interests.send'), ['member_id' => $sybil->id])
            ->assertNotFound();
    }
});

it('o corte de idade e configuravel', function () {
    config(['interest.anonymity_floor_account_age_days' => 30]);

    $profile = floorPerformer();
    followersFor($profile, 5, ageDays: 10); // antigas para o padrão, novas p/ 30

    followersPage($profile)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('below_floor', true)
    );
});

it('discreto antigo conta para o piso, discreto novo nao', function () {
    $profile = floorPerformer();
    followersFor($profile, 5);
    followersFor($profile, 3, discrete: true, ageDays: 1);

    // As duas contagens do piso (total e visíveis) usam o mesmo corte: a conta
    // nova e discreta não entra em nenhuma das duas.
    followersPage($profile)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('below_floor', false)
        ->has('followers.data', 5)
    );
});

// ─── E-mail verificado no piso ───────────────────────────────────────────────
//
// A espera de 7 dias encarece a PRESSA, não o VOLUME: quem registra 200 contas
// num burst espera uma semana uma única vez. Exigir e-mail verificado cobra uma
// caixa de entrada real POR CONTA — o custo passa a escalar com o lote.

it('conta antiga sem email verificado nao conta para o piso', function () {
    $profile = floorPerformer();
    followersFor($profile, 5, verified: false);

    followersPage($profile)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('below_floor', true)
        ->where('followers.data', [])
        // De novo: a faixa conta todo mundo, é exibição.
        ->where('total_followers_label', '5+')
    );
});

it('conta antiga com email verificado conta para o piso', function () {
    $profile = floorPerformer();
    followersFor($profile, 5, verified: true);

    followersPage($profile)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('below_floor', false)
        ->has('followers.data', 5)
    );
});

it('4 verificadas mais 1 nao verificada fica abaixo do piso', function () {
    $profile = floorPerformer();
    followersFor($profile, 4);
    followersFor($profile, 1, verified: false);

    // A não verificada não fecha o piso — mesmo padrão do corte de idade.
    followersPage($profile)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('below_floor', true)
        ->where('followers.data', [])
    );
});

it('nao verificada aparece na lista quando o piso foi atingido por verificadas', function () {
    $profile = floorPerformer();
    followersFor($profile, 5);
    $naoVerificada = followersFor($profile, 1, verified: false)[0];

    // Os cortes valem para DESTRAVAR, não para exibir.
    $response = followersPage($profile);

    $response->assertInertia(fn (Assert $page) => $page->where('below_floor', false));
    expect($response->getContent())->toContain('Membro #' . $naoVerificada->id);
});

it('os dois cortes sao independentes: antiga+nao verificada e nova+verificada nao somam', function () {
    $profile = floorPerformer();
    followersFor($profile, 3, verified: false);          // antigas, sem verificar
    followersFor($profile, 3, ageDays: 1);               // verificadas, novas

    // 6 seguidores ativos e nenhum elegível: passar num corte não compensa
    // falhar no outro.
    followersPage($profile)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('below_floor', true)
        ->where('followers.data', [])
    );
});

it('performer nao envia Interesse a seguidor de conta nao verificada abaixo do piso', function () {
    $profile = floorPerformer();
    $naoVerificadas = followersFor($profile, 5, verified: false);

    // Tela e envio precisam concordar, senão o 404-vs-201 reconstrói a lista.
    foreach ($naoVerificadas as $membro) {
        $this->actingAs($profile->user)
            ->postJson(route('performer.interests.send'), ['member_id' => $membro->id])
            ->assertNotFound();
    }
});

// ─── Throttle no cadastro web ────────────────────────────────────────────────

it('cadastro web devolve 429 depois de 5 tentativas no mesmo minuto', function () {
    // Registro em lote é o caminho barato para plantar contas e destravar o
    // piso. O corte de idade encarece a pressa; o throttle encarece o volume.
    $payload = fn (int $i) => [
        'name' => 'Sybil ' . $i,
        'email' => "sybil{$i}@example.com",
        'password' => 'Password1',
        'password_confirmation' => 'Password1',
        'birthdate' => now()->subYears(25)->format('Y-m-d'),
        'accept_terms' => true,
        'lgpd_consent' => true,
        'role' => 'consumer',
        'terms_version' => '1.0',
    ];

    // Payload inválido de propósito (senha fraca): mantém o teste como guest e
    // mede o throttle, não o cadastro. O limiter conta a tentativa de qualquer
    // forma — é isso que faz dele uma barreira contra lote.
    foreach (range(1, 5) as $i) {
        $this->post(route('register.store'), array_merge($payload($i), [
            'password' => 'weak', 'password_confirmation' => 'weak',
        ]))->assertStatus(302);
    }

    $this->post(route('register.store'), $payload(6))->assertStatus(429);

    expect(User::where('email', 'sybil6@example.com')->exists())->toBeFalse();
});

it('o throttle do cadastro web vale para performer tambem', function () {
    // A rota é uma só; o papel vem no payload. Se o throttle fosse por papel,
    // alternar 'role' zeraria o contador.
    foreach (range(1, 5) as $i) {
        $this->post(route('register.store'), ['role' => 'performer'])->assertStatus(302);
    }

    $this->post(route('register.store'), ['role' => 'consumer'])->assertStatus(429);
});

// ─── Modo Discreto na lista ──────────────────────────────────────────────────

it('membro discreto conta para o total mas nao aparece na lista', function () {
    $profile = floorPerformer();
    $visible = followersFor($profile, 5);
    $hidden = followersFor($profile, 2, discrete: true);

    $response = followersPage($profile);

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('below_floor', false)
        ->where('total_followers_label', '5+')   // discretos contam
        ->has('followers.data', 5)      // mas não são listados
    );

    foreach ($hidden as $member) {
        expect($response->getContent())->not->toContain('Membro #' . $member->id);
    }
    expect($response->getContent())->toContain('Membro #' . $visible[0]->id);
});

it('discreto conta para o total mas nao substitui um visivel', function () {
    $profile = floorPerformer();
    followersFor($profile, 4);
    followersFor($profile, 1, discrete: true);

    // O piso vale para as DUAS contagens: total 5 (atingido) mas só 4 visíveis,
    // então a lista continua escondida. Medir só o total permitiria o caso
    // degenerado de 4 discretos + 1 visível exibindo um nome sozinho.
    followersPage($profile)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('below_floor', true)
        ->where('total_followers_label', '5+')
        ->where('followers.data', [])
    );
});

it('sem o discreto o piso nao seria atingido e nada apareceria', function () {
    $profile = floorPerformer();
    followersFor($profile, 4);

    // Mesmo cenário do teste anterior, menos o discreto: continua abaixo.
    followersPage($profile)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('below_floor', true)
        ->where('followers.data', [])
    );
});

it('divergencia entre a flag do follow e a do usuario esconde o membro', function () {
    $profile = floorPerformer();
    followersFor($profile, 5);

    // Drift: o usuário está discreto mas a linha do follow ficou para trás
    // (ex.: update parcial). Na dúvida, esconde.
    $drifted = floorMember(discrete: true);
    Follow::create([
        'user_id' => $drifted->id,
        'performer_profile_id' => $profile->id,
        'discrete_mode' => false,
    ]);

    $response = followersPage($profile);

    expect($response->getContent())->not->toContain('Membro #' . $drifted->id);
});

// ─── Toggle do Modo Discreto ─────────────────────────────────────────────────

it('membro Black ativa o Modo Discreto', function () {
    $member = memberOfCircle('black');

    $this->actingAs($member, 'sanctum')
        ->patchJson(route('consumer.preferences.discrete-mode'))
        ->assertOk()
        ->assertJson(['discrete_mode' => true]);

    expect($member->fresh()->discrete_mode)->toBeTrue();
});

it('membro Founders Circle ativa o Modo Discreto', function () {
    $member = memberOfCircle('founders_circle');

    $this->actingAs($member, 'sanctum')
        ->patchJson(route('consumer.preferences.discrete-mode'))
        ->assertOk()
        ->assertJson(['discrete_mode' => true]);
});

it('tiers abaixo de Black recebem 403', function (string $slug) {
    $member = memberOfCircle($slug);

    $this->actingAs($member, 'sanctum')
        ->patchJson(route('consumer.preferences.discrete-mode'))
        ->assertForbidden()
        ->assertJson(['message' => 'Modo Discreto disponível apenas para membros Black e Founders Circle']);

    expect($member->fresh()->discrete_mode)->toBeFalse();
})->with(['explorador', 'insider', 'prestige']);

it('membro sem assinatura recebe 403', function () {
    $member = floorMember();

    $this->actingAs($member, 'sanctum')
        ->patchJson(route('consumer.preferences.discrete-mode'))
        ->assertForbidden();

    expect($member->fresh()->discrete_mode)->toBeFalse();
});

it('assinatura Black expirada nao vale como elegivel', function () {
    $member = floorMember();
    Subscription::factory()->circle('black')->create([
        'user_id' => $member->id,
        'status' => 'active',
        'current_period_end' => now()->subDay(), // período vencido
    ]);

    $this->actingAs($member, 'sanctum')
        ->patchJson(route('consumer.preferences.discrete-mode'))
        ->assertForbidden();
});

it('o toggle desliga quando ja estava ligado', function () {
    $member = memberOfCircle('black');

    $this->actingAs($member, 'sanctum')->patchJson(route('consumer.preferences.discrete-mode'))
        ->assertJson(['discrete_mode' => true]);

    $this->actingAs($member->fresh(), 'sanctum')->patchJson(route('consumer.preferences.discrete-mode'))
        ->assertJson(['discrete_mode' => false]);

    expect($member->fresh()->discrete_mode)->toBeFalse();
});

// ─── Propagação ──────────────────────────────────────────────────────────────

it('ativar o modo atualiza todos os follows existentes', function () {
    $member = memberOfCircle('black');
    $profiles = collect(range(1, 3))->map(fn () => floorPerformer());

    foreach ($profiles as $profile) {
        Follow::create(['user_id' => $member->id, 'performer_profile_id' => $profile->id]);
    }

    $this->actingAs($member, 'sanctum')
        ->patchJson(route('consumer.preferences.discrete-mode'))
        ->assertOk();

    expect(Follow::where('user_id', $member->id)->where('discrete_mode', true)->count())->toBe(3);

    // E desligar reverte todos.
    $this->actingAs($member->fresh(), 'sanctum')
        ->patchJson(route('consumer.preferences.discrete-mode'))
        ->assertOk();

    expect(Follow::where('user_id', $member->id)->where('discrete_mode', false)->count())->toBe(3);
});

it('novo follow ja nasce discreto quando o membro esta em Modo Discreto', function () {
    $member = memberOfCircle('black');
    $this->actingAs($member, 'sanctum')->patchJson(route('consumer.preferences.discrete-mode'))->assertOk();

    $profile = floorPerformer();

    // Caminho web (FollowService) — é o que o frontend Vue usa de fato.
    app(FollowService::class)->follow($member->fresh(), $profile);

    $follow = Follow::where('user_id', $member->id)->where('performer_profile_id', $profile->id)->first();
    expect($follow->discrete_mode)->toBeTrue();
});

it('novo follow pela API tambem nasce discreto', function () {
    $member = memberOfCircle('black');
    $this->actingAs($member, 'sanctum')->patchJson(route('consumer.preferences.discrete-mode'))->assertOk();

    $profile = floorPerformer();

    $this->actingAs($member->fresh(), 'sanctum')
        ->postJson("/api/v1/performers/{$profile->slug}/follow")
        ->assertSuccessful();

    expect(Follow::where('user_id', $member->id)->where('performer_profile_id', $profile->id)
        ->value('discrete_mode'))->toBeTrue();
});

it('follow de membro normal nasce visivel', function () {
    $member = floorMember();
    $profile = floorPerformer();

    app(FollowService::class)->follow($member, $profile);

    expect(Follow::where('user_id', $member->id)->value('discrete_mode'))->toBeFalse();
});

// ─── Porta web (sessão) ──────────────────────────────────────────────────────

it('membro Black liga o Modo Discreto pela rota web, com flash de sucesso', function () {
    $member = memberOfCircle('black');

    $this->actingAs($member)
        ->patch(route('consumer.settings.discrete-mode'), ['discrete_mode' => true])
        ->assertRedirect()
        ->assertSessionHas('success', 'Modo Discreto ativado');

    expect($member->fresh()->discrete_mode)->toBeTrue();
});

it('a rota web desliga e avisa', function () {
    $member = memberOfCircle('black');
    $this->actingAs($member)->patch(route('consumer.settings.discrete-mode'), ['discrete_mode' => true]);

    $this->actingAs($member->fresh())
        ->patch(route('consumer.settings.discrete-mode'), ['discrete_mode' => false])
        ->assertSessionHas('success', 'Modo Discreto desativado');

    expect($member->fresh()->discrete_mode)->toBeFalse();
});

it('a rota web nega 403 a membro Explorador', function () {
    $member = memberOfCircle('explorador');

    $this->actingAs($member)
        ->patch(route('consumer.settings.discrete-mode'), ['discrete_mode' => true])
        ->assertForbidden();

    expect($member->fresh()->discrete_mode)->toBeFalse();
});

it('a tela de configuracoes compartilha o estado do Modo Discreto', function () {
    $member = memberOfCircle('black');

    $this->actingAs($member)->get(route('consumer.settings'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Consumer/Settings')
            ->where('auth.user.can_use_discrete_mode', true)
            ->where('auth.user.discrete_mode', false)
        );
});

it('membro sem tier ve can_use_discrete_mode falso', function () {
    $member = memberOfCircle('prestige');

    $this->actingAs($member)->get(route('consumer.settings'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.can_use_discrete_mode', false)
        );
});

// ─── Idempotência e saída do modo ────────────────────────────────────────────

it('repetir o mesmo valor nao desliga o modo sem querer', function () {
    $member = memberOfCircle('black');

    // Duplo clique / retry de rede: o cliente manda o estado desejado.
    foreach (range(1, 3) as $ignored) {
        $this->actingAs($member->fresh())
            ->patch(route('consumer.settings.discrete-mode'), ['discrete_mode' => true]);
    }

    expect($member->fresh()->discrete_mode)->toBeTrue();
});

it('quem perdeu o tier ainda consegue DESLIGAR o modo', function () {
    $member = memberOfCircle('black');
    $this->actingAs($member)->patch(route('consumer.settings.discrete-mode'), ['discrete_mode' => true]);

    // Assinatura Black lapsa: o membro segue discreto (não reexpomos por lapso
    // de pagamento), mas não pode ficar preso sem conseguir sair.
    Subscription::where('user_id', $member->id)->update(['status' => 'canceled']);

    $this->actingAs($member->fresh())
        ->patch(route('consumer.settings.discrete-mode'), ['discrete_mode' => false])
        ->assertSessionHas('success', 'Modo Discreto desativado');

    expect($member->fresh()->discrete_mode)->toBeFalse();
});

it('quem perdeu o tier NAO consegue ligar de novo', function () {
    $member = memberOfCircle('black');
    Subscription::where('user_id', $member->id)->update(['status' => 'canceled']);

    $this->actingAs($member->fresh())
        ->patch(route('consumer.settings.discrete-mode'), ['discrete_mode' => true])
        ->assertForbidden();
});

// ─── O piso e o Interesse Controlado precisam concordar ──────────────────────

it('performer nao envia Interesse a membro em Modo Discreto', function () {
    $profile = floorPerformer();
    followersFor($profile, 5);

    $discrete = floorMember(discrete: true);
    Follow::create([
        'user_id' => $discrete->id,
        'performer_profile_id' => $profile->id,
        'discrete_mode' => true,
    ]);

    // Ele não está na lista; mandar o id na mão tem de ser indistinguível de um
    // id que não existe, senão o envio vira oráculo do Modo Discreto.
    $this->actingAs($profile->user)
        ->postJson(route('performer.interests.send'), ['member_id' => $discrete->id])
        ->assertNotFound();
});

it('abaixo do piso a performer nao envia Interesse a ninguem', function () {
    $profile = floorPerformer();
    $members = followersFor($profile, 3);

    // Sem isto, varrer ids e ler 404-vs-201 reconstruiria a lista inteira que a
    // tela esconde — o piso viraria decoração.
    foreach ($members as $member) {
        $this->actingAs($profile->user)
            ->postJson(route('performer.interests.send'), ['member_id' => $member->id])
            ->assertNotFound();
    }
});

it('lista com poucos visiveis fica escondida mesmo com o total acima do piso', function () {
    $profile = floorPerformer();
    followersFor($profile, 4, discrete: true);
    followersFor($profile, 1);

    // 5 no total (piso atingido) mas só 1 visível: mostrar seria expor um único
    // "Membro #id" — exatamente o que o piso existe para impedir.
    followersPage($profile)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('below_floor', true)
        ->where('followers.data', [])
    );
});

// ─── Contador em faixas ──────────────────────────────────────────────────────

it('rotula a contagem de seguidores por faixa', function (int $count, string $expected) {
    $profile = floorPerformer();
    $profile->forceFill(['followers_count' => $count])->save();

    expect($profile->followersCountLabel())->toBe($expected);
})->with([
    'zero' => [0, 'Menos de 5'],
    'limite inferior' => [4, 'Menos de 5'],
    'entra na faixa 5+' => [5, '5+'],
    'topo do 5+' => [9, '5+'],
    'entra na faixa 10+' => [10, '10+'],
    'topo do 10+' => [49, '10+'],
    'entra na faixa 50+' => [50, '50+'],
    'entra na faixa 100+' => [100, '100+'],
    'topo das faixas' => [499, '100+'],
    // A partir de 500 o exato volta: nessa escala um incremento não identifica.
    'exato a partir de 500' => [500, '500'],
    'exato formatado' => [1247, '1.247'],
]);

it('o catalogo publico nunca expoe o numero exato abaixo de 500', function () {
    $profile = floorPerformer();
    $profile->forceFill(['followers_count' => 137])->save();

    $response = $this->get(route('performers.public'));

    $response->assertOk();
    // Nem no payload do Inertia, nem em campo que a UI não usa.
    expect($response->getContent())->toContain('100+');
    expect($response->getContent())->not->toContain('"followers_count"');
    expect($response->getContent())->not->toContain('137');
});

it('o perfil publico da performer mostra a faixa', function () {
    $profile = floorPerformer();
    $profile->forceFill(['followers_count' => 7])->save();

    $this->get(route('performers.public.show', $profile->slug))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('performer.followers_label', '5+'));
});

it('o dashboard da propria performer tambem ve a faixa, nao o numero', function () {
    $profile = floorPerformer();
    $profile->forceFill(['followers_count' => 3])->save();

    // Quem faz a correlação "contador subiu → foi fulano" é ela: faixar só as
    // telas públicas deixaria o ataque em pé.
    $this->actingAs($profile->user)->get(route('performer.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('followers', 'Menos de 5'));
});

it('a tela de seguidores mostra faixa e nao devolve o total exato ao front', function () {
    $profile = floorPerformer();
    followersFor($profile, 3);

    $response = followersPage($profile);

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('total_followers_label', 'Menos de 5')
        ->missing('total_followers')      // o raw fica no servidor
        ->missing('total_followers_raw')
    );
});

it('o piso continua decidindo pelo numero exato no servidor', function () {
    $profile = floorPerformer();
    followersFor($profile, 5);

    // A faixa é só exibição: quem decide o piso é a contagem real.
    followersPage($profile)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('below_floor', false)
        ->where('total_followers_label', '5+')
        ->has('followers.data', 5)
    );
});

// ─── Fim a fim ───────────────────────────────────────────────────────────────

it('membro que ativa o modo some da lista de quem ele ja seguia', function () {
    $profile = floorPerformer();
    followersFor($profile, 5);

    $member = memberOfCircle('black');
    Follow::create(['user_id' => $member->id, 'performer_profile_id' => $profile->id]);

    // Antes: aparece (6 seguidores, acima do piso).
    expect(followersPage($profile)->getContent())->toContain('Membro #' . $member->id);

    $this->actingAs($member, 'sanctum')
        ->patchJson(route('consumer.preferences.discrete-mode'))
        ->assertOk();

    // Depois: sumiu da lista, mas continua contando para o piso.
    $response = followersPage($profile);
    expect($response->getContent())->not->toContain('Membro #' . $member->id);
    $response->assertInertia(fn (Assert $page) => $page
        ->where('total_followers_label', '5+')
        ->has('followers.data', 5)
    );
});
