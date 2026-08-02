<?php

use App\Exceptions\StoryException;
use App\Models\Follow;
use App\Models\PerformerProfile;
use App\Models\PerformerStory;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PerformerStoryService;
use App\Services\PerformerStoryStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Convite via Stories — Sprint 12 (Caminho 2 do Interesse Expandido).
 *
 * A performer marca um Story como CONVITE; o feed o exibe com destaque para os
 * SEGUIDORES que ainda não têm chat com ela, como isca para o funil pago. O eixo
 * destes testes é o que a feature NÃO pode virar:
 *
 *  1. **Não há lista de "quem recebeu".** `is_invite` é um booleano na
 *     publicação; o alvo é derivado na leitura (seguidor sem chat), nunca
 *     materializado. Dois seguidores diferentes sem chat veem o MESMO selo — não
 *     é seleção, é categoria.
 *  2. **O selo é por espectador.** Quem já conversa (assinante OU comprou chat)
 *     vê o Story normal, sem selo; quem não segue não vê o Story de forma alguma.
 *  3. **O convite é um Story como os outros** — expira em 24h, e o teto de
 *     convites ativos vale na leitura, sem job.
 *
 * Helpers com prefixo `inv` para rodar isolado ou na suíte: as funções do Pest
 * são globais e colidiriam com as dos outros arquivos de story.
 */
beforeEach(function () {
    Storage::fake(PerformerStoryStore::DISK);
});

// ─── Fixtures ────────────────────────────────────────────────────────────────

function invJpeg(): string
{
    $img = imagecreatetruecolor(50, 30);
    imagefilledrectangle($img, 0, 0, 49, 29, imagecolorallocate($img, 200, 120, 40));
    ob_start();
    imagejpeg($img, null, 90);
    $bytes = ob_get_clean();
    imagedestroy($img);

    return $bytes;
}

function invUpload(): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'limen_inv_');
    file_put_contents($path, invJpeg());

    return new UploadedFile($path, 'story.jpg', 'image/jpeg', null, true);
}

/** Membro ativo e maduro, sem assinatura e sem chat. */
function invMember(): User
{
    return User::factory()->create([
        'role' => 'consumer',
        'status' => 'active',
        'email_verified_at' => now()->subDays(30),
        'created_at' => now()->subDays(30),
    ]);
}

function invFollow(User $member, PerformerProfile $profile): void
{
    Follow::create(['user_id' => $member->id, 'performer_profile_id' => $profile->id]);
}

/** Publica um Story-convite pelo service (atalho de setup). */
function invPublish(PerformerProfile $profile, string $visibility = 'public', bool $isInvite = true): PerformerStory
{
    return app(PerformerStoryService::class)->publish($profile, invUpload(), $visibility, $isInvite);
}

/** O item do story dentro do feed do membro, ou null. */
function invFeedItem(array $feed, int $storyId): ?array
{
    foreach ($feed as $group) {
        foreach ($group['stories'] as $story) {
            if ($story['id'] === $storyId) {
                return $story;
            }
        }
    }

    return null;
}

// ─── Publicação ──────────────────────────────────────────────────────────────

it('publica um story marcado como convite', function () {
    $story = invPublish(chatPerformer());

    expect($story->is_invite)->toBeTrue()
        // Convite é um Story normal em tudo o mais: mesmo TTL de 24h.
        ->and($story->expires_at->timestamp)->toBe(now()->addHours(24)->timestamp);
});

it('publica story normal por padrão — is_invite false sem a marcação', function () {
    // O quarto argumento default é `false`: quem não pede convite publica um
    // Story comum, e toda linha pré-existente da migration nasce assim.
    $story = app(PerformerStoryService::class)->publish(chatPerformer(), invUpload(), 'public');

    expect($story->is_invite)->toBeFalse();
});

it('não aceita is_invite por mass assignment — nasce só no service', function () {
    // Mesma disciplina de `discrete_mode`/2FA: flag que gateia comportamento não
    // entra por array de request.
    $story = new PerformerStory(['visibility_level' => 'public', 'is_invite' => true]);

    expect($story->is_invite)->not->toBeTrue();
});

// ─── Rate limit: 2 convites ativos ───────────────────────────────────────────

it('recusa o terceiro convite ativo no service', function () {
    $performer = chatPerformer();

    invPublish($performer);
    invPublish($performer);

    // O teto (MAX_ACTIVE_INVITES) é de convites VIVOS. O terceiro estoura com o
    // motivo estável para o front traduzir em 422.
    expect(fn () => invPublish($performer))
        ->toThrow(StoryException::class);

    expect(PerformerStoryService::MAX_ACTIVE_INVITES)->toBe(2)
        ->and(PerformerStory::where('performer_profile_id', $performer->id)->where('is_invite', true)->count())->toBe(2);
});

it('recusa o terceiro convite pelo endpoint com 422', function () {
    $performer = chatPerformer();

    invPublish($performer);
    invPublish($performer);

    $this->actingAs($performer->user)
        ->postJson(route('performer.stories.store'), [
            'imagem' => invUpload(),
            'visibility_level' => 'public',
            'is_invite' => true,
        ])
        ->assertStatus(422)
        ->assertJsonPath('reason', StoryException::INVITE_LIMIT);

    // Nada foi gravado — a guarda roda ANTES do Store, então nem bytes órfãos
    // ficam para trás.
    expect(PerformerStory::where('performer_profile_id', $performer->id)->count())->toBe(2)
        ->and(Storage::disk(PerformerStoryStore::DISK)->allFiles())->toHaveCount(2);
});

