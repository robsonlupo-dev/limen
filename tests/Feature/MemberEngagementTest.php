<?php

use App\Models\Conversation;
use App\Models\Message;
use App\Models\PerformerHeart;
use App\Models\User;
use App\Services\ChatAccessService;
use App\Services\DeletionService;
use App\Services\PerformerHeartService;
use App\Support\FanAlias;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Motor de engajamento do catálogo de membros (home da performer): CORAÇÃO
 * (grátis, ilimitado, identidade da performer visível ao membro) e MENSAGEM
 * PERSONALIZADA (franquia diária grátis; o membro paga para LER o corpo pelo gate
 * de chat existente).
 *
 * Foco: as invariantes de economia e privacidade — o coração não move tokens e é
 * visível ao membro; a mensagem nasce visível-mas-bloqueada e consome a franquia;
 * o alvo sempre resolve contra os membros que a performer já vê no catálogo.
 */

// ─── Helpers ─────────────────────────────────────────────────────────────────

function engPerformer(): User
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);

    $user->performerProfile()->create([
        'stage_name' => 'Perf '.Str::random(8),
        'slug' => 'perf-'.strtolower(Str::random(6)),
        'category' => 'mulheres',
        'is_verified' => true,
        'level' => 'iniciante',
        'split_pct' => 65,
    ]);

    return $user->fresh();
}

function engMember(array $attrs = []): User
{
    return User::factory()->create(array_merge([
        'role' => 'consumer',
        'status' => 'active',
        'email_verified_at' => now(),
    ], $attrs));
}

function heartHandle(User $performer, User $member): string
{
    return FanAlias::handle($performer->performerProfile->id, $member->id);
}

// ─── CORAÇÃO ─────────────────────────────────────────────────────────────────

it('performer curte um membro do catálogo — grátis e sem mover tokens', function () {
    $performer = engPerformer();
    $member = engMember();

    $this->actingAs($performer)
        ->postJson(route('performer.members.heart'), ['member_handle' => heartHandle($performer, $member)])
        ->assertStatus(201)
        ->assertJson(['hearted' => true]);

    $this->assertDatabaseHas('performer_hearts', [
        'performer_profile_id' => $performer->performerProfile->id,
        'member_id' => $member->id,
    ]);
    // Coração é grátis: nenhum lançamento no ledger append-only.
    $this->assertDatabaseCount('token_ledger', 0);
});

it('recurtir é idempotente — não duplica linha', function () {
    $performer = engPerformer();
    $member = engMember();
    $handle = heartHandle($performer, $member);

    $this->actingAs($performer)->postJson(route('performer.members.heart'), ['member_handle' => $handle])->assertStatus(201);
    $this->actingAs($performer)->postJson(route('performer.members.heart'), ['member_handle' => $handle])->assertStatus(201);

    $this->assertDatabaseCount('performer_hearts', 1);
});

it('404 ao curtir membro fora do catálogo (oculto)', function () {
    $performer = engPerformer();
    $member = engMember(['visible_to_performers' => false]);

    $this->actingAs($performer)
        ->postJson(route('performer.members.heart'), ['member_handle' => heartHandle($performer, $member)])
        ->assertStatus(404);

    $this->assertDatabaseCount('performer_hearts', 0);
});

it('o membro vê quem o curtiu COM a identidade da performer (não anônima)', function () {
    $performer = engPerformer();
    $member = engMember();
    PerformerHeart::create([
        'performer_profile_id' => $performer->performerProfile->id,
        'member_id' => $member->id,
    ]);

    // A rota responde (Inertia resolve o componente no cliente) e o serviço
    // dono da regra devolve a identidade PÚBLICA da performer — nunca anônima.
    $this->actingAs($member)->get(route('consumer.hearts.index'))->assertOk();

    $rows = app(PerformerHeartService::class)->listForMember($member);
    expect($rows)->toHaveCount(1)
        ->and($rows->first()['stage_name'])->toBe($performer->performerProfile->stage_name)
        ->and($rows->first()['slug'])->toBe($performer->performerProfile->slug);
});

it('coração de performer não verificada não aparece para o membro', function () {
    $performer = engPerformer();
    $performer->performerProfile->update(['is_verified' => false]);
    $member = engMember();
    PerformerHeart::create([
        'performer_profile_id' => $performer->performerProfile->id,
        'member_id' => $member->id,
    ]);

    expect(app(PerformerHeartService::class)->listForMember($member))->toHaveCount(0);
});

it('o card do catálogo traz o estado hearted', function () {
    $performer = engPerformer();
    $member = engMember();
    PerformerHeart::create([
        'performer_profile_id' => $performer->performerProfile->id,
        'member_id' => $member->id,
    ]);

    $this->actingAs($performer)
        ->get(route('performer.members'))
        ->assertInertia(fn (Assert $p) => $p->where('members.data.0.hearted', true));
});

