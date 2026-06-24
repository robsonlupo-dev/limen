---
name: security-checklist
description: Checklist de segurança e compliance do Limen. Aplique em QUALQUER código que toque cadastro, autenticação, PII, idade/KYC, tokens ou pagamento.
---

# Checklist de Segurança — Limen

Plataforma adulta: PII sensível, 18+ dos dois lados, pagamento. Trate como prioridade.

## Sempre
- [ ] Input validado via Form Request (nunca confiar no que vem do cliente).
- [ ] Autorização via Policy/Gate (consumer não acessa rota de performer/admin e vice-versa).
- [ ] Eloquent/Query Builder com bind. Proibido concatenar variável em SQL.
- [ ] Nenhum segredo no código — só em `.env` (fora do Git).
- [ ] PII criptografada em repouso, em tabela isolada, storage privado. Nunca em log nem em URL.

## Idade e identidade
- [ ] Gate 18+ no cadastro (rejeita menor e data futura).
- [ ] Performer entra como `pending`; só vira `active` após verificação.
- [ ] Prova de idade/KYC guardada de forma auditável (`identity_verifications`).

## Tokens e pagamento
- [ ] Saldo é derivado do ledger append-only; nunca `UPDATE saldo = saldo + x` solto.
- [ ] Débito nunca deixa saldo negativo (transação + lock de linha).
- [ ] Crédito por pagamento só via webhook **idempotente** (dedup por id de evento).
- [ ] Ação sensível (verificação, pagamento, payout, mudança de role) gera `audit_logs`.

## Autenticação
- [ ] Senha forte (≥8, maiúscula, número), hash bcrypt.
- [ ] Rate-limit no login (anti brute force).
- [ ] Token de sessão com expiração (Sanctum).