it('teto de convites NÃO barra a publicação de story normal', function () {
    $performer = chatPerformer();

    invPublish($performer);
    invPublish($performer);

    // Com as duas vagas de convite ocupadas, um Story COMUM ainda publica: o teto
    // só vale para convites.
    $normal = app(PerformerStoryService::class)->publish($performer, invUpload(), 'public');

    expect($normal->is_invite)->toBeFalse()
        ->and(PerformerStory::where('performer_profile_id', $performer->id)->count())->toBe(3);
});

it('convite vencido libera a vaga na leitura, sem job', function () {
    $performer = chatPerformer();

    $primeiro = invPublish($performer);
    invPublish($performer);

    // Vence o primeiro sem passar pelo relógio nem pelo stories:purge.
    $primeiro->forceFill(['expires_at' => now()->subHour()])->save();

    // A vaga se libera na leitura (escopo active()), como a expiração do próprio
    // Story (§ 2.8): o terceiro agora passa.
    expect(app(PerformerStoryService::class)->activeInviteCount($performer->refresh()))->toBe(1)
        ->and(fn () => invPublish($performer))->not->toThrow(StoryException::class);
});

// ─── Feed: o selo é por espectador ───────────────────────────────────────────

it('mostra o selo de convite ao seguidor sem chat', function () {
    $performer = chatPerformer();
    $story = invPublish($performer);

    $member = invMember();
    invFollow($member, $performer);

    $feed = $this->actingAs($member->fresh())
        ->getJson(route('stories.feed'))
        ->assertOk()
        ->json('performers');

    expect(invFeedItem($feed, $story->id))->not->toBeNull()
        ->and(invFeedItem($feed, $story->id)['is_invite'])->toBeTrue();
});

it('esconde o selo de quem já assina um Círculo — ele já tem chat livre', function () {
    $performer = chatPerformer();
    $story = invPublish($performer);

    $member = invMember();
    invFollow($member, $performer);
    Subscription::factory()->circle('explorador')->create([
        'user_id' => $member->id,
        'status' => 'active',
        'current_period_end' => now()->addMonth(),
    ]);

    $feed = $this->actingAs($member->fresh())
        ->getJson(route('stories.feed'))
        ->assertOk()
        ->json('performers');

    // Assinante vê o Story (é seguidor), mas SEM selo: mandar-lhe "compre chat"
    // seria vender o que ele já tem.
    expect(invFeedItem($feed, $story->id))->not->toBeNull()
        ->and(invFeedItem($feed, $story->id)['is_invite'])->toBeFalse();
});

it('esconde o selo de quem já comprou acesso ao chat', function () {
    $performer = chatPerformer();
    $story = invPublish($performer);

    // Par com chat aberto de verdade: follow → interesse → unlock → compra de
    // acesso. Gera linha em chat_access, então ele já está no funil.
    [$member, $conversation] = chatUnlockedPair($performer, 50);
    grantChatAccess($member, $conversation);

    $feed = $this->actingAs($member->fresh())
        ->getJson(route('stories.feed'))
        ->assertOk()
        ->json('performers');

    expect(invFeedItem($feed, $story->id))->not->toBeNull()
        ->and(invFeedItem($feed, $story->id)['is_invite'])->toBeFalse();
});

it('não expõe o convite a quem não segue a performer', function () {
    $performer = chatPerformer();
    $story = invPublish($performer);

    // Não-seguidor sem tier: o Story público nem entra no feed dele, então não há
    // convite a ver. O feed vazio é a prova de que o convite não é broadcast.
    $feed = $this->actingAs(invMember())
        ->getJson(route('stories.feed'))
        ->assertOk()
        ->json('performers');

    expect(invFeedItem($feed, $story->id))->toBeNull();
});

it('não põe selo em story normal, mesmo lado a lado com um convite', function () {
    $performer = chatPerformer();
    $convite = invPublish($performer);
    $normal = app(PerformerStoryService::class)->publish($performer, invUpload(), 'public');

    $member = invMember();
    invFollow($member, $performer);

    $feed = $this->actingAs($member->fresh())
        ->getJson(route('stories.feed'))
        ->assertOk()
        ->json('performers');

    // O selo é do convite, não do grupo: no MESMO grupo, o normal fica sem.
    expect(invFeedItem($feed, $convite->id)['is_invite'])->toBeTrue()
        ->and(invFeedItem($feed, $normal->id)['is_invite'])->toBeFalse();
});

it('convite some do feed quando expira, como qualquer story', function () {
    $performer = chatPerformer();
    $story = invPublish($performer);

    $member = invMember();
    invFollow($member, $performer);

    $story->forceFill(['expires_at' => now()->subHour()])->save();

    $feed = $this->actingAs($member->fresh())
        ->getJson(route('stories.feed'))
        ->assertOk()
        ->json('performers');

    // O convite expira COM o Story (24h): a expiração vale na leitura.
    expect(invFeedItem($feed, $story->id))->toBeNull();
});

// ─── Privacidade: sem lista de alvos ─────────────────────────────────────────

it('não materializa "quem recebeu" — é categoria, não seleção', function () {
    $performer = chatPerformer();
    $story = invPublish($performer);

    // Dois seguidores DIFERENTES, ambos sem chat: os dois veem o MESMO selo. Não
    // há lista escolhida — é a categoria "seguidor sem chat" inteira.
    foreach ([invMember(), invMember()] as $member) {
        invFollow($member, $performer);

        $feed = $this->actingAs($member->fresh())
            ->getJson(route('stories.feed'))
            ->assertOk();

        expect(invFeedItem($feed->json('performers'), $story->id)['is_invite'])->toBeTrue();

        // E o feed nunca carrega id de membro nem uma lista de destinatários.
        expect($feed->getContent())
            ->not->toContain('recipients')
            ->not->toContain('targets')
            ->not->toContain('user_id');
    }
});
