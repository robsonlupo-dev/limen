<?php

use App\Http\Resources\PerformerPublicResource;
use App\Models\CallReservation;
use App\Models\ChatAccess;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\PerformerHeart;
use App\Models\PhotoGrant;
use App\Models\User;
use App\Services\MemberPhotoService;
use App\Services\MemberPhotoStore;
use App\Support\NewBadge;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Sinais de atividade dos catálogos (feat/activity-badges):
 *  - selo "Nova/Novo" na janela de 7 dias (performer E membro);
 *  - contadores de não-vistos na nav (mensagens não lidas + corações recebidos),
 *    que incrementam e ZERAM ao abrir a seção, respeitando o paywall do chat.
 *
 * NÃO há "online agora" — decisão do PO: colidiria com a granularidade "hoje" do
 * ActivitySlot e com a não-exposição de presença do membro. Só is_live é tempo real.
 */

// ─── Helpers ─────────────────────────────────────────────────────────────────

function abPerformer(): User
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

function abMember(array $attrs = []): User
{
    return User::factory()->create(array_merge([
        'role' => 'consumer',
        'status' => 'active',
        'email_verified_at' => now(),
    ], $attrs));
}

function abConversation(User $member, User $performer): Conversation
{
    return Conversation::create([
        'member_id' => $member->id,
        'performer_profile_id' => $performer->performerProfile->id,
        'status' => 'active',
        'last_message_at' => now(),
    ]);
}

function abMessage(Conversation $c, User $sender, ?\Illuminate\Support\Carbon $readAt = null): Message
{
    $m = new Message(['conversation_id' => $c->id, 'body' => 'oi']);
    $m->sender_id = $sender->id;
    $m->read_at = $readAt;
    $m->save();

    return $m;
}

function abGrantChatAccess(User $member, User $performer): ChatAccess
{
    return ChatAccess::create([
        'member_id' => $member->id,
        'performer_profile_id' => $performer->performerProfile->id,
        'unlocked_at' => now(),
        'expires_at' => now()->addDays(30),
        'grace_ends_at' => now()->addDays(31),
        'status' => 'active',
    ]);
}

// ─── SELO "NOVA" (janela pura) ───────────────────────────────────────────────

it('NewBadge é verdadeiro dentro de 7 dias e falso fora — null nunca é novo', function () {
    expect(NewBadge::isNew(now()))->toBeTrue();
    expect(NewBadge::isNew(now()->subDays(6)))->toBeTrue();
    expect(NewBadge::isNew(now()->subDays(7)->addMinute()))->toBeTrue();
    expect(NewBadge::isNew(now()->subDays(8)))->toBeFalse();
    expect(NewBadge::isNew(null))->toBeFalse();
});

// ─── SELO "NOVA" — performer (PerformerPublicResource) ────────────────────────

it('performer recém-entrada tem is_new true; passada a janela, false', function () {
    $performer = abPerformer();
    $profile = $performer->performerProfile;

    $fresh = (new PerformerPublicResource($profile))->resolve();
    expect($fresh['is_new'])->toBeTrue();

    // Nunca sai o timestamp cru — só o booleano.
    expect($fresh)->not->toHaveKey('created_at');

    $profile->forceFill(['created_at' => now()->subDays(8)])->saveQuietly();
    $old = (new PerformerPublicResource($profile->fresh()))->resolve();
    expect($old['is_new'])->toBeFalse();
});

// ─── SELO "NOVO" — membro (catálogo de membros da performer) ──────────────────

