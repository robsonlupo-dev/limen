<?php

use App\Http\Resources\PerformerPublicResource;
use App\Models\PerformerProfile;
use App\Models\User;
use App\Support\TestAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

/**
 * Selo "Teste" (disciplina de UAT) — BOOLEANO derivado do e-mail. Prefixo `tb`.
 */
function tbPerformer(string $email): PerformerProfile
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active', 'email' => $email]);

    static $n = 0;
    $n++;
    $name = 'Tb'.$n;

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

function tbResource(PerformerProfile $p): array
{
    return (new PerformerPublicResource($p->load('user')))->toArray(Request::create('/'));
}

it('marca como teste os domínios reservados dos seeders', function () {
    expect(TestAccount::isTest(User::factory()->make(['email' => 'performer01@teste.limen.local'])))->toBeTrue()
        ->and(TestAccount::isTest(User::factory()->make(['email' => 'ana@uat.limen.test'])))->toBeTrue();
});

it('NÃO marca conta de domínio real', function () {
    expect(TestAccount::isTest(User::factory()->make(['email' => 'pessoa@gmail.com'])))->toBeFalse()
        ->and(TestAccount::isTest(User::factory()->make(['email' => 'contato@thelimen.com.br'])))->toBeFalse();
});

it('expõe is_test no resource público para conta de teste', function () {
    expect(tbResource(tbPerformer('ana@uat.limen.test'))['is_test'])->toBeTrue()
        ->and(tbResource(tbPerformer('real@gmail.com'))['is_test'])->toBeFalse();
});

it('respeita a allowlist de e-mails avulsos do config', function () {
    config(['accounts.test_emails' => ['meu.teste@gmail.com']]);

    expect(TestAccount::isTest(User::factory()->make(['email' => 'MEU.TESTE@gmail.com'])))->toBeTrue()
        ->and(TestAccount::isTest(User::factory()->make(['email' => 'outro@gmail.com'])))->toBeFalse();
});

it('o interruptor global desliga o selo de uma vez', function () {
    config(['accounts.test_badge_enabled' => false]);

    expect(TestAccount::isTest(User::factory()->make(['email' => 'ana@uat.limen.test'])))->toBeFalse();
});

it('não quebra com e-mail ausente ou malformado', function () {
    expect(TestAccount::isTest(null))->toBeFalse()
        ->and(TestAccount::isTest(User::factory()->make(['email' => 'sem-arroba'])))->toBeFalse();
});
