<?php

use App\Models\PerformerProfile;
use App\Models\User;
use App\Services\DocumentAcceptanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Chamada 1:1 com presença online (feat/online-presence-call). O botão "Pedir
 * chamada privada" no perfil só aparece quando a performer está online e NÃO em
 * live pública; ela recebe/atende a chamada em qualquer tela logada (CallIncoming
 * global no AppLayout), com a 1:1 INVISÍVEL (não liga is_live). A invariante de
 * invisibilidade proíbe expor "ocupada em 1:1" ao membro — o caso ocupada é
 * recusado pelo servidor (409), nunca exibido. Testes por fonte + payload. `opc`.
 */
beforeEach(fn () => config(['features.call_enabled' => true, 'features.live_enabled' => true]));

function opcPerformer(int $pricePerMinute = 10): PerformerProfile
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);
    $profile = $user->performerProfile()->create([
        'stage_name' => 'Perf '.Str::random(6),
        'slug' => 'perf-'.strtolower(Str::random(8)),
        'category' => 'mulheres',
        'worlds' => ['mulheres'],
        'is_verified' => true,
        'level' => 'iniciante',
        'split_pct' => 65,
        'call_price_per_minute' => $pricePerMinute,
        'call_max_duration_minutes' => 30,
    ]);
    app(DocumentAcceptanceService::class)->acceptAll($user, Request::create('/', 'POST'));

    return $profile->fresh();
}

function opcMember(): User
{
    return User::factory()->create(['role' => 'consumer', 'status' => 'active']);
}

function opcShow(User $member, PerformerProfile $profile)
{
    return test()->actingAs($member)->get(route('catalog.show', $profile->slug));
}

// ── Invisibilidade: o perfil NÃO expõe "ocupada em chamada" ao membro ───────

it('o payload do perfil NAO vaza ocupacao em chamada (sem busy_in_call)', function () {
    $profile = opcPerformer();

    opcShow(opcMember(), $profile)
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('Catalog/Show')
            // is_available e is_live (sinais públicos legítimos) existem…
            ->has('performer.is_available')
            ->has('performer.is_live')
            // …mas "ocupada em 1:1" NUNCA sai (seria oráculo de presença).
            ->missing('performer.busy_in_call'));
});

// ── Gate do botão (estático por fonte): só online + não-live ────────────────

it('o perfil só mostra Pedir chamada quando online e NAO em live (sem cruzar ocupacao)', function () {
    $src = file_get_contents(resource_path('js/Pages/Catalog/Show.vue'));

    expect($src)->toContain('const canInstantCall')
        ->toContain('props.performer.is_available')
        ->toContain('!props.performer.is_live')
        ->toContain('v-if="canInstantCall"')
        // invisibilidade: o gate NÃO depende de um sinal de ocupação vazado.
        ->not->toContain('busy_in_call');
});

// ── Host global: recebe fora da live, invisível, sem derrubar o canal ───────

it('o host global da chamada fica no AppLayout', function () {
    expect(file_get_contents(resource_path('js/Layouts/AppLayout.vue')))
        ->toContain('GlobalCallHost');
});

it('o host global recebe/atende fora da live, invisivel, e desliga no console da live', function () {
    $src = file_get_contents(resource_path('js/Components/GlobalCallHost.vue'));

    expect($src)
        ->toContain('CallIncoming')                        // recebe o pedido em qualquer tela
        ->toContain('role="performer"')                    // abre a PrivateCall do lado dela
        ->toContain("page.component === 'Performer/Live'")  // desliga no console da live (sem duplicar)
        ->toContain("role === 'performer'")                // só para performer
        ->toContain('call_enabled');                        // só com a feature ligada
});

it('o CallIncoming remove SO o proprio callback no unmount (nao derruba o canal compartilhado)', function () {
    $src = file_get_contents(resource_path('js/Components/CallIncoming.vue'));

    // stopListening nomeado — e NENHUMA chamada a `.leave(` (que mataria
    // MessageToast/avisos no mesmo canal user.{id}). Checamos a INVOCAÇÃO
    // (`.leave(`), não a palavra "leave" — senão o próprio comentário do código
    // que explica o fix derrubaria a asserção. Achado ALTO da revisão de segurança.
    expect($src)
        ->toContain("stopListening('.call.requested', onRequested)")
        ->not->toContain('.leave(');
});
