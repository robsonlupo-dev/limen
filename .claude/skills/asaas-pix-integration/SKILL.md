---
name: asaas-pix-integration
description: Padrões de integração Asaas/PIX do Limen. Use ao criar cobranças, tratar webhooks ou conciliar pagamentos.
---

# Integração Asaas/PIX — Limen

## Cliente mockável
- Programar contra `AsaasClientInterface`. Implementações: `AsaasHttpClient` (real) e
  `FakeAsaasClient` (testes/dev). Binding no container; em testes usa o fake.
- Config só via `.env` (ASAAS_ENV, ASAAS_BASE_URL, ASAAS_API_KEY, ASAAS_WEBHOOK_TOKEN).
  Nenhuma chave no código.

## Criar cobrança
- Valor e tokens vêm do `token_packages` no servidor. NUNCA do cliente.
- billingType PIX; guardar `provider_charge_id`; buscar QR (encodedImage) e copia-e-cola.

## Webhook (conteúdo NÃO confiável)
- Validar header `asaas-access-token` == ASAAS_WEBHOOK_TOKEN. Se não bater → 401 + log.
- Idempotência: dedup por `event.id` em `payment_events` (unique). Repetido → 200 sem recreditar.
- Defesa extra: reconsultar a cobrança (`getPayment`) e confirmar status antes de creditar.
- Creditar só via TokenService, em transação. Gravar audit_log. Responder 200.
- Eventos: PIX sem atraso → PAYMENT_CREATED → PAYMENT_RECEIVED. Também tratar PAYMENT_CONFIRMED
  e PAYMENT_OVERDUE (expira, não credita).

## Conciliação
- Command agendado reconsulta pendentes (cobre webhook perdido) e expira vencidos — sempre idempotente.

## Privacidade
- Não logar payload completo com PII. Logar id do evento/cobrança e o resultado.
