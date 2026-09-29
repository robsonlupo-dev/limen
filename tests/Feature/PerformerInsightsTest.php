<?php

use App\Models\PerformerProfile;
use App\Models\User;
use App\Services\PerformerInsightsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Insights da performer — roadmap social, Onda 3 (§ 3.2). Painel só-leitura e
 * AGREGADO. Eixo: (1) contagens/funil/horários/evolução corretos por janela;
 * (2) ANONIMATO — nada de id/lista de membro sai do serviço (viewers de story é só
 * número); (3) só a performer (dona) acessa. Prefixo `pi`.
 */
function piPerformer(): PerformerProfile
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);
    $profile = $user->performerProfile()->create([
        'stage_name' => 'Ana '.Str::random(6),
        'slug' => 'ana-'.strtolower(Str::random(6)),
        'category' => 'mulheres',
        'is_verified' => true,
    ]);
    DB::table('token_wallets')->insert(['user_id' => $user->id, 'balance' => 0, 'created_at' => now(), 'updated_at' => now()]);

    return $profile;
}

function piMember(): User
{
    return User::factory()->create(['role' => 'consumer', 'status' => 'active']);
}

function piVisit(PerformerProfile $p, User $m, $when): void
{
    DB::table('profile_visits')->insert([
        'visitor_id' => $m->id, 'performer_profile_id' => $p->id,
        'visited_at' => $when, 'created_at' => $when, 'updated_at' => $when,
    ]);
}

// ─── Agregação por janela ────────────────────────────────────────────────────────

