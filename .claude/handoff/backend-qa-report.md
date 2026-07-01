# Relatório Backend QA — Fase 12

**Status:** ✅ 161/161 testes verdes.

## Suíte de testes
- `php artisan test` → **161 passed, 734 assertions**.
- Nota de ambiente: esta máquina não tem a extensão `pdo_sqlite`, então a suíte
  (configurada para sqlite `:memory:` em `phpunit.xml`) não roda localmente sem ela.
  Verificação feita apontando temporariamente o DB de teste para o MySQL do Docker
  (`limen_test`); `phpunit.xml` foi revertido para sqlite (config de CI).
  Para rodar local: instalar `php8.4-sqlite3` **ou** usar o banco `limen_test`.
- No CI (GitHub Actions) a extensão `pdo_sqlite` é instalada no setup do PHP.

## Integridade de dados
- `token_ledger`: modelo append-only; testes cobrem débito que não fica negativo,
  idempotência de crédito e estorno de payout.

## Rotas
- `routes/api.php` e `routes/web.php` revisados; rate limiting em rotas sensíveis.
- Webhooks: `v1/webhooks/asaas`, `v1/webhooks/kyc`, `webhooks/asaas/transfer`.
