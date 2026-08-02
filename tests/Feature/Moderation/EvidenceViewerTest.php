<?php

use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\MemberPhoto;
use App\Models\Message;
use App\Models\PerformerProfile;
use App\Models\PerformerStory;
use App\Models\Report;
use App\Models\User;
use App\Services\MemberPhotoStore;
use App\Services\PerformerStoryStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Visualizador da PROVA RETIDA na fila de moderação (Sprint 13).
 *
 * O eixo NÃO é "o endpoint responde". É: (1) só serve conteúdo COM denúncia
 * associada — id adivinhado sem denúncia é 404; (2) só moderator/admin alcança —
 * consumer e performer não; (3) prova recolhida vira hash + "expirado", sem
 * pretender que o arquivo ainda existe; (4) cada abertura deixa trilha de quem
 * viu; (5) os cabeçalhos de segurança (inline, no-store, nosniff) do serving do
 * membro valem aqui igual.
 *
 * Helpers com prefixo `evq` (evidence viewer queue) — funções Pest são GLOBAIS e
 * a suíte compartilha o namespace; colidir derruba a suíte inteira.
 */
beforeEach(function () {
    Storage::fake(MemberPhotoStore::DISK);
    Storage::fake(PerformerStoryStore::DISK);
});

// ─── Fixtures ────────────────────────────────────────────────────────────────

function evqJpeg(): string
{
    $img = imagecreatetruecolor(48, 32);
    imagefilledrectangle($img, 0, 0, 47, 31, imagecolorallocate($img, 30, 120, 200));
    ob_start();
    imagejpeg($img, null, 90);
    $bytes = ob_get_clean();
    imagedestroy($img);

    return $bytes;
}

function evqUpload(): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'limen_evq_');
    file_put_contents($path, evqJpeg());

    return new UploadedFile($path, 'foto.jpg', 'image/jpeg', null, true);
}

function evqModerator(): User
{
    return User::factory()->create([
        'role' => 'moderator',
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
}

function evqConsumer(): User
{
    return User::factory()->create(['role' => 'consumer', 'status' => 'active']);
}

function evqPerformerProfile(): PerformerProfile
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

/** Foto efêmera de um membro, com denúncia apontando para ela. */
function evqPhotoWithReport(?User $reporter = null): MemberPhoto
{
    $reporter ??= evqConsumer();
    $member = evqConsumer();

    ['path' => $path, 'hash' => $hash] = app(MemberPhotoStore::class)->store(evqUpload(), $member->id);

    $photo = new MemberPhoto(['expires_at' => now()->addDay(), 'size_bytes' => 1234]);
    $photo->user_id = $member->id;
    $photo->path_encrypted = $path;
    $photo->content_hash = $hash;
    $photo->save();

    Report::open($reporter, $photo, 'non_consensual', 'conteúdo denunciado');

    return $photo;
}

/** Story de uma performer, com denúncia apontando para ele. */
function evqStoryWithReport(?User $reporter = null): PerformerStory
{
    $reporter ??= evqConsumer();
    $profile = evqPerformerProfile();

    ['path' => $path, 'hash' => $hash] = app(PerformerStoryStore::class)->store(evqUpload(), $profile->id);

    $story = new PerformerStory(['visibility_level' => 'public']);
    $story->performer_profile_id = $profile->id;
    $story->media_path = $path;
    $story->content_hash = $hash;
    $story->expires_at = now()->addDay();
    $story->save();

    Report::open($reporter, $story, 'underage_content', 'conteúdo denunciado');

    return $story;
}

/** Mensagem de chat, com denúncia apontando para ela. */
function evqMessageWithReport(?User $reporter = null): Message
{
    $reporter ??= evqConsumer();
    $member = evqConsumer();
    $profile = evqPerformerProfile();

    $conversation = Conversation::create([
        'member_id' => $member->id,
        'performer_profile_id' => $profile->id,
        'status' => 'active',
    ]);

    $message = new Message(['conversation_id' => $conversation->id, 'body' => 'mensagem denunciada aqui']);
    $message->sender_id = $member->id;
    $message->save();

    Report::open($reporter, $message, 'coercion', 'conteúdo denunciado');

    return $message;
}

// ─── O moderador vê a prova ──────────────────────────────────────────────────

it('serves a reported ephemeral photo to a moderator', function () {
    $photo = evqPhotoWithReport();

    $response = $this->actingAs(evqModerator())
        ->get(route('moderacao.evidence.photo', $photo->id));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('image/jpeg');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    expect($response->headers->get('Content-Disposition'))->toContain('inline');
});

it('serves a reported story to a moderator', function () {
    $story = evqStoryWithReport();

    $this->actingAs(evqModerator())
        ->get(route('moderacao.evidence.story', $story->id))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg');
});

it('returns the reported message body as json to a moderator', function () {
    $message = evqMessageWithReport();

    $this->actingAs(evqModerator())
        ->getJson(route('moderacao.evidence.message', $message->id))
        ->assertOk()
        ->assertJson(['body' => 'mensagem denunciada aqui']);
});

it('serves reported evidence to an admin too', function () {
    $photo = evqPhotoWithReport();

    $this->actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']))
        ->get(route('moderacao.evidence.photo', $photo->id))
        ->assertOk();
});

// ─── Só moderator/admin ──────────────────────────────────────────────────────

it('denies evidence to a consumer', function () {
    $photo = evqPhotoWithReport();

    $this->actingAs(evqConsumer())
        ->get(route('moderacao.evidence.photo', $photo->id))
        ->assertForbidden();
});

it('denies evidence to a performer', function () {
    $photo = evqPhotoWithReport();
    $performer = evqPerformerProfile()->user;

    $this->actingAs($performer)
        ->get(route('moderacao.evidence.photo', $photo->id))
        ->assertForbidden();
});

it('redirects a guest to login', function () {
    $photo = evqPhotoWithReport();

    $this->get(route('moderacao.evidence.photo', $photo->id))
        ->assertRedirect(route('login'));
});

// ─── Sem denúncia associada → 404 ────────────────────────────────────────────

it('returns 404 for a photo with no report', function () {
    $member = evqConsumer();
    ['path' => $path] = app(MemberPhotoStore::class)->store(evqUpload(), $member->id);
    $photo = new MemberPhoto(['expires_at' => now()->addDay(), 'size_bytes' => 1]);
    $photo->user_id = $member->id;
    $photo->path_encrypted = $path;
    $photo->save();

    $this->actingAs(evqModerator())
        ->get(route('moderacao.evidence.photo', $photo->id))
        ->assertNotFound();
});

it('returns 404 for a nonexistent target id', function () {
    $this->actingAs(evqModerator())
        ->get(route('moderacao.evidence.photo', 999999))
        ->assertNotFound();
});

// ─── Prova recolhida → hash + expirado ───────────────────────────────────────

it('reports evidence as unavailable with a hash when bytes are purged', function () {
    $photo = evqPhotoWithReport();
    $report = Report::where('reportable_id', $photo->id)->sole();

    // Simula o encerramento de conta sob denúncia: linha soft-deletada, hash de
    // pé, bytes recolhidos do disco.
    Storage::disk(MemberPhotoStore::DISK)->delete($photo->path_encrypted);
    $photo->delete();

    // A tela de detalhe anuncia a ausência e mostra o hash.
    $this->actingAs(evqModerator())
        ->get(route('moderacao.reports.show', $report))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('evidence.kind', 'image')
            ->where('evidence.available', false)
            ->where('evidence.content_hash', $photo->content_hash)
            ->where('evidence.url', null)
        );

    // E o endpoint de serving devolve 404 — não há bytes para servir.
    $this->actingAs(evqModerator())
        ->get(route('moderacao.evidence.photo', $photo->id))
        ->assertNotFound();
});

it('exposes the serving url on the detail page when the photo is present', function () {
    $photo = evqPhotoWithReport();
    $report = Report::where('reportable_id', $photo->id)->sole();

    $this->actingAs(evqModerator())
        ->get(route('moderacao.reports.show', $report))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('evidence.available', true)
            ->where('evidence.url', fn ($url) => str_contains((string) $url, '/moderacao/evidencia/foto/'.$photo->id))
        );
});

