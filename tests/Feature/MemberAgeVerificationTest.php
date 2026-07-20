<?php

use App\Models\AgeVerification;
use App\Models\User;
use App\Support\CpfHash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Verificação de maioridade do membro no cadastro (ECA Digital).
 *
 * O que este arquivo garante NÃO é que a pessoa tem 18 anos — CPF válido é
 * checksum público e a data é autodeclarada. Garante que o controle existe, que
 * rejeita o que deve rejeitar, e sobretudo que o CPF **não sobra no banco**.
 * Esse último é o invariante que mais provavelmente regride sem ninguém notar.
 */

// CPFs estruturalmente válidos, não pertencem a ninguém.
const CPF_VALIDO = '529.982.247-25';
const CPF_VALIDO_2 = '11144477735';

function registerMemberPayload(array $overrides = []): array
{
    return array_merge([
        'tipo' => 'membro',
        'name' => 'Membro Teste',
        'email' => 'membro@example.com',
        'password' => 'Password1',
        'password_confirmation' => 'Password1',
        'birthdate' => now()->subYears(25)->format('Y-m-d'),
        'cpf' => CPF_VALIDO,
        'preferred_world' => 'mulheres',
        'accept_terms' => true,
        'lgpd_consent' => true,
    ], $overrides);
}

// ─── Schema ─────────────────────────────────────────────────────────────────

it('nunca cria coluna de cpf em users nem em age_verifications', function () {
    expect(Schema::hasColumn('users', 'cpf'))->toBeFalse()
        ->and(Schema::hasColumn('age_verifications', 'cpf'))->toBeFalse()
        ->and(Schema::hasColumn('age_verifications', 'cpf_hmac'))->toBeTrue();
});

// ─── Rejeições ──────────────────────────────────────────────────────────────

it('rejeita cadastro de membro com CPF invalido', function () {
    $this->post('/cadastro', registerMemberPayload(['cpf' => '111.111.111-11']))
        ->assertSessionHasErrors('cpf');

    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('age_verifications', 0);
});

it('rejeita cadastro de membro sem CPF', function () {
    $this->post('/cadastro', registerMemberPayload(['cpf' => null]))
        ->assertSessionHasErrors('cpf');

    $this->assertDatabaseCount('users', 0);
});

it('rejeita cadastro de membro menor de idade mesmo com CPF valido', function () {
    $this->post('/cadastro', registerMemberPayload([
        'birthdate' => now()->subYears(17)->format('Y-m-d'),
    ]))->assertSessionHasErrors('birthdate');

    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('age_verifications', 0);
});

// Faz 18 anos amanhã: o corte é exatamente hoje, e um off-by-one aqui deixaria
// entrar menor de idade no dia anterior ao aniversário.
it('rejeita quem completa 18 anos amanha', function () {
    $this->post('/cadastro', registerMemberPayload([
        'birthdate' => now()->subYears(18)->addDay()->format('Y-m-d'),
    ]))->assertSessionHasErrors('birthdate');
});

it('aceita quem completa 18 anos hoje', function () {
    $this->post('/cadastro', registerMemberPayload([
        'birthdate' => now()->subYears(18)->format('Y-m-d'),
    ]))->assertSessionHasNoErrors();

    expect(User::where('email', 'membro@example.com')->exists())->toBeTrue();
});

// ─── Caminho feliz + não persistência do CPF ────────────────────────────────

it('aceita membro maior de idade e nao grava o CPF em lugar nenhum', function () {
    $this->post('/cadastro', registerMemberPayload())
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('verification.notice'));

    $user = User::where('email', 'membro@example.com')->firstOrFail();

    $verification = AgeVerification::where('user_id', $user->id)->firstOrFail();
    expect($verification->method)->toBe(AgeVerification::METHOD_CPF_DOB)
        ->and($verification->verified_at)->not->toBeNull()
        ->and($verification->cpf_hmac)->toBe(CpfHash::make(CPF_VALIDO));

    // O digest não pode ser o CPF, nem conter o CPF.
    $digits = preg_replace('/\D/', '', CPF_VALIDO);
    expect($verification->cpf_hmac)->not->toContain($digits);

    // Varredura de verdade: nenhuma coluna de nenhuma tabela guarda o número.
    // É o que pega uma regressão do tipo "alguém adicionou users.cpf".
    expect(dumpContainsCpf($digits))->toBeFalse();
});

it('mantem age_verified_at nulo — declaracao nao equivale a KYC', function () {
    $this->post('/cadastro', registerMemberPayload());

    $user = User::where('email', 'membro@example.com')->firstOrFail();

    // age_verified_at é o sinal forte (documento conferido por provedor, via
    // KycService). Se um dia o cadastro passar a marcá-lo, um whereNotNull
    // passa a tratar data autodeclarada como documento verificado.
    expect($user->age_verified_at)->toBeNull();
});

// ─── Deduplicação ───────────────────────────────────────────────────────────

it('gera o mesmo hmac para o CPF com e sem mascara', function () {
    expect(CpfHash::make('529.982.247-25'))->toBe(CpfHash::make('52998224725'));
});

it('permite detectar duas contas com o mesmo CPF', function () {
    $this->post('/cadastro', registerMemberPayload());

    // O cadastro loga o usuário e /cadastro está atrás do middleware `guest` —
    // sem derrubar a sessão, o segundo POST viraria redirect, não cadastro.
    Auth::logout();
    $this->flushSession();

    $this->post('/cadastro', registerMemberPayload(['email' => 'outro@example.com']));

    // Hoje o cadastro duplicado NÃO é bloqueado (decisão do PO em aberto) —
    // mas fica detectável, que é o ponto de guardar o digest.
    expect(AgeVerification::where('cpf_hmac', CpfHash::make(CPF_VALIDO))->count())->toBe(2)
        ->and(AgeVerification::where('cpf_hmac', CpfHash::make(CPF_VALIDO_2))->count())->toBe(0);
});

/** Procura os dígitos do CPF em toda coluna de texto de todas as tabelas. */
function dumpContainsCpf(string $digits): bool
{
    foreach (Schema::getTableListing() as $table) {
        $table = str_contains($table, '.') ? explode('.', $table)[1] : $table;

        foreach (Schema::getColumns($table) as $column) {
            if (! preg_match('/char|text|json|blob/i', $column['type_name'])) {
                continue;
            }

            $hit = DB::table($table)
                ->where($column['name'], 'like', '%'.$digits.'%')
                ->exists();

            if ($hit) {
                return true;
            }
        }
    }

    return false;
}
