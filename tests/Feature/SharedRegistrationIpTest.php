<?php

use App\Models\IdentityVerification;
use App\Models\User;
use App\Support\ClientFingerprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 * Flag de IP de cadastro compartilhado entre performers (rede de exploração).
 *
 * O sinal SINALIZA, nunca bloqueia — os testes abaixo verificam que cadastrar do
 * mesmo IP continua funcionando normalmente, só que marcado.
 */

/**
 * CPF estruturalmente válido e determinístico a partir de uma base de 9 dígitos.
 * Gerado, não constante: o cadastro deduplica por CPF, então cada conta do teste
 * precisa do seu — e uma lista de constantes "que parecem válidas" quebraria na
 * CpfValido sem dizer por quê.
 */
function cpfValidoParaTeste(string $base9): string
{
    $digits = array_map('intval', str_split($base9));

    // 1º dígito: pesos 10..2 sobre os 9 primeiros. 2º: pesos 11..2 sobre os 10.
    foreach ([9, 10] as $length) {
        $sum = 0;
        for ($i = 0; $i < $length; $i++) {
            $sum += $digits[$i] * ($length + 1 - $i);
        }
        $check = ($sum * 10) % 11;
        $digits[] = $check === 10 ? 0 : $check;
    }

    return implode('', $digits);
}

function registerPerformerFrom(string $ip, string $email, string $cpfBase = '111444777'): User
{
    $response = test()->withServerVariables(['REMOTE_ADDR' => $ip])
        ->postJson('/api/v1/auth/register/performer', [
            'name' => 'Performer '.Str::random(4),
            'email' => $email,
            'password' => 'SenhaForte123',
            'password_confirmation' => 'SenhaForte123',
            'birthdate' => '1995-05-05',
            'cpf' => cpfValidoParaTeste($cpfBase),
            'stage_name' => 'Stage '.Str::random(6),
            'accept_terms' => true,
            'lgpd_consent' => true,
            'terms_version' => '1.0',
        ]);

    $response->assertCreated();

    return User::where('email', $email)->sole();
}

function registerMemberFrom(string $ip, string $email, string $cpfBase): void
{
    test()->withServerVariables(['REMOTE_ADDR' => $ip])
        ->postJson('/api/v1/auth/register/consumer', [
            'name' => 'Membro Teste',
            'email' => $email,
            'password' => 'SenhaForte123',
            'password_confirmation' => 'SenhaForte123',
            'birthdate' => '1995-05-05',
            'cpf' => cpfValidoParaTeste($cpfBase),
            'accept_terms' => true,
            'lgpd_consent' => true,
            'terms_version' => '1.0',
        ])->assertCreated();
}

/** Fila de KYC do admin, com a verificação pendente de cada performer. */
function adminKycQueue(array $performers): array
{
    foreach ($performers as $performer) {
        IdentityVerification::where('user_id', $performer->id)
            ->update(['status' => 'pending']);
    }

    $admin = User::factory()->admin()->create();

    return test()->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/admin/kyc')
        ->assertOk()
        ->json('data');
}

/** O item da fila referente a esta performer. */
function queueRowFor(array $rows, User $performer): array
{
    return collect($rows)->firstWhere('performer.id', $performer->id);
}

it('sinaliza as duas performers cadastradas do mesmo IP', function () {
    $a = registerPerformerFrom('198.51.100.10', 'a@example.com', '111444777');
    $b = registerPerformerFrom('198.51.100.10', 'b@example.com', '222555888');

    $rows = adminKycQueue([$a, $b]);

    foreach ([$a, $b] as $performer) {
        $flag = queueRowFor($rows, $performer)['shared_registration_ip'];

        expect($flag['flagged'])->toBeTrue()
            ->and($flag['others_count'])->toBe(1)
            ->and($flag['label'])->toBe('IP de cadastro compartilhado com 1 outra performer');
    }
});

