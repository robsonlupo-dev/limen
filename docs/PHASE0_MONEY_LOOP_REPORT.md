# Fase 0 — Fechamento do Money Loop (Limen)

**Data:** 2026-07-03
**Escopo:** fundação financeira e de segurança da plataforma — do primeiro real
que entra ao primeiro real que sai, com trilha auditável de ponta a ponta.
**Status:** ✅ Concluída. Suíte verde, fluxos validados, revisão de segurança feita.

---

## 1. Objetivo e o que foi provado

A Fase 0 existe para provar que o **ciclo do dinheiro fecha sem furos** antes de
qualquer feature de produto crescer sobre ele. O critério não era "ter as telas",
e sim: **todo token que existe tem origem rastreável, todo movimento é reversível
por auditoria, e nenhum caminho credita ou paga em dobro.**

Ficou provado que:

- Um cliente compra tokens via PIX e o crédito é **idempotente** — reprocessar o
  webhook nunca duplica saldo.
- O saldo de tokens é **derivado de um ledger append-only**, nunca de um
  `UPDATE saldo = saldo + x`. O saldo é a soma das linhas; a auditoria compara os
  dois e nunca diverge.
- Uma gorjeta aplica o **split por nível do performer** de forma determinística
  (retenção da plataforma + crédito do performer) dentro de uma transação atômica.
- Um saque só move dinheiro em **estado terminal explícito**; falha terminal gera
  **estorno por lançamento** no ledger; estado ambíguo **nunca** estorna.
- PII/KYC sensível fica **criptografada em repouso** em disco isolado, fora de log
  e de URL.

## 2. Fluxos validados ponta a ponta

| Fluxo | Mecanismo | Garantia | Cobertura |
|---|---|---|---|
| **Compra de tokens (PIX/Asaas)** | `PaymentService` + webhook por `provider_event_id` | Crédito uma única vez; valor/quantidade sempre do servidor (cliente só manda `token_package_id`) | `PaymentApiTest`, `AsaasHttpClientTest`, `AsaasClientBindingTest` |
| **Gorjeta + split** | `TipService`: debita consumer, credita performer por `floor(amount × split_pct/100)`, plataforma retém o resto; tudo em `DB::transaction` | Split determinístico em inteiros; idempotência por chave; rate limit 10/min; nunca gorjeta a si mesmo | `TipPhase6Test`, `TipWebTest` |
| **Saque pago (payout)** | `PayoutService`: cria transfer Asaas, webhook `TRANSFER_PAID` → estado terminal `paid`; chave PIX mapeada ao enum Asaas (random→EVP) | Idempotente; só finaliza em estado terminal; webhook tratado como conteúdo não confiável | `PayoutTest` |
| **Estorno (falha de saque)** | `TRANSFER_FAILED` → lançamento `payout_reversal` devolve os tokens; guarda de estado terminal | Anti-pagamento-em-dobro: estado ambíguo nunca estorna; terminal nunca reprocessa | `PayoutTest` |

Reconciliação agendada cobre os dois lados como rede de segurança:
`payments:reconcile` (`ReconcilePayments`) reprocessa pagamentos com webhook
perdido/timeout, e `tokens:reconcile-wallets` (`ReconcileWallets`) audita todas as
carteiras contra o ledger.

## 3. Arquitetura de segurança implementada

- **Ledger append-only (regra inegociável).** `TokenService::credit/debit` só
  inserem linhas em `token_ledger`; `balance()` é `SUM(amount)`. Tipos de
  lançamento em uso: `tip_credit`, `spend_tip`, `payout_reversal`, `bonus`,
  `adjustment`, `staging_seed_backfill`. **Zero `UPDATE` direto de saldo** no
  código de produção.
- **Reconciliação de carteiras.** `tokens:reconcile-wallets` compara `balance` vs
  soma do ledger e corrige eventual resíduo por **lançamento de ajuste** (nunca por
  UPDATE). A origem histórica do resíduo (seeding antigo que setava `balance`
  direto) foi eliminada: **performers nascem com saldo 0** e ganham via
  gorjeta/sessão (Etapa 7.3).
