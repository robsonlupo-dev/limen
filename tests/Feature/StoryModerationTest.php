<?php

use App\Exceptions\StoryException;
use App\Models\AuditLog;
use App\Models\Follow;
use App\Models\PerformerProfile;
use App\Models\PerformerStory;
use App\Models\Report;
use App\Models\StoryView;
use App\Models\User;
use App\Services\DeletionService;
use App\Services\PerformerStoryService;
use App\Services\PerformerStoryStore;
use App\Services\StoryVisibilityService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Moderação e retenção dos Stories (Sprint 9C, PR 5).
 * Origem das regras: docs/SECURITY_ISSUES.md § 2.4 e § 2.6.
 *
 * O eixo destes testes é o achado mais sério da feature: **o auto-delete de 24h
 * é destruição de prova embutida no produto**. O `DeletionService` preserva
 * `reports` de propósito — *"apagá-la porque o denunciado pediu exclusão daria ao
 * infrator um botão para destruir a prova contra si"* — e Stories daria esse botão
 * a toda performer, automático, a cada 24 horas.
 *
 * As três portas por onde a prova sumiria, e que estes testes fecham: o GC, a
 * deleção manual e o encerramento da conta. O que sobrevive às três é o HASH.
 *
 * Helpers com prefixo `mod` porque as funções do Pest são globais.
 */
beforeEach(function () {
    Storage::fake(PerformerStoryStore::DISK);
});

// ─── Fixtures ────────────────────────────────────────────────────────────────

function modUpload(): UploadedFile
{
    $img = imagecreatetruecolor(60, 40);
    imagefilledrectangle($img, 0, 0, 59, 39, imagecolorallocate($img, 150, 40, 40));
    ob_start();
    imagejpeg($img, null, 90);
    $bytes = ob_get_clean();
    imagedestroy($img);

    $path = tempnam(sys_get_temp_dir(), 'limen_mod_');
    file_put_contents($path, $bytes);

    return new UploadedFile($path, 'story.jpg', 'image/jpeg', null, true);
}

function modPerformer(): PerformerProfile
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);

    return $user->performerProfile()->create([
        'stage_name' => 'Perf '.Str::random(8),
        'slug' => 'mod-'.strtolower(Str::random(8)),
        'category' => 'mulheres',
        'is_verified' => true,
        'level' => 'iniciante',
        'split_pct' => 65,
    ]);
}

function modMember(): User
{
    return User::factory()->create([
        'role' => 'consumer',
        'status' => 'active',
        'preferred_world' => 'mulheres',
        'email_verified_at' => now()->subDays(30),
        'created_at' => now()->subDays(30),
    ]);
}

function modStory(PerformerProfile $profile, string $visibility = 'public'): PerformerStory
{
    return app(PerformerStoryService::class)->publish($profile, modUpload(), $visibility);
}

/** Membro que segue a performer — o mínimo para ver (e denunciar) um story público. */
function modViewer(PerformerProfile $profile): User
{
    $member = modMember();
    Follow::create(['user_id' => $member->id, 'performer_profile_id' => $profile->id]);

    return $member->fresh();
}

function modReport(User $member, PerformerStory $story, string $reason = 'underage_content'): Report
{
    test()->actingAs($member)
        ->postJson(route('report.store'), [
            'reportable_type' => 'performer_story',
            'reportable_id' => $story->id,
            'reason' => $reason,
        ])
        ->assertOk();

    return Report::where('reportable_type', $story->getMorphClass())
        ->where('reportable_id', $story->id)
        ->sole();
}

// ─── Hash na ingestão (§ 2.4, parte 1) ───────────────────────────────────────

it('guarda o SHA-256 dos bytes que foram para o disco', function () {
    $performer = modPerformer();
    $story = modStory($performer);

    $onDisk = Storage::disk(PerformerStoryStore::DISK)->get($story->media_path);

    // O hash é dos bytes PROCESSADOS, não do upload: é o único conjunto de bytes
    // que existe do nosso lado, e hashear o original produziria um valor que não
    // corresponde a arquivo nenhum aqui.
    expect($story->content_hash)->toBe(hash('sha256', $onDisk))
        ->and($story->content_hash)->toHaveLength(64);
});

it('não expõe o hash em serialização', function () {
    $story = modStory(modPerformer());

    // Prova, não conteúdo de tela: publicá-lo daria a quem já tem o arquivo uma
    // forma barata de confirmar que é exatamente aquele — inclusive para testar
    // se um re-upload evasivo mudou o suficiente para escapar do matching.
    expect($story->toArray())->not->toHaveKey('content_hash')
        ->and($story->fresh()->content_hash)->not->toBeNull();
});

