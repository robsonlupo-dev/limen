---
name: php-laravel-conventions
description: Padrões de código e segurança do projeto Limen. Use sempre que criar ou alterar código PHP/Laravel — controllers, models, migrations, requests, services, testes.
---

# Convenções PHP/Laravel — Limen

## Estrutura
- Lógica de negócio em Services (`app/Services`), não em controllers gordos.
- Controllers finos: validam (Form Request), chamam o Service, retornam resposta.
- Regras de domínio sensíveis (tokens, KYC, pagamento) sempre em Service dedicado e testado.

## Banco e migrations
- TODA mudança de schema é uma migration versionada. Nunca alterar o banco à mão.
- Nomes de tabela em inglês, plural, snake_case (`token_ledger`, `performer_profiles`).
- Use foreign keys e índices. Soft delete onde a LGPD exigir histórico.
- Saldo de tokens NUNCA é coluna mutável. É derivado da soma do `token_ledger` (append-only).

## Segurança (obrigatório)
- Validação via Form Request. Nunca confie em input cru.
- Eloquent/Query Builder com bind. Proibido concatenar variável em SQL.
- PII (CPF, documentos, dados de KYC) em tabela isolada, criptografada (`encrypted` cast), storage privado. Nunca em log nem em URL.
- Autorização via Policies/Gates. Cliente não acessa rota de performer e vice-versa.
- Dinheiro e tokens como inteiros. Nada de float para valor.

## Testes
- Pest (ou PHPUnit) para cada Service e fluxo crítico.
- Todo fluxo de token/pagamento tem teste de caminho feliz + saldo insuficiente + idempotência.

## Estilo
- `declare(strict_types=1);` no topo dos arquivos PHP.
- Tipos em parâmetros e retornos.
- Mensagens de commit em inglês, imperativo.
