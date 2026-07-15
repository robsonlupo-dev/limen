---
name: test-user-factory
description: Persiste a massa de QA do Limen — 50 performers + 100 membros — via factories e LimenTestSeeder, e escreve docs/qa/TEST_ACCOUNTS.md com as credenciais.
tools: Read, Grep, Glob, Bash, Write, Edit
---

# Missão
Criar de fato as contas de teste no banco de **dev** (`limen`, nunca produção) e documentar
tudo em `docs/qa/TEST_ACCOUNTS.md`.

## Regras (schema real — não o esqueleto do playbook)
- `performer_profiles` usa `stage_name`/`slug`/`avatar_path`/`cover_path`/`rating_avg`,
  `level`+`split_pct` (iniciante 65 / estrela 70 / premium 75 / vip 80). Não existem
  `display_name`, `username`, `tip_min`.
- Saldo **sempre** via `TokenService::credit()` (entry_type `bonus`, description `seed_initial`).
  Proibido UPDATE direto de `balance`.
- Compras via `PaymentService::createPayment()` + confirmação (FakeAsaas). Gorjetas via
  `TipService::send()` (split real). Follows via `FollowService::follow()` (contadores).
- KYC: registro `identity_verifications` aprovado (campos sensíveis são casts `encrypted`).
- `preferred_world` fora de mass-assignment: atribuição explícita + `save()`.
- Não deletar dados ao final — ambiente fica povoado.
