---
name: token-ledger-rules
description: Regras do ledger e do saldo de tokens do Limen. Use ao creditar, debitar ou consultar tokens, ou ao integrar pagamento/gorjeta/payout.
---

# Regras do Ledger de Tokens — Limen

## Invariantes
- Saldo é DERIVADO do `token_ledger` (append-only). Nunca `UPDATE saldo = saldo + x` solto.
- Todo movimento passa pelo `TokenService` (credit/debit), que:
  - abre transação e dá `SELECT ... FOR UPDATE` no wallet (lock de linha);
  - calcula novo saldo; débito NUNCA deixa negativo (lança exceção);
  - insere linha no ledger com `balance_after`;
  - atualiza `token_wallets.balance`; commit.
- Ledger é IMUTÁVEL: nunca update/delete de linha.

## entry_type
- purchase (crédito por compra) · spend_tip · spend_private · spend_camera ·
  payout_reserve · refund · bonus · adjustment.
- `amount` sinalizado: + crédito, − débito. Sempre gravar `balance_after`.
- Vincular ao fato gerador: `reference_type` + `reference_id` (ex.: 'payment', id).

## Idempotência
- Um lançamento `purchase` por payment. Antes de creditar, verificar que não existe
  ledger com (reference_type='payment', reference_id=X). Reprocessar não duplica.

## Dinheiro
- tokens e centavos como inteiros. Nunca float.