it('conta corretamente com três contas no mesmo IP', function () {
    $a = registerPerformerFrom('198.51.100.20', 'a3@example.com', '111444777');
    $b = registerPerformerFrom('198.51.100.20', 'b3@example.com', '222555888');
    $c = registerPerformerFrom('198.51.100.20', 'c3@example.com', '333666999');

    $rows = adminKycQueue([$a, $b, $c]);
    $flag = queueRowFor($rows, $a)['shared_registration_ip'];

    // "OUTRAS": três contas no IP = 2 outras, não 3.
    expect($flag['others_count'])->toBe(2)
        ->and($flag['label'])->toBe('IP de cadastro compartilhado com 2 outras performers');
});

it('não sinaliza performer com IP único', function () {
    $sozinha = registerPerformerFrom('198.51.100.30', 'unica@example.com', '111444777');
    registerPerformerFrom('198.51.100.31', 'outra@example.com', '222555888');

    $flag = queueRowFor(adminKycQueue([$sozinha]), $sozinha)['shared_registration_ip'];

    expect($flag['flagged'])->toBeFalse()
        ->and($flag['others_count'])->toBe(0)
        ->and($flag['label'])->toBeNull();
});

it('não sinaliza performer quando quem divide o IP é um membro', function () {
    // A hipótese é performer×performer: recrutamento sob coerção. Um membro no
    // mesmo IP é o caso doméstico banal (casal, mesma casa) e não pode acender
    // a luz — falso positivo aqui custa uma revisão manual injusta.
    $performer = registerPerformerFrom('198.51.100.40', 'perf@example.com', '111444777');

    registerMemberFrom('198.51.100.40', 'membro@example.com', '222555888');

    $flag = queueRowFor(adminKycQueue([$performer]), $performer)['shared_registration_ip'];

    expect($flag['flagged'])->toBeFalse()
        ->and($flag['others_count'])->toBe(0);
});

it('não grava o IP do membro em coluna nenhuma', function () {
    registerMemberFrom('198.51.100.50', 'membro2@example.com', '111444777');

    // Coleta de IP tem finalidade declarada (detectar rede de exploração de
    // performers). Membro fora do escopo = coluna nula, não "guarda por via
    // das dúvidas".
    expect(User::where('email', 'membro2@example.com')->sole()->registration_ip_hash)
        ->toBeNull();
});

it('grava o HMAC do IP, nunca o IP em texto puro', function () {
    $performer = registerPerformerFrom('198.51.100.60', 'hash@example.com', '111444777');

    expect($performer->registration_ip_hash)
        ->toBe(ClientFingerprint::hash('198.51.100.60'))
        ->and(strlen($performer->registration_ip_hash))->toBe(64);

    // Varredura das colunas de texto de users + performer_profiles atrás dos
    // octetos crus. NÃO varre a base toda de propósito: `sessions.ip_address`
    // guarda IP em claro (driver `database` em produção) e `audit_logs.ip`
    // também — em teste o driver é `array`, então uma varredura global passaria
    // e prometeria uma garantia que produção não cumpre. Ver SECURITY_ISSUES.md.
    foreach (['users', 'performer_profiles'] as $table) {
        $row = (array) DB::table($table)
            ->when($table === 'users', fn ($q) => $q->where('email', 'hash@example.com'))
            ->when($table === 'performer_profiles', fn ($q) => $q->where('user_id', $performer->id))
            ->first();

        foreach ($row as $column => $value) {
            if (is_string($value)) {
                expect($value)->not->toContain('198.51.100.60', "coluna {$table}.{$column}");
            }
        }
    }
});

it('não deixa o cadastro escolher o próprio registration_ip_hash', function () {
    // Preenchível, o campo viraria escolha de quem se cadastra: dava para
    // colidir de propósito (poluir a fila) ou fugir do flag mandando um hash
    // exclusivo. É atribuído no service, fora do $fillable.
    test()->withServerVariables(['REMOTE_ADDR' => '198.51.100.70'])
        ->postJson('/api/v1/auth/register/performer', [
            'name' => 'Esperta',
            'email' => 'esperta@example.com',
            'password' => 'SenhaForte123',
            'password_confirmation' => 'SenhaForte123',
            'birthdate' => '1995-05-05',
            'cpf' => cpfValidoParaTeste('111444777'),
            'stage_name' => 'Esperta Stage',
            'accept_terms' => true,
            'lgpd_consent' => true,
            'terms_version' => '1.0',
            'registration_ip_hash' => str_repeat('f', 64),
        ])->assertCreated();

    expect(User::where('email', 'esperta@example.com')->sole()->registration_ip_hash)
        ->toBe(ClientFingerprint::hash('198.51.100.70'));
});