it('membro recém-cadastrado aparece com is_new no catálogo da performer', function () {
    $performer = abPerformer();
    abMember(); // conta nova → is_new true

    $this->actingAs($performer)
        ->get(route('performer.members'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Performer/Members')
            ->where('members.data.0.is_new', true)
            // A máscara nunca vaza a data de criação nem o id do membro.
            ->missing('members.data.0.created_at'));
});

it('membro fora da janela de 7 dias não recebe o selo', function () {
    $performer = abPerformer();
    $member = abMember();
    $member->forceFill(['created_at' => now()->subDays(8)])->saveQuietly();

    $this->actingAs($performer)
        ->get(route('performer.members'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('members.data.0.is_new', false));
});

// ─── CONTADOR DE CORAÇÕES NÃO-VISTOS (membro) ────────────────────────────────

it('corações recebidos incrementam o contador e zeram ao abrir Interessadas', function () {
    $member = abMember();
    $p1 = abPerformer();
    $p2 = abPerformer();

    PerformerHeart::create(['performer_profile_id' => $p1->performerProfile->id, 'member_id' => $member->id]);
    PerformerHeart::create(['performer_profile_id' => $p2->performerProfile->id, 'member_id' => $member->id]);

    // Numa tela qualquer do membro, a nav vê 2 não-vistos.
    $this->actingAs($member)
        ->get(route('catalog'))
        ->assertInertia(fn (Assert $page) => $page->where('nav_counts.hearts', 2));

    // Abrir a tela de corações ZERA o contador na MESMA resposta (prop lazy).
    $this->actingAs($member)
        ->get(route('consumer.hearts.index'))
        ->assertInertia(fn (Assert $page) => $page->where('nav_counts.hearts', 0));

    // Persistiu: próxima tela também vê 0.
    $this->actingAs($member)
        ->get(route('catalog'))
        ->assertInertia(fn (Assert $page) => $page->where('nav_counts.hearts', 0));

    // Um coração NOVO depois de visto volta a contar 1. Avança o relógio para o
    // created_at do coração ficar ESTRITAMENTE após o watermark (a igualdade ao
    // segundo é tratada como "já visto" — ver unseenCountForMember).
    $p3 = abPerformer();
    $this->travel(5)->seconds();
    PerformerHeart::create(['performer_profile_id' => $p3->performerProfile->id, 'member_id' => $member->id]);
    $this->actingAs($member->fresh())
        ->get(route('catalog'))
        ->assertInertia(fn (Assert $page) => $page->where('nav_counts.hearts', 1));
});

it('coração de performer não verificada não conta no contador', function () {
    $member = abMember();
    $unverified = User::factory()->create(['role' => 'performer', 'status' => 'active']);
    $unverified->performerProfile()->create([
        'stage_name' => 'X', 'slug' => 'x-'.strtolower(Str::random(6)),
        'category' => 'mulheres', 'is_verified' => false, 'level' => 'iniciante', 'split_pct' => 65,
    ]);

    PerformerHeart::create(['performer_profile_id' => $unverified->performerProfile->id, 'member_id' => $member->id]);

    $this->actingAs($member)
        ->get(route('catalog'))
        ->assertInertia(fn (Assert $page) => $page->where('nav_counts.hearts', 0));
});

// ─── CONTADOR DE MENSAGENS NÃO-LIDAS ─────────────────────────────────────────

it('performer vê mensagem não lida do membro no contador da nav', function () {
    $performer = abPerformer();
    $member = abMember();
    $conv = abConversation($member, $performer);
    abMessage($conv, $member); // membro escreveu, sem read_at

    $this->actingAs($performer)
        ->get(route('performer.members'))
        ->assertInertia(fn (Assert $page) => $page->where('nav_counts.messages', 1));
});

it('membro só conta mensagem não lida quando tem acesso pago (paywall)', function () {
    $performer = abPerformer();
    $member = abMember();
    $conv = abConversation($member, $performer);
    abMessage($conv, $performer); // performer escreveu ao membro

    // Sem ChatAccess: o corpo está atrás do cadeado — o contador NÃO vaza (0).
    $this->actingAs($member)
        ->get(route('catalog'))
        ->assertInertia(fn (Assert $page) => $page->where('nav_counts.messages', 0));

    // Com acesso pago vigente: passa a contar.
    abGrantChatAccess($member, $performer);
    $this->actingAs($member)
        ->get(route('catalog'))
        ->assertInertia(fn (Assert $page) => $page->where('nav_counts.messages', 1));
});

it('mensagem já lida (read_at) não conta', function () {
    $performer = abPerformer();
    $member = abMember();
    $conv = abConversation($member, $performer);
    abMessage($conv, $member, now()); // já lida

    $this->actingAs($performer)
        ->get(route('performer.members'))
        ->assertInertia(fn (Assert $page) => $page->where('nav_counts.messages', 0));
});

it('minha própria mensagem não conta como não-lida para mim', function () {
    $performer = abPerformer();
    $member = abMember();
    $conv = abConversation($member, $performer);
    abMessage($conv, $performer); // a própria performer escreveu

    $this->actingAs($performer)
        ->get(route('performer.members'))
        ->assertInertia(fn (Assert $page) => $page->where('nav_counts.messages', 0));
});

// ─── Pendências do Painel (reorg do Painel, UAT Fase 21) ─────────────────────
// O badge de "Painel" soma pedidos de acesso + fotos recebidas não vistas +
// agendamentos a confirmar; "Pessoas" mostra só os pedidos. Cada um é CONTAGEM
// da mesma regra que o painel já usa — e nunca sai para o membro.

/** JPEG mínimo para a foto efêmera do membro (GD), com nome próprio deste arquivo. */
function abJpeg(): string
{
    $img = imagecreatetruecolor(40, 30);
    imagefilledrectangle($img, 0, 0, 39, 29, imagecolorallocate($img, 120, 80, 60));
    ob_start();
    imagejpeg($img, null, 90);
    $bytes = ob_get_clean();
    imagedestroy($img);

    return $bytes;
}

it('pedido de acesso a foto PRIVADA conta no badge da performer; foto pública e o membro não', function () {
    $performer = abPerformer();
    $member = abMember();
    $profile = $performer->performerProfile;

    $privada = $profile->photos()->create(['path' => $profile->id.'/'.Str::random(12).'.jpg', 'position' => 0]);
    $privada->is_private = true; // fora do $fillable, como o service faz
    $privada->save();
    $publica = $profile->photos()->create(['path' => $profile->id.'/'.Str::random(12).'.jpg', 'position' => 1]);

    foreach ([$privada, $publica] as $photo) {
        $grant = new PhotoGrant;
        $grant->performer_photo_id = $photo->id;
        $grant->user_id = $member->id;
        $grant->save(); // granted_at nulo = pendente
    }

    // Só o pedido sobre a foto PRIVADA é um pedido de verdade (mesma regra do
    // PhotoAccessService::pendingRequestsFor).
    $this->actingAs($performer)
        ->get(route('performer.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('nav_counts.access_requests', 1));

    // O membro nunca carrega a contagem da performer.
    $this->actingAs($member)
        ->get(route('catalog'))
        ->assertInertia(fn (Assert $page) => $page->where('nav_counts.access_requests', 0));
});

it('foto recebida conta só enquanto NÃO vista e dentro do prazo', function () {
    Storage::fake(MemberPhotoStore::DISK);

    $performer = abPerformer();
    $member = abMember();
    $service = app(MemberPhotoService::class);

    $photo = $service->create($member, UploadedFile::fake()->createWithContent('foto.jpg', abJpeg()), 24);
    $access = $service->grantTo($member, $photo, $performer->performerProfile, 24);

    $this->actingAs($performer)
        ->get(route('performer.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('nav_counts.received_photos', 1));

    // Abriu: deixa de "precisar dela".
    $access->markViewed();

    $this->actingAs($performer->fresh())
        ->get(route('performer.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('nav_counts.received_photos', 0));
});

it('agendamento pendente conta só com a flag de chamada ligada', function () {
    $performer = abPerformer();
    $member = abMember();

    CallReservation::create([
        'member_id' => $member->id,
        'performer_profile_id' => $performer->performerProfile->id,
        'scheduled_at' => now()->addHours(3),
        'slot_minutes' => 15,
        'deposit_tokens' => 10,
        'price_per_min_locked' => 10,
    ]); // status default = pending

    config(['features.call_enabled' => false]);
    $this->actingAs($performer)
        ->get(route('performer.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('nav_counts.reservations', 0));

    config(['features.call_enabled' => true]);
    $this->actingAs($performer->fresh())
        ->get(route('performer.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('nav_counts.reservations', 1));
});
