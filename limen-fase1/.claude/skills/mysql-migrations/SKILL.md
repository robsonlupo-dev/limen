---
name: mysql-migrations
description: Como criar migrations MySQL no projeto Limen com segurança. Use ao criar ou alterar qualquer tabela, coluna, índice ou relação.
---

# Migrations MySQL — Limen

## Regras
- TODA mudança de schema é uma migration nova e versionada. Nunca alterar o banco à mão nem editar uma migration já aplicada — crie outra.
- Sempre defina `down()` reversível.
- FKs explícitas com `onDelete` adequado; índices em colunas de busca e em FKs.
- Dinheiro/tokens como inteiros: tokens (`bigInteger`/`unsignedInteger`) e centavos (`unsignedInteger price_cents`). Nunca float/decimal para valor.
- PII sensível (CPF, documentos, data de nascimento, nome legal) em coluna `text` com **cast `encrypted`** no model, em tabela isolada. Nunca em índice em texto puro, nunca em log.
- `token_ledger` é append-only: sem `updated_at`, sem soft delete; nada de update/delete de linha.
- Saldo (`token_wallets.balance`) só muda dentro de transação com lock de linha, junto de um insert no ledger.

## Procedimento
1. Rodar a migration sempre contra a base de DEV (Docker). Nunca produção.
2. Após criar: `php artisan migrate` e conferir no Adminer.
3. Se precisar recomeçar do zero em dev: `php artisan migrate:fresh --seed`.
4. Para mudança em tabela existente já versionada: nova migration de alteração, nunca editar a antiga.

## Checklist antes de marcar pronto
- [ ] `up()` e `down()` testados (migrate e rollback funcionam)
- [ ] FKs e índices presentes
- [ ] Nenhum valor monetário em float
- [ ] PII com cast encrypted e em tabela isolada
- [ ] Seeder roda sem erro com dados sintéticos