it('agrega visão geral, funil, horários e ganhos por janela', function () {
    $p = piPerformer();
    $walletId = DB::table('token_wallets')->where('user_id', $p->user_id)->value('id');
    [$m1, $m2, $m3] = [piMember(), piMember(), piMember()];

    // Visitas: m1 (2d e 20d), m2 (1d), m3 (20d). 30d: 4 visitas / 3 únicos.
    piVisit($p, $m1, now()->subDays(2));
    piVisit($p, $m1, now()->subDays(20));
    piVisit($p, $m2, now()->subDay());
    piVisit($p, $m3, now()->subDays(20));

    // Seguidores: m1, m2 (agora).
    foreach ([$m1, $m2] as $m) {
        DB::table('follows')->insert(['user_id' => $m->id, 'performer_profile_id' => $p->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    // Story + 2 viewers (m1, m2).
    $storyId = DB::table('performer_stories')->insertGetId([
        'performer_profile_id' => $p->id, 'media_path' => 'x/story.jpg',
        'visibility_level' => 'public', 'expires_at' => now()->addDay(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    foreach ([$m1, $m2] as $m) {
        DB::table('story_views')->insert(['performer_story_id' => $storyId, 'user_id' => $m->id, 'viewed_at' => now()]);
    }

    // Conteúdo + 2 desbloqueios (m1, m2).
    $contentId = DB::table('performer_content')->insertGetId([
        'performer_profile_id' => $p->id, 'access_level' => 'open', 'price_tokens' => 20,
        'path' => 'x/c.jpg', 'created_at' => now(), 'updated_at' => now(),
    ]);
    foreach ([$m1, $m2] as $m) {
        DB::table('content_unlocks')->insert([
            'performer_content_id' => $contentId, 'user_id' => $m->id, 'tokens_paid' => 20,
            'unlocked_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // Chat aberto por m1, m2 (agora).
    foreach ([$m1, $m2] as $m) {
        DB::table('chat_access')->insert([
            'member_id' => $m->id, 'performer_profile_id' => $p->id,
            'unlocked_at' => now(), 'expires_at' => now()->addDays(30), 'grace_ends_at' => now()->addDays(45),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // Ganhos: tip_credit 4 + content_credit 6 = 10 (hoje).
    DB::table('token_ledger')->insert([
        ['wallet_id' => $walletId, 'entry_type' => 'tip_credit', 'amount' => 4, 'balance_after' => 4, 'created_at' => now()],
        ['wallet_id' => $walletId, 'entry_type' => 'content_credit', 'amount' => 6, 'balance_after' => 10, 'created_at' => now()],
    ]);

    $out = app(PerformerInsightsService::class)->forOwner($p, 30);

    // PRESENÇA sai em FAIXA (banda) — contagens pequenas viram "Menos de 5", nunca o
    // número exato (anti-canal-lateral, mesma disciplina de followersLabelFor).
    expect($out['overview']['visits'])->toBe('Menos de 5')
        ->and($out['overview']['unique_visitors'])->toBe('Menos de 5')
        ->and($out['overview']['followers_total'])->toBe('Menos de 5')
        ->and($out['overview']['followers_new'])->toBe('Menos de 5')
        ->and($out['overview']['story_views'])->toBe('Menos de 5')
        ->and($out['overview']['story_viewers'])->toBe('Menos de 5')
        // Ações PAGAS (transações da performer) — exatas.
        ->and($out['overview']['unlocks'])->toBe(2)
        ->and($out['overview']['chats_started'])->toBe(2);

    // Funil: visitantes em faixa; ações pagas exatas; taxas null (base < piso).
    expect($out['funnel'])->toBe([
        'visited' => 'Menos de 5', 'chatted' => 2, 'bought' => 2,
        'chat_rate' => null, 'buy_rate' => null,
    ]);
    expect($out['earnings']['total'])->toBe(10.0)
        ->and($out['earnings']['daily'])->not->toBeEmpty();
    // Distribuição por horário suprimida abaixo do piso de volume.
    expect($out['periods']['available'])->toBeFalse()
        ->and($out['periods']['buckets'])->toHaveCount(4);
});

it('respeita a janela: métrica exata cai fora dos 7 dias', function () {
    $p = piPerformer();
    [$m1, $m2] = [piMember(), piMember()];
    $contentId = DB::table('performer_content')->insertGetId([
        'performer_profile_id' => $p->id, 'access_level' => 'open', 'price_tokens' => 20,
        'path' => 'x/c.jpg', 'created_at' => now(), 'updated_at' => now(),
    ]);
    // Um desbloqueio dentro de 7d, outro há 20 dias (fora de 7d, dentro de 30d).
    DB::table('content_unlocks')->insert([
        ['performer_content_id' => $contentId, 'user_id' => $m1->id, 'tokens_paid' => 20, 'unlocked_at' => now()->subDays(2), 'created_at' => now(), 'updated_at' => now()],
        ['performer_content_id' => $contentId, 'user_id' => $m2->id, 'tokens_paid' => 20, 'unlocked_at' => now()->subDays(20), 'created_at' => now(), 'updated_at' => now()],
    ]);

    expect(app(PerformerInsightsService::class)->forOwner($p, 7)['overview']['unlocks'])->toBe(1);
    expect(app(PerformerInsightsService::class)->forOwner($p, 30)['overview']['unlocks'])->toBe(2);
});

// ─── Anonimato: só número, nunca "quem" ──────────────────────────────────────────

it('não expõe nenhuma lista/id de membro (viewers de story é só contagem)', function () {
    $p = piPerformer();
    $m = piMember();
    $storyId = DB::table('performer_stories')->insertGetId([
        'performer_profile_id' => $p->id, 'media_path' => 'x/s.jpg',
        'visibility_level' => 'public', 'expires_at' => now()->addDay(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('story_views')->insert(['performer_story_id' => $storyId, 'user_id' => $m->id, 'viewed_at' => now()]);

    $out = app(PerformerInsightsService::class)->forOwner($p, 30);

    // story_viewers é FAIXA (string), não uma lista nem um número exato; e o payload
    // não carrega o id do membro em lugar nenhum.
    expect($out['overview']['story_viewers'])->toBe('Menos de 5');
    expect(json_encode($out))->not->toContain('"'.$m->id.'"');
    expect($out)->not->toHaveKey('viewers');
});

it('exclui stories exclusivos da contagem de views (audiência de exclusivo é sinal Black/FC)', function () {
    $p = piPerformer();
    $m = piMember();
    $exclusiveStory = DB::table('performer_stories')->insertGetId([
        'performer_profile_id' => $p->id, 'media_path' => 'x/e.jpg',
        'visibility_level' => 'exclusive', 'expires_at' => now()->addDay(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('story_views')->insert(['performer_story_id' => $exclusiveStory, 'user_id' => $m->id, 'viewed_at' => now()]);

    $out = app(PerformerInsightsService::class)->forOwner($p, 30);
    // A view do exclusivo NÃO conta → faixa fica "Menos de 5" (zero), nunca revela
    // que houve 1 viewer de conteúdo exclusivo.
    expect($out['overview']['story_views'])->toBe('Menos de 5')
        ->and($out['overview']['story_viewers'])->toBe('Menos de 5');
});

// ─── Só a performer acessa ───────────────────────────────────────────────────────

it('só a performer ativa abre o painel de insights', function () {
    $p = piPerformer();

    $this->actingAs($p->user)->get(route('performer.insights'))->assertOk();

    $consumer = piMember();
    $this->actingAs($consumer)->get(route('performer.insights'))->assertForbidden();
});
