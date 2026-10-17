<?php

namespace App\Support;

use App\Models\User;

/**
 * Conta de TESTE/UAT — alimenta o selo verde "Teste" no catálogo e no perfil.
 *
 * Disciplina de UAT num ambiente compartilhado (dev == prod): o testador
 * precisa distinguir a massa sintética do que seria real. É DERIVADO do e-mail,
 * sem coluna nova — domínio reservado dos seeders OU e-mail avulso na allowlist
 * (config/accounts.php). Some sozinho no go-live: conta real tem domínio real e
 * não casa, e o interruptor `test_badge_enabled` apaga tudo de uma vez.
 *
 * NUNCA expõe o e-mail — só o booleano derivado. Não é sinal de presença, então
 * (ao contrário do "Voltou"/activity) não é suprimido por appear_offline.
 */
class TestAccount
{
    public static function isTest(?User $user): bool
    {
        if (! config('accounts.test_badge_enabled', true)) {
            return false;
        }

        $email = mb_strtolower((string) ($user?->email ?? ''));

        if ($email === '' || ! str_contains($email, '@')) {
            return false;
        }

        $domain = substr(strrchr($email, '@'), 1);

        return in_array($domain, (array) config('accounts.test_domains', []), true)
            || in_array($email, (array) config('accounts.test_emails', []), true);
    }
}
