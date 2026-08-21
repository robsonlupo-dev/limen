<?php

use App\Models\TokenWallet;
use App\Models\User;
use App\Services\TokenService;
use App\Support\LedgerEntryLabel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * feat/content-showcase item 2: NENHUM nome técnico de lançamento chega ao usuário.
 * O rótulo é traduzido no servidor (LedgerEntryLabel) para as duas telas.
 */

// Lê o enum REAL da coluna entry_type — se um tipo novo entrar via migration e não
// ganhar rótulo, este teste falha (não deixa vazar cru).
function ledgerEntryTypes(): array
{
    $col = DB::selectOne("SELECT COLUMN_TYPE ct FROM information_schema.COLUMNS WHERE TABLE_NAME='token_ledger' AND COLUMN_NAME='entry_type' AND TABLE_SCHEMA=DATABASE()");
    preg_match_all("/'([^']+)'/", $col->ct, $m);

    return $m[1];
}

it('traduz TODO tipo do enum — nenhum rótulo é o nome cru de banco', function () {
    foreach (ledgerEntryTypes() as $type) {
        $label = LedgerEntryLabel::for($type);

        expect($label)->not->toBe($type)                 // nunca o nome cru
            ->and($label)->not->toContain('_')           // nada de snake_case de banco
            ->and(trim($label))->not->toBe('');          // sempre algo legível
    }
});

it('tipo desconhecido cai num genérico, nunca no nome cru', function () {
    expect(LedgerEntryLabel::for('algum_tipo_futuro'))->toBe('Movimento');
});

it('o histórico da carteira do membro entrega rótulo traduzido, nunca o entry_type cru', function () {
    $member = User::factory()->create(['role' => 'consumer', 'status' => 'active']);
    $svc = app(TokenService::class);
    $svc->credit($member, 100, 'purchase');
    $svc->debit($member, 5, 'spend_content');
    $svc->debit($member, 2, 'spend_chat_access');

    $data = $this->actingAs($member)->get(route('wallet.history'))
        ->assertOk()
        ->viewData('page')['props']['entries']['data'];

    $labels = collect($data)->pluck('label')->all();

    expect($labels)->toContain('Compra de tokens', 'Conteúdo desbloqueado', 'Abertura de conversa');
    // Nenhum rótulo cru na tela.
    foreach ($data as $row) {
        expect($row['label'])->not->toContain('_')
            ->and($row['label'])->not->toBe($row['entry_type']);
    }
});
