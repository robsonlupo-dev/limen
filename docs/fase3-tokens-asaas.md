<!-- Vocabulário: "Fase N" neste doc é LEGADO (ciclo da fundação) e NÃO
     corresponde ao "Sprint N" atual. Ex.: Fase 4 = perfis/catálogo;
     Sprint 4 = chat. O ciclo de entrega vigente é "Sprint N" — ver CLAUDE.md. -->

# Fase 3 — Compra de Tokens + Asaas/PIX (Limen)

O motor de receita. Encaixa nas tabelas da Fase 1 (payments, payment_events,
token_ledger) e no TokenService. Construído **test-first com cliente Asaas mockável**:
~90% fica pronto e testado SEM chave real. A chave sandbox só é necessária no teste ao vivo.

## Princípio central de segurança
- **Valor e tokens são sempre do servidor.** O cliente só manda `token_package_id`.
  Nunca confiar em valor/quantidade vindos do cliente (evita fraude de preço).
- **Crédito de tokens é idempotente.** Reprocessar o mesmo evento NUNCA credita 2x.
- **Webhook é conteúdo não confiável.** Validar o `authToken` do Asaas e, por segurança,
  reconsultar a cobrança no Asaas antes de creditar.

## Arquitetura do cliente Asaas (mockável)
- Interface `App\Services\Asaas\AsaasClientInterface` (createCustomer, createPixCharge,
  getPixQrCode, getPayment).
- Implementação real `AsaasHttpClient` (usa `Http::` com base URL e API key do `.env`).
- Implementação fake `FakeAsaasClient` para testes/dev (retornos determinísticos).
- Binding no container: em ambiente de teste usa o fake. Sem chave real para construir/testar.

## Migration adicional
- Adicionar coluna `asaas_customer_id` (string, nullable) em `users`.

## Endpoints (sob /api/v1, auth:sanctum)

`GET /token-packages` — lista pacotes ativos (id, slug, name, tokens, preço formatado).

`POST /payments` (throttle) — body: `token_package_id`
- carrega o pacote no servidor (valor e tokens autoritativos);
- garante um customer Asaas para o user (cria se não existir; guarda `asaas_customer_id`);
- cria cobrança PIX no Asaas (value = price_cents/100, billingType PIX, dueDate, externalReference);
- busca o QR Code PIX (encodedImage + copia-e-cola);
- grava `payments` (status=pending, provider_charge_id, pix_qr_code, pix_copy_paste, expires_at, tokens, amount_cents);
- retorna 201 com { payment_id, status, pix_qr_code, pix_copy_paste, expires_at, amount, tokens }.

`GET /payments` — lista pagamentos do próprio user.
`GET /payments/{id}` — status do pagamento (Policy: só o dono). Para o cliente fazer polling.

`POST /webhooks/asaas` — SEM auth:sanctum, mas:
- valida header `asaas-access-token` == `ASAAS_WEBHOOK_TOKEN`; se não bater → 401 e log;
- idempotência: tenta inserir em `payment_events` pelo `event.id`; se já existe → 200 (já processado);
- localiza o payment por `provider_charge_id` (= event.payment.id);
- em `PAYMENT_RECEIVED`/`PAYMENT_CONFIRMED`: (reconsultar a cobrança no Asaas para confirmar) →
  numa transação: marca payment confirmed, credita tokens via `TokenService.credit`
  (entry_type=purchase, reference=payment), seta confirmed_at, grava audit_log `payment.confirmed`;
- em `PAYMENT_OVERDUE`: marca expired (não credita);
- marca `processed_at` no evento; responde 200.

## Tarefa agendada (automação que eu já entrego)
- Command `payments:reconcile`:
  - para cada payment pending além do prazo, reconsulta no Asaas; se pago e ainda não creditado,
    credita de forma idempotente (cobre webhook perdido);
  - marca como expired os pending vencidos.
- Registrar no scheduler (a cada 10 min). Em dev, rodar com `php artisan schedule:work`.

## Configuração (.env — ver .env.asaas-trecho.txt)
- ASAAS_ENV=sandbox
- ASAAS_BASE_URL=https://sandbox.asaas.com/api/v3   (produção: https://api.asaas.com/v3)
- ASAAS_API_KEY= (gerar em Integrações na conta sandbox; sandbox e produção têm chaves distintas)
- ASAAS_WEBHOOK_TOKEN= (token de autenticação do webhook; o servidor valida contra o header)

## Regra do ledger (aplicar skill token-ledger-rules)
- 1 lançamento `purchase` por payment (guard por reference_type+reference_id — não duplica).
- Crédito só via TokenService (transação + lock); saldo derivado; ledger imutável.

## Testes (Pest, com FakeAsaasClient) obrigatórios
1. listar pacotes → só ativos.
2. criar pagamento → cria payment pending + cobrança (fake) + retorna QR/copia-e-cola;
   valor/tokens derivados do servidor (ignora valor mandado pelo cliente).
3. webhook com authToken errado/ausente → 401, nada creditado.
4. webhook PAYMENT_RECEIVED válido → payment confirmado, tokens creditados 1x, ledger 'purchase', audit log.
5. mesmo event.id duas vezes (idempotência) → credita só 1x.
6. webhook de cobrança desconhecida → tratado sem quebrar.
7. GET /payments/{id} de outro user → 403.
8. `payments:reconcile` credita pending que o Asaas reporta pago (webhook perdido), idempotente.
9. pending vencido → marcado expired pelo reconcile.
10. criar pagamento para pacote inativo/inexistente → 422.

## Definição de pronto
- endpoints respondendo; FakeAsaasClient cobre os testes; suíte verde.
- subagente `security-reviewer` rodado no fluxo de pagamento + webhook, achados triados.
- (depois, com sua chave sandbox) teste ao vivo: criar cobrança e simular pagamento no Asaas.