// ─── A prova só é servível com denúncia EM ABERTO ────────────────────────────

it('stops serving evidence once the report is resolved', function () {
    $photo = evqPhotoWithReport();
    $report = Report::where('reportable_id', $photo->id)->sole();

    // Fila concluída: a retenção dos bytes existe para a revisão, então o serving
    // falha fechado mesmo que os bytes ainda estejam no disco.
    $report->forceFill(['status' => 'resolved'])->save();

    $this->actingAs(evqModerator())
        ->get(route('moderacao.evidence.photo', $photo->id))
        ->assertNotFound();

    // E a tela de detalhe deixa de oferecer o link, mostrando o hash no lugar.
    $this->actingAs(evqModerator())
        ->get(route('moderacao.reports.show', $report))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('evidence.available', false)
            ->where('evidence.url', null)
            ->where('evidence.content_hash', $photo->content_hash)
        );
});

// ─── Audit de quem viu ───────────────────────────────────────────────────────

it('audits who viewed the evidence, referencing the report and not the content', function () {
    $photo = evqPhotoWithReport();
    $report = Report::where('reportable_id', $photo->id)->sole();
    $moderator = evqModerator();

    $this->actingAs($moderator)
        ->get(route('moderacao.evidence.photo', $photo->id))
        ->assertOk();

    $log = AuditLog::where('action', 'moderation.evidence_viewed')->latest('id')->first();

    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBe($moderator->id)
        ->and($log->subject_type)->toBe($report->getMorphClass())
        ->and($log->subject_id)->toBe($report->id)
        ->and($log->metadata['evidence_type'])->toBe('member_photo')
        // Nada do conteúdo entra no audit.
        ->and($log->metadata)->not->toHaveKey('body')
        ->and($log->metadata)->not->toHaveKey('path');
});

it('audits a message evidence view with the right type', function () {
    $message = evqMessageWithReport();
    $moderator = evqModerator();

    $this->actingAs($moderator)
        ->getJson(route('moderacao.evidence.message', $message->id))
        ->assertOk();

    $log = AuditLog::where('action', 'moderation.evidence_viewed')->latest('id')->first();

    expect($log->metadata['evidence_type'])->toBe('message');
});
