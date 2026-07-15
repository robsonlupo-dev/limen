# LIMEN — TEST RESULTS (Operação de QA · 02/07/2026)

> Consolidação qa-lead. Fonte: execução real da suíte Pest contra MySQL `limen_test`
> (`181 testes, 808 asserts — 100% verdes`), auditoria SQL sobre o banco de dev povoado
> (50 performers / 100 membros / 755 follows / 444 tips / 266 payments / 1243 linhas de
> ledger) e verificação estática dirigida. Nenhum resultado inventado.

## Resumo

**181 PASS · 0 FAIL · 5 módulos N/A (não implementados)**

## Por módulo

| Módulo | Testes | Pass | Fail | N/A | Status |
|---|---|---|---|---|---|
| Auth API (register/login/logout/me) | 22 | 22 | 0 | — | ✅ |
| Auth Web + e-mail PT-BR + reset | 27 | 27 | 0 | — | ✅ |
| Form Requests (idade, termos, senha) | 5 | 5 | 0 | — | ✅ |
| Tokens / ledger (TokenService) | 4 | 4 | 0 | — | ✅ |
| Pagamento PIX / webhook / reconcile | 14 | 14 | 0 | — | ✅ |
| Wallet (UI + isolamento) | 16 | 16 | 0 | — | ✅ |
| Gorjetas (split, idempotência, rate limit) | 17 | 17 | 0 | — | ✅ |
| Payout (PIX transfer, reversão) | 20 | 20 | 0 | — | ✅ |
| KYC (submit, webhook, resubmissão) | 12 | 12 | 0 | — | ✅ |
| Perfis / catálogo / follows | 22 | 22 | 0 | — | ✅ |
| Frontend Inertia (renderização, age gate) | 15 | 15 | 0 | — | ✅ |
| Gaps da Operação de QA (novos) | 8 | 8 | 0 | — | ✅ |
| **Feed / posts** | — | — | — | ✓ | N/A NÃO IMPLEMENTADO |
| **Conteúdo pago destravável** | — | — | — | ✓ | N/A NÃO IMPLEMENTADO |
| **Chat / mensagens** | — | — | — | ✓ | N/A NÃO IMPLEMENTADO |
| **Streaming (LiveKit)** | — | — | — | ✓ | N/A NÃO IMPLEMENTADO |
| **Analytics de produto** | — | — | — | ✓ | N/A NÃO IMPLEMENTADO |

## Wave A — dinheiro e auth (evidências-chave)
- Webhook PIX: replay do mesmo `event.id` **não** duplica crédito (`PaymentApiTest`).
- Webhook com token errado → rejeitado; transfer webhook idem (`PayoutTest`).
- Split por nível verificado para os 4 níveis (65/70/75/80%).
- Débito acima do saldo → exceção, **zero** linha no ledger.
- Payout falho → status `failed` + estorno `payout_reversal` no ledger.
- Login de suspenso/banido bloqueado (web 422 + guest; API 401) — **gap coberto nesta operação**.

## Wave B — frontend
- `npm run build` limpo.
- **Cruzamento Ziggy**: todas as chamadas `route('…')` nos `.vue` presentes no allowlist
  (`config/ziggy.php`) — zero divergência (proteção contra o bug histórico da tela preta).
- Renderização Inertia das páginas cobertas (`WebPhase7Test`, `UxFixesFase12Test`).
- Catálogo por mundo + preferências + filtros ✅.

## Wave C — auditoria sobre o banco povoado (SQL real)

| Invariante | Falhas |
|---|---|
| `wallet.balance` == Σ ledger (todas as wallets) | **0** |
| Encadeamento `balance_after` (janela por wallet) | **0** |
| Saldo negativo (wallets e ledger) | **0** |
| `performer_amount + platform_amount == amount` (444 tips) | **0** |
| Trilha de auditoria (`tip.sent`/`tip.received` 446+446, `payment.created` 267, `payment.confirmed` 266) | consistente |

## Falhas detalhadas
Nenhuma falha aberta. Três falhas transitórias durante a construção dos testes de gap foram
diagnósticos do próprio teste (contrato real: API usa `performer_slug` + UUID; login API
retorna 401; `preferred_world` no cadastro é feature validada por enum, não mass assignment).

## Bugs (bug-hunter) — priorizados
1. **[ALTO · produto] Gorjeta desabilitada na UI** (`Catalog/Show.vue`): modal "Em breve"
   com API funcional — loop de monetização quebrado no front. Ver UX_REPORT.md.
2. **[MÉDIO · config] FakeAsaas só em `testing`** (`AppServiceProvider`): em `local` o
   binding resolve `AsaasHttpClient` real sem credenciais — qualquer fluxo de pagamento em
   dev manual quebra silenciosamente. Sugerido: respeitar um `ASAAS_PROVIDER=fake` no .env
   (paralelo ao `KYC_PROVIDER=fake`). O seeder contorna com binding local.
3. **[BAIXO · dx] `.env.example` com sqlite** enquanto o projeto exige MySQL — fricção de
   onboarding (erro "could not find driver").
4. **[BAIXO · ux] Reenvio de verificação sem feedback visível** (`VerifyEmail.vue`).

## Critério do qa-lead
Nenhum blocker: todos os testes de segurança de dinheiro passam (saldo negativo impossível,
dedup de webhook comprovado, ledger íntegro nos 1243 lançamentos da massa povoada).
