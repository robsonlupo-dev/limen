<?php

use App\Models\Conversation;
use App\Models\PerformerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// fix/member-chat-click: o ícone "Conversar" do card de performer no catálogo do
// membro não pode iniciar chat frio (chat membro→performer é interest-gated). O
// catálogo passa a carregar, por performer, o id da conversa JÁ ABERTA do membro
// — com id, o ícone abre `chat.show` direto; null, cai no perfil. Aqui travamos o
// CONTRATO do payload (a origem do href); o comportamento do <Link> é derivado.

function chatClickMember(): User
{
    return User::factory()->create([
        'role' => 'consumer',
        'status' => 'active',
        'preferred_world' => 'mulheres',
    ]);
}

function chatClickPerformer(string $name): PerformerProfile
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);

    return $user->performerProfile()->create([
        'stage_name' => $name,
        'slug' => PerformerProfile::generateSlug($name),
        'category' => 'mulheres',
        'worlds' => ['mulheres'],
        'is_verified' => true,
        'level' => 'iniciante',
        'split_pct' => 65,
    ]);
}

/** @return array<int,array> os itens `performers.data` do payload do catálogo. */
function catalogItems(User $member): array
{
    return test()->actingAs($member)->get(route('catalog'))->assertOk()
        ->viewData('page')['props']['performers']['data'];
}

it('devolve chat_conversation_id null quando o membro nao tem conversa com a performer', function () {
    $member = chatClickMember();
    chatClickPerformer('Ana');

    $items = catalogItems($member);

    expect($items)->toHaveCount(1)
        ->and($items[0])->toHaveKey('chat_conversation_id')
        ->and($items[0]['chat_conversation_id'])->toBeNull();
});

it('devolve o id da conversa aberta do membro com a performer', function () {
    $member = chatClickMember();
    $profile = chatClickPerformer('Bia');

    $conversation = Conversation::create([
        'member_id' => $member->id,
        'performer_profile_id' => $profile->id,
        'status' => 'active',
        'last_message_at' => now(),
    ]);

    $items = catalogItems($member);

    expect($items[0]['chat_conversation_id'])->toBe($conversation->id);
});

it('nao vaza a conversa de OUTRO membro no payload deste membro', function () {
    $member = chatClickMember();
    $other = chatClickMember();
    $profile = chatClickPerformer('Cléo');

    // Conversa é do OUTRO membro com a mesma performer.
    Conversation::create([
        'member_id' => $other->id,
        'performer_profile_id' => $profile->id,
        'status' => 'active',
        'last_message_at' => now(),
    ]);

    $items = catalogItems($member);

    // Este membro não tem conversa → null (o par não vira oráculo).
    expect($items[0]['chat_conversation_id'])->toBeNull();
});