it('sinaliza sem bloquear: o cadastro do mesmo IP continua funcionando', function () {
    $a = registerPerformerFrom('198.51.100.80', 'ok1@example.com', '111444777');
    $b = registerPerformerFrom('198.51.100.80', 'ok2@example.com', '222555888');

    // Revisão manual, não porta fechada — bloquear automaticamente puniria o
    // caso legítimo (mesma casa, mesmo coworking) sem ninguém olhar.
    expect($a->status)->toBe('pending')
        ->and($b->status)->toBe('pending')
        ->and($b->exists)->toBeTrue();
});

it('não sinaliza performers criadas fora de request HTTP (seeder/factory)', function () {
    // Sem esta guarda, a massa sintética inteira colidiria no 127.0.0.1 do
    // console e nasceria sinalizada, afogando o sinal real em ruído.
    $seeded = User::factory()->performer()->count(3)->create();

    foreach ($seeded as $user) {
        expect($user->registration_ip_hash)->toBeNull();
    }
});

it('mantém o flag quando a outra conta do IP é excluída', function () {
    $a = registerPerformerFrom('198.51.100.90', 'fica@example.com', '111444777');
    $b = registerPerformerFrom('198.51.100.90', 'sai@example.com', '222555888');

    // Banir/excluir a segunda conta não pode apagar o sinal da primeira: churn
    // de contas é comportamento de quem opera rede de coerção, então esse é o
    // caso em que o flag mais importa.
    $b->delete();

    $flag = queueRowFor(adminKycQueue([$a]), $a)['shared_registration_ip'];

    expect($flag['flagged'])->toBeTrue()
        ->and($flag['others_count'])->toBe(1);
});

it('ignora X-Forwarded-For enviado pelo cliente', function () {
    // Sem TrustProxies configurado (e não há proxy/CDN: o nginx fala direto com
    // o php-fpm), o Laravel usa REMOTE_ADDR e descarta o header. Isso é o que
    // impede quem se cadastra de escolher o próprio IP — seja para escapar do
    // flag, seja para apontar para o IP de outra performer e incriminá-la.
    //
    // Se um dia entrar CDN na frente, este teste continua passando enquanto o
    // comportamento correto é o oposto: aí TODA performer colidiria no IP da
    // borda. Ver a ressalva em docs/SECURITY_ISSUES.md.
    test()->withServerVariables(['REMOTE_ADDR' => '198.51.100.100'])
        ->withHeader('X-Forwarded-For', '203.0.113.250')
        ->postJson('/api/v1/auth/register/performer', [
            'name' => 'Spoof',
            'email' => 'spoof@example.com',
            'password' => 'SenhaForte123',
            'password_confirmation' => 'SenhaForte123',
            'birthdate' => '1995-05-05',
            'cpf' => cpfValidoParaTeste('111444777'),
            'stage_name' => 'Spoof Stage',
            'accept_terms' => true,
            'lgpd_consent' => true,
            'terms_version' => '1.0',
        ])->assertCreated();

    expect(User::where('email', 'spoof@example.com')->sole()->registration_ip_hash)
        ->toBe(ClientFingerprint::hash('198.51.100.100'))
        ->not->toBe(ClientFingerprint::hash('203.0.113.250'));
});

it('não expõe o hash na serialização do usuário', function () {
    $performer = registerPerformerFrom('198.51.100.110', 'oculto@example.com', '111444777');

    expect($performer->toArray())->not->toHaveKey('registration_ip_hash');
});

it('users tem a coluna de hash e nenhuma coluna de IP cru', function () {
    $columns = Schema::getColumnListing('users');

    expect($columns)->toContain('registration_ip_hash');

    foreach ($columns as $column) {
        expect($column)->not->toBe('ip')
            ->and($column)->not->toBe('ip_address')
            ->and($column)->not->toBe('registration_ip');
    }
});
