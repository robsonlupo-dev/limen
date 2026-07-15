<?php

use App\Models\Follow;
use App\Models\PerformerProfile;
use App\Models\User;
use App\Services\InterestService;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * Aba "Interesses enviados" do painel da performer (Sprint 3).
 * Ver docs/INTEREST_SYSTEM_SPEC.md e docs/INTEREST_ANONYMITY_FLOOR.md.
 *
 * O grosso destes testes é sobre uma coisa só: a performer não pode descobrir
 * que um membro optou por sair de interesses. A tela mostra o histórico dela,
 * e o status 'suppressed' precisa ser indistinguível do estado que a linha
 * teria se o opt-out não existisse.
 */
function sentInterestsPerformer(): PerformerProfile
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

function sentInterestsFollower(PerformerProfile $profile, int $balance = 0): User
{
    $member = User::factory()->create(['role' => 'consumer', 'status' => 'active']);
    Follow::create(['user_id' => $member->id, 'performer_profile_id' => $profile->id]);

    if ($balance > 0) {
        app(TokenService::class)->credit($member, $balance, 'purchase');
    }

    return $member;
}

it('lista os interesses enviados com quem revelou e a cota do dia', function () {
    $profile = sentInterestsPerformer();

    $pending = sentInterestsFollower($profile);
    $payer = sentInterestsFollower($profile, 100);

    app(InterestService::class)->send($profile, $pending);
    $paid = app(InterestService::class)->send($profile, $payer);
    app(InterestService::class)->unlock($payer, $paid);

    $this->actingAs($profile->user)
        ->get(route('performer.interests.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Performer/Interests')
            ->where('stats.total_sent', 2)
            ->where('stats.total_unlocked', 1)
            // 5 de cota diária, 2 gastos.
            ->where('remainingToday', 3)
            ->where('dailyLimit', 5)
            ->has('interests.data', 2)
        );
});

it('mostra o membro anonimizado, sem nome nem email no payload', function () {
    $profile = sentInterestsPerformer();
    $member = sentInterestsFollower($profile);
    $member->update(['name' => 'Fulano Silva', 'email' => 'fulano@example.com']);

    app(InterestService::class)->send($profile, $member);

    $response = $this->actingAs($profile->user)->get(route('performer.interests.index'));

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('interests.data.0.label', 'Membro #' . $member->id)
        );

    $payload = $response->getContent();
    expect($payload)->not->toContain('Fulano Silva')
        ->and($payload)->not->toContain('fulano@example.com');
});

it('conta o interesse suprimido na cota do dia, como qualquer outro', function () {
    $profile = sentInterestsPerformer();
    $optedOut = sentInterestsFollower($profile);
    $optedOut->update(['interests_opt_out' => true]);

    app(InterestService::class)->send($profile, $optedOut);

    // Se o suprimido não gastasse cota, a própria cota entregava o opt-out.
    $this->actingAs($profile->user)
        ->get(route('performer.interests.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('remainingToday', 4)
            ->where('stats.total_sent', 1)
        );
});

it('exibe o interesse suprimido como aguardando, igual a quem não pagou', function () {
    $profile = sentInterestsPerformer();

    $optedOut = sentInterestsFollower($profile);
    $optedOut->update(['interests_opt_out' => true]);
    app(InterestService::class)->send($profile, $optedOut);

    $justPending = sentInterestsFollower($profile);
    app(InterestService::class)->send($profile, $justPending);

    $response = $this->actingAs($profile->user)->get(route('performer.interests.index'));

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.total_unlocked', 0)
            ->has('interests.data', 2)
            // As duas linhas precisam ser byte a byte o mesmo estado.
            ->where('interests.data.0.status', 'sent')
            ->where('interests.data.1.status', 'sent')
            ->where('interests.data.0.unlocked_at', null)
            ->where('interests.data.1.unlocked_at', null)
        );

    // 'suppressed' nunca pode chegar ao cliente.
    expect($response->getContent())->not->toContain('suppressed');
});

