<?php

use App\Exceptions\NicknameException;
use App\Models\PerformerProfile;
use App\Models\TokenLedger;
use App\Models\User;
use App\Services\MemberCatalogService;
use App\Services\MemberNicknameService;
use App\Services\PerformerEarningsService;
use App\Services\TipService;
use App\Services\TokenService;
use App\Support\FanAlias;
use App\Support\MemberDisplayName;
use Illuminate\Support\Str;

/**
 * Apelido do membro (feat/member-nickname). O membro escolhe um apelido e é assim
 * que a performer o chama, no lugar de "Fã #NNNN". Camada de EXIBIÇÃO — o FanAlias
 * segue como identificador técnico no ledger/extrato/logs (testado explicitamente).
 *
 * A validação é mais RÍGIDA que a do chat: barra telefone/e-mail/rede social/palavra
 * reservada/nome de performer/conduta. Helpers `nk*` para rodar isolado.
 */
function nkService(): MemberNicknameService
{
    return app(MemberNicknameService::class);
}

function nkMember(int $balance = 0): User
{
    $user = User::factory()->create([
        'role' => 'consumer', 'status' => 'active',
        'email_verified_at' => now()->subDays(30), 'created_at' => now()->subDays(30),
    ]);
    if ($balance > 0) {
        app(TokenService::class)->credit($user, $balance, 'purchase');
    }

    return $user;
}

function nkPerformer(string $stageName = 'Perf'): PerformerProfile
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);

    return $user->performerProfile()->create([
        'stage_name' => $stageName.' '.Str::random(4),
        'slug' => 'perf-'.strtolower(Str::random(8)),
        'category' => 'mulheres', 'is_verified' => true, 'level' => 'iniciante', 'split_pct' => 65,
    ]);
}

// ─── Validação: cada categoria proibida é REJEITADA ─────────────────────────

it('rejeita telefone disfarçado (sequência longa de dígitos)', function () {
    expect(fn () => nkService()->validate('leo 11 99999 8888'))
        ->toThrow(NicknameException::class);
    try {
        nkService()->validate('11999998888');
    } catch (NicknameException $e) {
        expect($e->reason)->toBe('contact');
    }
});

it('rejeita e-mail / arroba', function () {
    try {
        nkService()->validate('leo@casa');
        $this->fail('deveria ter lançado');
    } catch (NicknameException $e) {
        expect($e->reason)->toBe('contact');
    }
});

it('rejeita rede social, inclusive com leetspeak (1nsta)', function () {
    foreach (['instagram_leo', '1nsta_leo', 'zaap', 'wh4ts'] as $bad) {
        try {
            nkService()->validate($bad);
            $this->fail("deveria rejeitar {$bad}");
        } catch (NicknameException $e) {
            expect($e->reason)->toBe('contact');
        }
    }
});

it('rejeita contato/rede social ESPAÇADO (z a p, i n s t a, o n l y f a n s)', function () {
    // A normalização do chat PRESERVA espaço; sem a forma canônica sem-espaço estes
    // escapavam de todo casamento de keyword (achado da revisão de segurança).
    foreach (['z a p 11', 'i n s t a leo', 'o n l y f a n s', 'wh a ts'] as $bad) {
        try {
            nkService()->validate($bad);
            $this->fail("deveria rejeitar {$bad}");
        } catch (NicknameException $e) {
            expect($e->reason)->toBe('contact');
        }
    }
});

it('rejeita telefone com underscore como separador (1_2_3_4_5)', function () {
    try {
        nkService()->validate('leo_1_2_3_4_5');
        $this->fail('deveria rejeitar');
    } catch (NicknameException $e) {
        expect($e->reason)->toBe('contact');
    }
});

it('rejeita near-clone de OUTRO MEMBRO (leet/espaço/repetição) com mensagem genérica', function () {
    $original = nkMember();
    nkService()->set($original, 'joaozinho');

    // Cada um destes canonicaliza para "joaozinho" — personificação membro→membro.
    foreach (['joaozinh0', 'jo aozinho', 'joaozinhoo', 'JOAOZINHO'] as $clone) {
        try {
            nkService()->validate($clone, nkMember());
            $this->fail("deveria rejeitar o near-clone {$clone}");
        } catch (NicknameException $e) {
            expect($e->reason)->toBe('unavailable')
                ->and($e->getMessage())->toBe('Esse apelido não está disponível.');
        }
    }
});

it('rejeita palavra reservada (limen/suporte/admin)', function () {
    foreach (['suporte', 'admin_leo', 'limenoficial'] as $bad) {
        try {
            nkService()->validate($bad);
            $this->fail("deveria rejeitar {$bad}");
        } catch (NicknameException $e) {
            expect($e->reason)->toBe('reserved');
        }
    }
});

it('rejeita apelido igual a nome de performer existente (mensagem genérica)', function () {
    // Nome artístico EXATO (o helper normal adiciona sufixo aleatório).
    $u = User::factory()->create(['role' => 'performer', 'status' => 'active']);
    $u->performerProfile()->create([
        'stage_name' => 'Belladona', 'slug' => 'belladona-'.strtolower(Str::random(6)),
        'category' => 'mulheres', 'is_verified' => true, 'level' => 'iniciante', 'split_pct' => 65,
    ]);

    try {
        nkService()->validate('belladona'); // caixa diferente, mesma normalização
        $this->fail('deveria rejeitar');
    } catch (NicknameException $e) {
        // Genérico — não confirma que existe uma performer com o nome.
        expect($e->reason)->toBe('unavailable')
            ->and($e->getMessage())->toBe('Esse apelido não está disponível.');
    }
});