it('não aceita hash por mass assignment', function () {
    $story = new PerformerStory([
        'visibility_level' => 'public',
        'content_hash' => str_repeat('a', 64),
    ]);

    // Evidência escolhida pelo denunciado não é evidência.
    expect($story->content_hash)->toBeNull();
});

// ─── Story denunciável (§ 2.4, parte 2) ──────────────────────────────────────

it('deixa o membro denunciar um story que ele viu', function () {
    $performer = modPerformer();
    $story = modStory($performer);
    $member = modViewer($performer);

    $report = modReport($member, $story);

    // Entra pela MESMA porta dos outros tipos — o dedup, o lock e a recusa de
    // autodenúncia já vivem lá.
    expect($report->reportable_type)->toBe(PerformerStory::class)
        ->and($report->reportable_id)->toBe($story->id)
        ->and($report->status)->toBe('pending')
        ->and($report->reporter_id)->toBe($member->id);
});

it('não deixa denunciar um story que o membro não alcança', function () {
    $performer = modPerformer();
    $exclusivo = modStory($performer, 'exclusive');
    // Segue, mas não tem o tier: o serving devolveria 403.
    $member = modViewer($performer);

    // A MESMA pergunta do serving (§ 2.3), e por isso: sem ela, variar o
    // `reportable_id` faria o par "recebida"/"não encontrado" reconstruir o que a
    // performer publicou e quando — o oráculo que `visibleTo()` existe para fechar.
    $this->actingAs($member)
        ->postJson(route('report.store'), [
            'reportable_type' => 'performer_story',
            'reportable_id' => $exclusivo->id,
            'reason' => 'underage_content',
        ])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'target_not_found');

    expect(Report::count())->toBe(0);
});

it('responde igual para story inexistente e para story que não pode ver', function () {
    $performer = modPerformer();
    modStory($performer, 'exclusive');
    $member = modViewer($performer);

    $inexistente = $this->actingAs($member)
        ->postJson(route('report.store'), [
            'reportable_type' => 'performer_story',
            'reportable_id' => 999999,
            'reason' => 'spam',
        ])
        ->assertStatus(422);

    // Distinguir os dois reabriria o oráculo pela porta dos fundos.
    expect($inexistente->json('reason'))->toBe('target_not_found');
});

// ─── Quarentena: as três portas por onde a prova sumiria ─────────────────────

it('recusa a deleção manual do story denunciado', function () {
    $performer = modPerformer();
    $story = modStory($performer);
    $member = modViewer($performer);
    modReport($member, $story);

    // O GC educado com uma porta manual aberta ao lado não protege nada:
    // bastaria clicar em "apagar" ao ver a denúncia chegar.
    $this->actingAs($performer->user)
        ->deleteJson(route('performer.stories.destroy', $story->id))
        ->assertForbidden()
        ->assertJsonPath('reason', StoryException::UNDER_REVIEW);

    expect(PerformerStory::find($story->id))->not->toBeNull()
        ->and(Storage::disk(PerformerStoryStore::DISK)->exists($story->media_path))->toBeTrue();
});

it('deixa apagar de novo quando a moderação conclui', function () {
    $performer = modPerformer();
    $story = modStory($performer);
    $report = modReport(modViewer($performer), $story);

    $report->forceFill(['status' => 'dismissed'])->save();

    // A recusa é do STORY e é temporária: o que a performer não pode é escolher
    // o instante em que a evidência some.
    $this->actingAs($performer->user)
        ->deleteJson(route('performer.stories.destroy', $story->id))
        ->assertOk();

    expect(PerformerStory::find($story->id))->toBeNull();
});

it('mantém o GC longe do story denunciado, e só dele', function () {
    $performer = modPerformer();
    $denunciado = modStory($performer);
    $normal = modStory($performer);
    modReport(modViewer($performer), $denunciado);

    $denunciado->forceFill(['expires_at' => now()->subHour()])->save();
    $normal->forceFill(['expires_at' => now()->subHour()])->save();

    $this->artisan('stories:purge')
        ->expectsOutputToContain('expired=1 deleted=1 quarantined=1')
        ->assertSuccessful();

    // Denúncia na hora 23 seria revisada contra nada se o GC não parasse aqui.
    expect(Storage::disk(PerformerStoryStore::DISK)->exists($denunciado->fresh()->media_path))->toBeTrue()
        ->and(PerformerStory::find($denunciado->id))->not->toBeNull()
        ->and(Storage::disk(PerformerStoryStore::DISK)->exists($normal->fresh()->media_path))->toBeFalse();
});