// ─── MENSAGEM PERSONALIZADA ──────────────────────────────────────────────────

it('mensagem nasce visível-mas-bloqueada: cria conversa/mensagem, membro não lê o corpo', function () {
    $performer = engPerformer();
    $member = engMember();

    $this->actingAs($performer)
        ->postJson(route('performer.members.message'), [
            'member_handle' => heartHandle($performer, $member),
            'body' => 'Oi, gostei do seu perfil :)',
        ])
        ->assertStatus(201)
        ->assertJson(['sent' => true]);

    $conversation = Conversation::where('member_id', $member->id)
        ->where('performer_profile_id', $performer->performerProfile->id)
        ->first();

    expect($conversation)->not->toBeNull();
    $this->assertDatabaseHas('messages', [
        'conversation_id' => $conversation->id,
        'sender_id' => $performer->id,
        'body' => 'Oi, gostei do seu perfil :)',
    ]);

    // O membro NÃO lê até abrir o chat pago (gate M.13.1 reusado).
    $state = app(ChatAccessService::class)->accessState($conversation, $member->fresh());
    expect($state['can_read'])->toBeFalse();
});

it('a mensagem consome a franquia diária e devolve o restante', function () {
    config(['member_engagement.free_messages_per_day' => 5]);
    $performer = engPerformer();
    $member = engMember();

    $this->actingAs($performer)
        ->postJson(route('performer.members.message'), [
            'member_handle' => heartHandle($performer, $member),
            'body' => 'ola',
        ])
        ->assertStatus(201)
        ->assertJson(['messages_remaining_today' => 4]);

    $this->assertDatabaseHas('performer_message_quotas', [
        'performer_profile_id' => $performer->performerProfile->id,
        'quota_date' => now()->toDateString(),
        'sent_count' => 1,
    ]);
});

it('esgotada a franquia diária, a mensagem é recusada com daily_message_limit', function () {
    config(['member_engagement.free_messages_per_day' => 1]);
    $performer = engPerformer();
    $member = engMember();
    $handle = heartHandle($performer, $member);

    $this->actingAs($performer)
        ->postJson(route('performer.members.message'), ['member_handle' => $handle, 'body' => 'primeira'])
        ->assertStatus(201);

    $this->actingAs($performer)
        ->postJson(route('performer.members.message'), ['member_handle' => $handle, 'body' => 'segunda'])
        ->assertStatus(422)
        ->assertJson(['reason' => 'daily_message_limit', 'messages_remaining_today' => 0]);

    // A segunda não persistiu.
    expect(Message::where('body', 'segunda')->exists())->toBeFalse();
});

it('mensagem barrada pelo filtro não consome a franquia', function () {
    config(['member_engagement.free_messages_per_day' => 3]);
    $performer = engPerformer();
    $member = engMember();

    $this->actingAs($performer)
        ->postJson(route('performer.members.message'), [
            'member_handle' => heartHandle($performer, $member),
            'body' => 'faz programa completo',
        ])
        ->assertStatus(422)
        ->assertJson(['reason' => 'content_blocked']);

    // Nada persistido e franquia intacta (linha do dia nem foi criada).
    $this->assertDatabaseCount('messages', 0);
    $this->assertDatabaseCount('performer_message_quotas', 0);
});

it('404 ao mandar mensagem para membro fora do catálogo', function () {
    $performer = engPerformer();
    $member = engMember(['visible_to_performers' => false]);

    $this->actingAs($performer)
        ->postJson(route('performer.members.message'), [
            'member_handle' => heartHandle($performer, $member),
            'body' => 'oi',
        ])
        ->assertStatus(404);

    $this->assertDatabaseCount('messages', 0);
});

// ─── Hard Delete ─────────────────────────────────────────────────────────────

it('Hard Delete do membro varre os corações recebidos', function () {
    $performer = engPerformer();
    $member = engMember();
    PerformerHeart::create([
        'performer_profile_id' => $performer->performerProfile->id,
        'member_id' => $member->id,
    ]);

    app(DeletionService::class)->executeDeletion($member);

    $this->assertDatabaseCount('performer_hearts', 0);
});

it('Hard Delete da performer varre os corações dados e o contador de mensagens', function () {
    config(['member_engagement.free_messages_per_day' => 5]);
    $performer = engPerformer();
    $member = engMember();

    $this->actingAs($performer)->postJson(route('performer.members.heart'), ['member_handle' => heartHandle($performer, $member)]);
    $this->actingAs($performer)->postJson(route('performer.members.message'), ['member_handle' => heartHandle($performer, $member), 'body' => 'oi']);

    app(DeletionService::class)->executeDeletion($performer->fresh());

    $this->assertDatabaseCount('performer_hearts', 0);
    $this->assertDatabaseCount('performer_message_quotas', 0);
});