it('rejeita duplicado com mensagem GENÉRICA (não revela que há um membro com ele)', function () {
    $a = nkMember();
    nkService()->set($a, 'Leozin');

    try {
        nkService()->validate('leozin', nkMember()); // outro membro, caixa diferente
        $this->fail('deveria rejeitar');
    } catch (NicknameException $e) {
        expect($e->reason)->toBe('unavailable')
            ->and($e->getMessage())->toBe('Esse apelido não está disponível.');
    }
});

it('rejeita tamanho fora de 3–20', function () {
    foreach (['ab', str_repeat('a', 21)] as $bad) {
        try {
            nkService()->validate($bad);
            $this->fail("deveria rejeitar {$bad}");
        } catch (NicknameException $e) {
            expect($e->reason)->toBe('length');
        }
    }
});

// ─── Troca: cooldown de 7 dias ──────────────────────────────────────────────

it('a PRIMEIRA escolha é livre; trocar dentro de 7 dias é rejeitado', function () {
    $member = nkMember();
    nkService()->set($member, 'PrimeiroNome');
    expect($member->fresh()->nickname)->toBe('PrimeiroNome');

    try {
        nkService()->set($member->fresh(), 'OutroNome');
        $this->fail('deveria rejeitar a troca dentro de 7 dias');
    } catch (NicknameException $e) {
        expect($e->reason)->toBe('cooldown');
    }

    // Passados 7 dias, troca de novo.
    $this->travel(8)->days();
    nkService()->set($member->fresh(), 'OutroNome');
    expect($member->fresh()->nickname)->toBe('OutroNome');
});

// ─── Sem apelido → alias; com apelido → apelido (o resolver) ────────────────

it('sem apelido a exibição cai no FanAlias; com apelido, mostra o apelido', function () {
    $pid = 42;
    $mid = 7;

    expect(MemberDisplayName::for(null, $pid, $mid))->toBe(FanAlias::label($pid, $mid))
        ->and(MemberDisplayName::for('', $pid, $mid))->toBe(FanAlias::label($pid, $mid))
        ->and(MemberDisplayName::for('Leo', $pid, $mid))->toBe('Leo');
});

// ─── INVARIANTE: ledger e extrato de ganhos SEGUEM no FanAlias ──────────────

it('o ledger e o extrato de ganhos continuam com o FanAlias MESMO com apelido definido', function () {
    $member = nkMember(balance: 100);
    nkService()->set($member, 'Leozito');
    expect($member->fresh()->nickname)->toBe('Leozito');

    $profile = nkPerformer();
    app(TipService::class)->send($member, $profile, 10, Str::uuid()->toString());

    // A descrição do crédito no ledger traz o FanAlias, nunca o apelido.
    $alias = FanAlias::label($profile->id, $member->id);
    $credit = TokenLedger::where('entry_type', 'tip_credit')->latest('id')->first();
    expect($credit->description)->toContain($alias)
        ->and($credit->description)->not->toContain('Leozito');

    // O extrato de ganhos (leitura do ledger) também usa o FanAlias, não o apelido.
    $rows = app(PerformerEarningsService::class)
        ->paginate($profile->user, [])
        ->getCollection();
    $labels = collect($rows)->pluck('member_alias')->filter()->all();
    expect($labels)->toContain($alias)
        ->and($labels)->each->not->toContain('Leozito');
});

// ─── Aparece no catálogo de membros (uma das 6 telas, via servidor) ─────────

it('o catálogo de membros exibe o apelido no lugar do FanAlias', function () {
    $performer = nkPerformer();
    $member = nkMember();
    nkService()->set($member, 'Estrelado');

    $rows = app(MemberCatalogService::class)->page($performer)->getCollection();
    $labels = collect($rows)->pluck('fan_alias_label');

    expect($labels)->toContain('Estrelado');
    // O handle (identificação técnica) segue sendo o FanAlias — não virou o apelido.
    expect(collect($rows)->pluck('member_handle')->first())->toMatch('/^[0-9a-f]{16}$/');
});

// ─── As 6 telas resolvem pelo MemberDisplayName (por fonte) ─────────────────

it('as seis telas de exibição resolvem o nome pelo MemberDisplayName', function () {
    $sites = [
        'app/Services/MemberCatalogService.php',
        'app/Http/Controllers/Web/ChatController.php',
        'app/Services/LiveChatService.php',
        'app/Services/LiveOverlayService.php',
        'app/Services/ProfileVisitService.php',
        'app/Http/Controllers/Web/Performer/DashboardController.php',
    ];
    foreach ($sites as $site) {
        expect(file_get_contents(base_path($site)))
            ->toContain('MemberDisplayName');
    }

    // E o extrato de ganhos NÃO usa o apelido (segue no FanAlias).
    expect(file_get_contents(base_path('app/Services/PerformerEarningsService.php')))
        ->not->toContain('MemberDisplayName');
});

it('a LISTA de conversas carrega o nickname no select do membro (senão cai no alias)', function () {
    // O select enxuto da lista precisa trazer `nickname`, ou $c->member->nickname é
    // sempre null e a lista mostra o FanAlias enquanto a conversa aberta mostra o
    // apelido — inconsistência apontada na revisão.
    $chat = file_get_contents(base_path('app/Http/Controllers/Web/ChatController.php'));
    expect($chat)->toMatch("/->select\('id', 'nickname'/");
});