- **KYC criptografado em repouso.** `App\Services\Kyc\KycDocumentStore` grava os
  documentos no disco privado isolado `kyc`, cifrados com `Crypt::encryptString`
  (AES-256 via `APP_KEY`), sufixo `.enc` marcando ciphertext — nunca uma imagem
  servível. Decodificação só sob demanda no servidor; nada em log, nada em URL
  (Fase 5 + Etapa 7.1).
- **Idempotência de pagamento.** `PaymentEvent` chaveado por `provider_event_id`;
  crédito acontece uma vez; falha deixa `processed_at` nulo para o
  `payments:reconcile` retentar. Webhook validado e cobrança reconsultada no
  gateway antes de creditar.
- **Anti-pagamento-em-dobro no payout.** `PayoutService` só transiciona dinheiro em
  estado terminal (`paid`/`failed`/`cancelled`), com lock; estado não-terminal
  jamais estorna (Etapa 7 — resolvido).
- **Guard de seeder fail-closed.** `Database\Seeders\Concerns\RefusesUnsafeEnvironment`:
  allowlist `local/testing/development/staging` decidida pela **união dos sinais**
  (APP_ENV bruto do processo + `app()->environment()`), **imune a `config:cache`**.
  As três contas base exigem `SEED_ADMIN_PASSWORD` fora de local/testing — nenhuma
  credencial pública em ambiente alcançável (Etapa 7.2).

## 4. Números

- **Suíte de testes:** **215 testes verdes / 920 asserções** (suíte completa contra
  MySQL 8.4). 0 falhas, 0 skips relevantes.
- **Cobertura de feature:** 21 arquivos de teste de feature — auth/cadastro,
  pagamento/Asaas, gorjeta/split, payout/estorno, wallet/ledger, KYC, catálogo,
  guard de seeder e reconciliação.
- **Auditoria de carteiras:** `tokens:reconcile-wallets` audita **100% das
  carteiras** (balance × soma do ledger). Resíduo histórico corrigido por
  lançamento; após a Etapa 7.3, divergência **0 por construção** (saldo só nasce do
  ledger).
- **Regra do ledger:** **0** ocorrências de `UPDATE ... balance` em código de
  produção.
- **Fixture de staging:** 50 performers + 100 membros + 3 contas base, povoados
  **via ledger** (nunca crédito direto de saldo).

## 5. O que fica para o Sprint 2

- **Retenção e expurgo de KYC.** A cifragem em repouso está pronta; falta a política
  de retenção/expurgo dos documentos e a **estratégia de rotação de `APP_KEY`**
  (rotacionar hoje quebra a decodificação — precisa de re-cifragem planejada).
- **Deploy — permissão do vendor.** O passo de Deploy via SSH morre no
  `composer install --no-dev` por dono errado de `vendor/` no servidor; falta
  corrigir ownership/sudoers de forma definitiva.
- **Staging — origem :8443 × APP_URL :443.** O descasamento de origem quebra POSTs
  do Inertia (ex.: logout); alinhar origem/`APP_URL`/CSRF no túnel de staging.
- **Frontend.** Fase 8: catálogo de performers no frontend (descoberta, cards,
  filtros, perfil público). Streaming (LiveKit) permanece no roadmap pós-fundação.
- **Endurecimento de senha de seed.** Considerar política mínima de força para
  `SEED_ADMIN_PASSWORD` em staging/development (hoje só se exige presença).

## 6. Assinatura

Fase 0 (money loop) encerrada com a suíte verde, os quatro fluxos financeiros
validados ponta a ponta e a revisão de segurança concluída em cada etapa sensível.

**Data:** 2026-07-03
**Product Owner:** Robson (robsonlupo-dev)
**Implementação e revisão:** Claude Code (Opus 4.8) — subagente de segurança em
cadastro/KYC/pagamento/payout.