/**
 * A "armadilha do auto-unlock" descrita em docs/INTEREST_ANONYMITY_FLOOR.md.
 *
 * O membro já pagou para revelar esta performer, então um novo interesse dela
 * nasceria 'unlocked' de graça. Com o opt-out ligado, o serviço grava
 * 'suppressed' (suprimir vence o auto-unlock). A performer SABE que este membro
 * já a revelou — se a tela mostrar "aguardando", ela deduz o opt-out.
 */
it('mascara o suprimido como revelado quando ele teria nascido auto-revelado', function () {
    $profile = sentInterestsPerformer();
    $member = sentInterestsFollower($profile, 100);

    $first = app(InterestService::class)->send($profile, $member);
    app(InterestService::class)->unlock($member, $first);

    // Opt-out depois de já ter revelado, e novo envio após o cooldown.
    $member->update(['interests_opt_out' => true]);
    $this->travel(31)->days();
    $second = app(InterestService::class)->send($profile, $member->fresh());

    expect($second->status)->toBe('suppressed');

    $this->actingAs($profile->user)
        ->get(route('performer.interests.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            // Os dois: o desbloqueio real e o suprimido mascarado.
            ->where('stats.total_unlocked', 2)
            ->where('interests.data.0.id', $second->id)
            ->where('interests.data.0.status', 'unlocked')
            // Auto-revelado revela na hora do envio.
            ->where('interests.data.0.unlocked_at', $second->sent_at->format('d/m/Y'))
        );

    $this->travelBack();
});

/**
 * O espelho do caso acima: um desbloqueio POSTERIOR ao envio suprimido não o
 * teria auto-revelado. Se a máscara olhasse "já desbloqueou algum dia?" em vez
 * do ponto no tempo, este 'sent' viraria 'revelado' no instante em que o membro
 * pagasse pelo interesse antigo — vazando o opt-out justamente aí.
 */
it('não mascara o suprimido quando o desbloqueio do par veio depois do envio', function () {
    $profile = sentInterestsPerformer();
    $member = sentInterestsFollower($profile, 100);

    // 1) Interesse comum, ainda sem pagar.
    $first = app(InterestService::class)->send($profile, $member);

    // 2) Membro opta por sair; novo envio após o cooldown vira 'suppressed'.
    $member->update(['interests_opt_out' => true]);
    $this->travel(31)->days();
    $second = app(InterestService::class)->send($profile, $member->fresh());
    expect($second->status)->toBe('suppressed');

    // 3) O opt-out não esconde os interesses ANTERIORES a ele: o membro ainda
    //    vê o primeiro na caixa dele e pode pagar para revelar. Um dia depois
    //    do envio suprimido — é justamente a ordem que a máscara tem de honrar.
    $this->travel(1)->day();
    app(InterestService::class)->unlock($member->fresh(), $first->fresh());

    $this->actingAs($profile->user)
        ->get(route('performer.interests.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            // Só o desbloqueio real conta. O suprimido segue "aguardando".
            ->where('stats.total_unlocked', 1)
            ->where('interests.data.0.id', $second->id)
            ->where('interests.data.0.status', 'sent')
            ->where('interests.data.1.id', $first->id)
            ->where('interests.data.1.status', 'unlocked')
        );

    $this->travelBack();
});

it('só mostra os interesses da própria performer', function () {
    $mine = sentInterestsPerformer();
    $other = sentInterestsPerformer();

    app(InterestService::class)->send($other, sentInterestsFollower($other));

    $this->actingAs($mine->user)
        ->get(route('performer.interests.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('interests.data', 0)
            ->where('stats.total_sent', 0)
        );
});

it('bloqueia quem não é performer ativa', function () {
    $consumer = User::factory()->create(['role' => 'consumer', 'status' => 'active']);

    $this->actingAs($consumer)
        ->get(route('performer.interests.index'))
        ->assertForbidden();
});

it('exige autenticação', function () {
    $this->get(route('performer.interests.index'))->assertRedirect(route('login'));
});
