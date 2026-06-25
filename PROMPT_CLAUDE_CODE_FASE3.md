# Briefing para o Claude Code — Fase 3

Cole no Claude Code (raiz do projeto). Reinicie a sessão antes (skills novas). Revise antes de aplicar.

---

Você é o dev do projeto Limen. Leia `CLAUDE.md`, as skills `token-ledger-rules`,
`asaas-pix-integration`, `laravel-api-conventions` e `security-checklist`, e a
especificação `docs/fase3-tokens-asaas.md`. Implemente a Fase 3 conforme a spec:

1. Migration: adicionar `asaas_customer_id` (string nullable) em users.
2. Cliente Asaas mockável: `AsaasClientInterface` + `AsaasHttpClient` (Http facade,
   base URL/API key do .env) + `FakeAsaasClient`. Binding no container; testes usam o fake.
3. Config `config/asaas.php` lendo o .env. NENHUMA chave no código.
4. Endpoints sob /api/v1: GET token-packages; POST payments (valor e tokens do SERVIDOR,
   nunca do cliente; cria customer + cobrança PIX + grava payment pending + retorna QR e
   copia-e-cola); GET payments; GET payments/{id} (Policy: só o dono).
5. POST webhooks/asaas: validar header asaas-access-token; idempotência por event.id em
   payment_events; ao receber pagamento, reconsultar a cobrança, e em transação confirmar
   o payment + creditar tokens via TokenService (1x, idempotente) + audit_log. PAYMENT_OVERDUE
   marca expired.
6. Command `payments:reconcile` (cobre webhook perdido + expira vencidos), registrado no
   scheduler a cada 10 min.
7. Escreva os 10 testes da spec usando o FakeAsaasClient.

Regras: valor/tokens sempre do servidor; crédito idempotente; webhook é não confiável;
nada de segredo no código; dinheiro/tokens como inteiros.

Quando terminar:
- invoque o subagente `security-reviewer` sobre o fluxo de pagamento + webhook e traga os achados;
- rode os testes e corrija até verde;
- me mostre resumo, resultado dos testes e achados de segurança.

Não faça nada além da Fase 3. Em caso de ambiguidade, pergunte antes de assumir.
