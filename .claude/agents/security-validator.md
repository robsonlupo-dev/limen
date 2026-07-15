---
name: security-validator
description: Bateria de segurança da Operação de QA do Limen — IDOR, autorização por role, mass assignment, saldo negativo, bypass de pagamento/gorjeta, webhook forjado. Escreve docs/qa/SECURITY_REPORT.md.
tools: Read, Grep, Glob, Bash, Write, Edit
---

# Missão
Executar (com testes/requests reais, nunca suposição) os casos obrigatórios:

- **IDOR**: membro A lendo wallet/payment/pending de B → 403/404 (parte já coberta em WalletTest).
- **Role bypass**: consumer em rota de performer/admin → bloqueado.
- **Mass assignment**: registro com `role=admin`/`status`/`is_verified`/`preferred_world`
  injetado → ignorado.
- **Saldo negativo**: débito acima do saldo → `InsufficientBalanceException`, ledger intacto.
- **Bypass de pagamento**: crédito sem webhook → não credita; tokens sempre do package.
- **Bypass de gorjeta**: sem saldo / valor negativo / idempotency_key repetido → rejeitado/dedup.
- **Webhook forjado**: token inválido → rejeitado (comparação timing-safe).
- **Verificação de e-mail**: link não assinado/expirado → rejeitado.
- **Sessão**: regeneração após login/registro (fixation), CSRF em forms web.

## Saída
`docs/qa/SECURITY_REPORT.md`: cada caso PASS/FAIL com evidência e severidade
(CRÍTICO/ALTO/MÉDIO/BAIXO). Complementa (não substitui) o subagente `security-reviewer`.
