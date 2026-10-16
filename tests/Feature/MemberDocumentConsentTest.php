<?php

use App\Models\DocumentAcceptance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Prova do aceite de Termos + Privacidade no cadastro (feat/waitlist-email).
 * Antes o membro só gravava users.terms_version='1.0' (constante morta) — sem
 * versão real, sem fingerprint, sem linha de evidência. Prefixo `mdc` nos
 * helpers (funções de teste do Pest são globais).
 */
function mdcMemberPayload(array $overrides = []): array
{
    return array_merge([
        'tipo' => 'membro',
        'name' => 'Membro Consent',
        'email' => 'consent@example.com',
        'password' => 'Password1',
        'password_confirmation' => 'Password1',
        'birthdate' => now()->subYears(25)->format('Y-m-d'),
        'cpf' => '529.982.247-25',
        'preferred_world' => 'mulheres',
        'accept_terms' => true,
        'lgpd_consent' => true,
    ], $overrides);
}

it('grava o aceite de Termos e Privacidade do membro no cadastro, com versão e fingerprint', function () {
    $this->post('/cadastro', mdcMemberPayload())->assertRedirect();

    $user = User::where('email', 'consent@example.com')->firstOrFail();

    $rows = DocumentAcceptance::where('user_id', $user->id)
        ->whereIn('document_type', ['terms', 'privacy'])
        ->get();

    expect($rows)->toHaveCount(2);

    foreach (['terms', 'privacy'] as $type) {
        $row = $rows->firstWhere('document_type', $type);
        expect($row)->not->toBeNull()
            ->and($row->document_version)->toBe(DocumentAcceptance::currentVersion($type))
            ->and($row->accepted_at)->not->toBeNull()
            // fingerprint é HMAC (não texto puro) — presente no request de teste.
            ->and($row->ip_address_hash)->not->toBeNull();
    }
});

it('users.terms_version aponta para a versão REAL dos termos, não a constante 1.0', function () {
    $this->post('/cadastro', mdcMemberPayload())->assertRedirect();

    $user = User::where('email', 'consent@example.com')->firstOrFail();

    expect($user->terms_version)->toBe(DocumentAcceptance::currentVersion('terms'))
        ->and($user->terms_version)->not->toBe('1.0')
        ->and($user->lgpd_consent_at)->not->toBeNull();
});

it('não é oráculo de versão: o membro não escolhe a versão aceita', function () {
    // Mesmo que o cliente tente mandar document_version no payload, a versão
    // gravada é a do config (recordConsent nunca lê input).
    $this->post('/cadastro', mdcMemberPayload(['document_version' => '1999-01-01']))->assertRedirect();

    $user = User::where('email', 'consent@example.com')->firstOrFail();

    expect(DocumentAcceptance::where('user_id', $user->id)->pluck('document_version')->unique()->values()->all())
        ->toBe([DocumentAcceptance::currentVersion('terms')]);
});

it('o aceite do membro (terms/privacy) não entra no gate de documentos da performer', function () {
    // terms/privacy NÃO estão em REQUIRED — são evidência, não gateiam ninguém.
    expect(DocumentAcceptance::REQUIRED)->not->toContain('terms')
        ->and(DocumentAcceptance::REQUIRED)->not->toContain('privacy');
});