// ─── DeletionService (§ 2.6), nos dois sentidos ──────────────────────────────

it('apaga stories e views quando a performer encerra a conta', function () {
    $performer = modPerformer();
    $story = modStory($performer);
    $viewer = modViewer($performer);

    $this->actingAs($viewer)->get(route('stories.image', $story->id))->assertOk();
    expect(StoryView::where('performer_story_id', $story->id)->count())->toBe(1);

    $path = $story->media_path;

    app(DeletionService::class)->executeDeletion($performer->user);

    // Linha, views e BYTES. Nenhum dos três sai pela FK: nem o usuário nem o
    // perfil sofrem DELETE físico (item 11 do CLAUDE.md), então quem apaga é
    // código.
    expect(PerformerStory::withTrashed()->find($story->id))->toBeNull()
        ->and(StoryView::where('performer_story_id', $story->id)->exists())->toBeFalse()
        ->and(Storage::disk(PerformerStoryStore::DISK)->exists($path))->toBeFalse();
});

it('preserva a linha do story DENUNCIADO quando a performer encerra — sem os bytes', function () {
    $performer = modPerformer();
    $story = modStory($performer);
    $viewer = modViewer($performer);
    $this->actingAs($viewer)->get(route('stories.image', $story->id))->assertOk();
    modReport($viewer, $story);

    $path = $story->media_path;
    $hash = $story->fresh()->content_hash;

    app(DeletionService::class)->executeDeletion($performer->user);

    // Encerrar a conta é a versão mais poderosa do botão de destruir a prova. O
    // que sobrevive é a EVIDÊNCIA (hash + carimbo), não o conteúdo — que é
    // exatamente a resposta do § 2.4: "preserva evidência SEM preservar conteúdo".
    $preservado = PerformerStory::withTrashed()->find($story->id);

    expect($preservado)->not->toBeNull()
        ->and($preservado->content_hash)->toBe($hash)
        // Os bytes saem de todo modo: o disco é nosso e o encerramento é o
        // pedido para que não exista mais.
        ->and(Storage::disk(PerformerStoryStore::DISK)->exists($path))->toBeFalse()
        // E a denúncia continua apontando para uma linha que existe.
        ->and(Report::where('reportable_id', $story->id)->exists())->toBeTrue();

    // A audiência NÃO é evidência: quem assistiu sai junto, é PII de terceiros.
    expect(StoryView::where('performer_story_id', $story->id)->exists())->toBeFalse();
});

it('apaga as views que o MEMBRO gerou quando ele encerra a conta', function () {
    $performer = modPerformer();
    $story = modStory($performer);
    $member = modViewer($performer);

    $this->actingAs($member)->get(route('stories.image', $story->id))->assertOk();
    expect(StoryView::where('user_id', $member->id)->count())->toBe(1);

    app(DeletionService::class)->executeDeletion($member->fresh());

    // Mapa de interesses membro→performer, igual a `profile_visits`: hard delete,
    // sem nada a preservar. O story da performer continua de pé — é dela.
    expect(StoryView::where('user_id', $member->id)->exists())->toBeFalse()
        ->and(PerformerStory::find($story->id))->not->toBeNull();
});

it('conta os stories no resumo da exclusão', function () {
    $performer = modPerformer();
    modStory($performer);
    modStory($performer);

    $log = app(DeletionService::class)->executeDeletion($performer->user);

    expect($log->data_summary['performer_stories'])->toBe(2)
        ->and($log->data_summary['performer_stories_preserved'])->toBe(0);
});

// ─── Guard de reachability ───────────────────────────────────────────────────

it('some com o indicador e com a faixa quando a performer sai do ar', function (string $status) {
    $performer = modPerformer();
    $story = modStory($performer);
    $member = modViewer($performer);

    $visibility = app(StoryVisibilityService::class);

    expect($visibility->profileIdsWithUnseenStories([$performer->id], $member))->toBe([$performer->id])
        ->and($visibility->profileStripFor($performer, $member))->toHaveCount(1);

    $performer->user->forceFill(['status' => $status])->save();

    // Suspensão por moderação: as 24h de TTL são exatamente a janela em que o
    // conteúdo tem de parar de circular. Sem o guard, a tela anunciaria o que o
    // serving já nega (que responde 404).
    expect($visibility->profileIdsWithUnseenStories([$performer->id], $member->fresh()))->toBe([])
        ->and($visibility->profileStripFor($performer->fresh(), $member->fresh()))->toBe([]);

    $this->actingAs($member->fresh())
        ->get(route('stories.image', $story->id))
        ->assertNotFound();
})->with(['suspended', 'banned', 'pending']);

