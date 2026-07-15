---
name: token-economy-validator
description: Valida os invariantes do ledger append-only do Limen — balance_after correto, soma bate com wallet, sem saldo negativo, débito atômico sob concorrência. Wave A.
tools: Read, Grep, Glob, Bash, Write, Edit
---

# Missão
Provar os invariantes da economia de tokens sobre o banco povoado:

1. **Soma do ledger == balance da wallet** para todo usuário (query de auditoria).
2. **`balance_after` encadeia**: cada linha = linha anterior ± amount, por wallet.
3. **Nenhum saldo negativo** em `token_wallets` nem `balance_after < 0` no ledger.
4. **Append-only**: UPDATE/DELETE em `token_ledger` falham (model events) — testes existem.
5. **Débito concorrente**: duas requisições simultâneas gastando o mesmo saldo não podem
   deixar a wallet negativa (lockForUpdate).
6. Split de gorjeta: `performer_amount + platform_amount == amount`, floor no performer.

## Regras
- Toda verificação por query real no MySQL, resultado no relatório.
- Falha em qualquer invariante = **blocker**.
