<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Audit;
use App\Support\ClientFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * `audit_logs` guarda o IP como HMAC, nunca em claro (security/audit-ip-hash).
 *
 * O IP é dado pessoal e o audit é o dossiê por user_id: com o octeto cru, um
 * dump do banco correlacionava contas por origem SEM a APP_KEY. A coluna é
 * `ip_hash` e o valor vem do mesmo ClientFingerprint do aceite de documentos —
 * HMAC preserva igualdade, então o sinal de correlação sobrevive; o octeto some.
 */
function auditRequestFrom(string $ip): Request
{
    return Request::create('/', 'GET', server: ['REMOTE_ADDR' => $ip]);
}

it('grava o IP do audit como HMAC, não em claro', function () {
    $user = User::factory()->create();

    Audit::log('test.action', $user, null, auditRequestFrom('198.51.100.42'));

    $row = AuditLog::where('action', 'test.action')->sole();

    expect($row->ip_hash)
        ->toBe(ClientFingerprint::hash('198.51.100.42'))
        ->and($row->ip_hash)->toHaveLength(64)
        ->and($row->ip_hash)->not->toContain('198.51.100.42');
});

it('o mesmo IP dá o mesmo hash — a correlação por origem sobrevive', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();

    Audit::log('test.a', $a, null, auditRequestFrom('203.0.113.10'));
    Audit::log('test.b', $b, null, auditRequestFrom('203.0.113.10'));

    $ha = AuditLog::where('action', 'test.a')->value('ip_hash');
    $hb = AuditLog::where('action', 'test.b')->value('ip_hash');

    expect($ha)->toBe($hb);
});

it('não deixa o octeto cru em nenhuma coluna de texto da linha de audit', function () {
    $user = User::factory()->create();

    Audit::log('test.scan', $user, ['nota' => 'sem ip aqui'], auditRequestFrom('203.0.113.99'));

    $row = (array) DB::table('audit_logs')->where('action', 'test.scan')->first();

    foreach ($row as $value) {
        if (is_string($value)) {
            expect($value)->not->toContain('203.0.113.99');
        }
    }
});

it('não tem mais a coluna ip em claro', function () {
    expect(Schema::hasColumn('audit_logs', 'ip'))->toBeFalse()
        ->and(Schema::hasColumn('audit_logs', 'ip_hash'))->toBeTrue();
});