it('some com a faixa quando o perfil da performer é encerrado', function () {
    $performer = modPerformer();
    modStory($performer);
    $member = modViewer($performer);

    $performer->delete();

    expect(app(StoryVisibilityService::class)->profileStripFor($performer->fresh(), $member))->toBe([]);
});

// ─── Audit log ───────────────────────────────────────────────────────────────

it('registra a publicação e a deleção sem caminho, hash nem bytes', function () {
    $performer = modPerformer();
    $story = modStory($performer, 'subscribers');

    $publicado = AuditLog::where('action', 'story.published')->sole();

    // Chaves conferidas uma a uma (e a LISTA de chaves fechada): o que este
    // teste guarda é que nada além disto entra na trilha — a ordem com que o
    // JSON volta do banco não é a asserção.
    expect($publicado->subject_id)->toBe($story->id)
        ->and(array_keys($publicado->metadata))
        ->toEqualCanonicalizing(['performer_story_id', 'visibility_level'])
        ->and($publicado->metadata['performer_story_id'])->toBe($story->id)
        ->and($publicado->metadata['visibility_level'])->toBe('subscribers');

    $this->actingAs($performer->user)
        ->deleteJson(route('performer.stories.destroy', $story->id))
        ->assertOk();

    $apagado = AuditLog::where('action', 'story.deleted')->sole();

    expect($apagado->subject_id)->toBe($story->id)
        ->and($apagado->metadata)->toBe(['performer_story_id' => $story->id]);

    // Mesma disciplina do filtro de chat: a trilha nunca leva o corpo. Nem o
    // caminho (layout do disco) nem o hash (que já tem lugar próprio na linha).
    $todos = AuditLog::whereIn('action', ['story.published', 'story.deleted'])->get();

    foreach ($todos as $linha) {
        expect(json_encode($linha->metadata))
            ->not->toContain('.jpg')
            ->not->toContain($story->content_hash);
    }
});

it('não audita a coleta rotineira do GC', function () {
    $performer = modPerformer();
    $story = modStory($performer);
    $story->forceFill(['expires_at' => now()->subHour()])->save();

    AuditLog::where('action', 'story.published')->delete();

    $this->artisan('stories:purge')->assertSuccessful();

    // Expirar é o produto funcionando, não um ato de ninguém: auditar cada
    // recolhimento horário encheria a trilha com o esperado e enterraria o
    // excepcional — a mesma razão pela qual o filtro de chat deduplica.
    expect(AuditLog::where('action', 'story.deleted')->count())->toBe(0);
});

// ─── A denúncia é a mesma porta, com as mesmas defesas ───────────────────────

it('não grava duas denúncias do mesmo par na janela', function () {
    $performer = modPerformer();
    $story = modStory($performer);
    $member = modViewer($performer);

    modReport($member, $story);
    modReport($member, $story);

    // O dedup, o lock e a resposta de sucesso para a repetição são do
    // ReportController — story entrou pela porta que já os tinha.
    expect(Report::where('reportable_id', $story->id)->count())->toBe(1);
});

it('mantém o hash da denúncia mesmo depois de o GC levar os bytes', function () {
    $performer = modPerformer();
    $story = modStory($performer);
    $report = modReport(modViewer($performer), $story);
    $hash = $story->fresh()->content_hash;

    // Conclui a revisão e deixa o GC recolher: o arquivo some…
    $report->forceFill(['status' => 'resolved'])->save();
    $story->forceFill(['expires_at' => now()->subHour()])->save();

    $this->artisan('stories:purge')->assertSuccessful();

    expect(Storage::disk(PerformerStoryStore::DISK)->exists($story->fresh()->media_path))->toBeFalse();

    // …e a prova fica. É o que permite casar um re-upload contra hash conhecido
    // depois que o conteúdo já não existe — o que de fato bloqueia a reincidência.
    expect(DB::table('performer_stories')->where('id', $story->id)->value('content_hash'))->toBe($hash);
});
