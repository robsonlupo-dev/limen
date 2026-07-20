<!-- Vocabulário: "Fase N" neste doc é LEGADO (ciclo da fundação) e NÃO
     corresponde ao "Sprint N" atual. Ex.: Fase 4 = perfis/catálogo;
     Sprint 4 = chat. O ciclo de entrega vigente é "Sprint N" — ver CLAUDE.md. -->

# LIMEN — QA HANDOFF MASTER

> Gerado em 02/07/2026 a partir do estado real: `ls tests/`, `ls .claude/agents/`,
> `php artisan test --testdox` (173 testes verdes), inspeção de seeder/factories.
> Objetivo: dar a um QA (agente ou humano) o mapa exato do que testar — e o que **não** testar,
> para não reportar bug falso em feature que ainda não existe.

---

## ESTRATÉGIA DE QA

Só existe hoje **1 subagente** no repo: `.claude/agents/security-reviewer.md`. A operação de
QA planejada (arquivo `LIMEN-QA-OPERATION.md`, ainda não versionado no repo) prevê **16 agentes**.
Papéis planejados:

| Agente | Papel |
|--------|-------|
| orchestrator | Coordena a operação, dispara os demais, consolida relatórios |
| qa-lead | Define plano de teste, prioriza, valida cobertura |
| backend-qa | Testa services/controllers/regras de domínio (ledger, split, idempotência) |
| frontend-qa | Testa telas Vue/Inertia, navegação, estados de erro |
| ux-validator | Valida fluxos de UX (entrada, age gate, onboarding) tela a tela |
| payments-validator | PIX/Asaas: cobrança, webhook idempotente, reconciliação |
| token-economy-validator | Compra/gasto/saldo sempre via ledger; sem saldo negativo |
| chat-validator | (feature de DESIGN — nada a validar ainda) |
| media-validator | Upload/armazenamento privado de mídia; tipos/limites |
| feed-validator | (feature de DESIGN — nada a validar ainda) |
| security-validator | IDOR, mass assignment, bypass de pagamento/gorjeta, webhook forjado |
| load-test-validator | Carga (k6) com ressalva de VPS de dev satura cedo |
| synthetic-data-generator | Gera massa sintética (performers/membros) só via Fake + TokenService |
| test-user-factory | Cria contas de teste padronizadas e documentadas |
| analytics-validator | (feature de DESIGN — nada a validar ainda) |
| bug-hunter | Exploração livre em busca de regressões |

> **Importante:** a operação de QA popula o banco e roda a bateria de forma autônoma. Só deve
> usar `FakeAsaasClient`/`FakeKycClient` e creditar saldo **exclusivamente** via `TokenService`
> (ledger). Nunca `UPDATE` direto de saldo.

---

## MATRIZ DE CAPACIDADE (o que existe no código real)

| Módulo | Status | Onde |
|--------|--------|------|
| Auth (register/login/logout/me) | ✅ IMPLEMENTADO | `Api/V1/Auth/*`, `Web/Auth/*` |
| Verificação de e-mail (PT-BR) | ✅ IMPLEMENTADO | `EmailVerificationController` |
| Reset de senha (PT-BR) | ✅ IMPLEMENTADO | `Forgot/ResetPasswordController` |
| Roles/autorização | ✅ IMPLEMENTADO | `EnsureUserHasRole`, policies |
| Wallet / saldo (ledger) | ✅ IMPLEMENTADO | `TokenService`, `WalletController` |
| Compra de tokens / PIX (Asaas) | ✅ IMPLEMENTADO (Fake) | `PaymentService`, `AsaasClient*` |
| Webhook idempotente Asaas | ✅ IMPLEMENTADO | `AsaasWebhookController`, `PaymentEvent` |
| Reconciliação de pagamentos | ✅ IMPLEMENTADO | `ReconcilePayments` |
| Gorjetas (split por nível) | ✅ IMPLEMENTADO | `TipService`, `TipController` |
| Payout performer (PIX transfer) | ✅ IMPLEMENTADO | `PayoutService`, transfer webhook |
| KYC performer | ✅ IMPLEMENTADO (Fake) | `KycService`, `KycClient*` |
| Follows | ✅ IMPLEMENTADO | `FollowService` |
| Catálogo (auth-gated, por mundo) | ✅ IMPLEMENTADO | `CatalogController`, `PerformerCatalogService` |
| Perfil público de performer | ✅ IMPLEMENTADO | `Catalog/Show.vue` |
| Upload de mídia (avatar/cover) | ✅ IMPLEMENTADO | `PerformerMediaController`, `Onboarding` |
| Dashboard de performer | ✅ IMPLEMENTADO | `DashboardController` |
| **Feed de posts** | ❌ NÃO IMPLEMENTADO (DESIGN) | — |
| **Conteúdo pago destravável** | ❌ NÃO IMPLEMENTADO (DESIGN) | — |
| **Chat** | ❌ NÃO IMPLEMENTADO (DESIGN) | — |
| **Streaming (LiveKit)** | ❌ NÃO IMPLEMENTADO (DESIGN) | — |
| **Analytics** | ❌ NÃO IMPLEMENTADO (DESIGN) | — |

> ❌ = não reportar bug. Se um teste referencia esses módulos, é falso positivo.

---

## TESTES EXECUTADOS (Pest atual — 173 testes, 785 asserts, todos ✅)

| Arquivo | Cobre |
|---------|-------|
| `AuthApiTest` | login/logout/me, tokens Sanctum |
| `RegisterConsumerRequestTest` | idade <18 rejeitada, data futura, termos, senha fraca |
| `PaymentApiTest` | criar cobrança, webhook, listagem |
| `PerformerPhase4Test` | perfis, catálogo ativo+verificado, follow idempotente |
| `CatalogPhase8Test` | filtros por categoria, busca, perfil não expõe user_id/email/CPF |
| `KycPhase5Test` | submit/status/resubmissão, webhook |
| `TipPhase6Test` | split por nível (65/70/75/80%), insufficient balance, self-tip, rate limit 10/min, rollback, idempotência |
| `TokenServiceTest` | credit/debit, saldo=ledger, bloqueia update/delete do ledger |
| `WalletTest` | tokens sempre do package (não do request), não expõe payment de outro user, pending/history isolados |
| `PayoutTest` | payout via PIX, regras |
| `PerformerDashboardTest` | dashboard |
| `WebPhase7Test` | render Inertia, register/login/logout web, age gate cookie, redirects |
| `UxFixesFase12Test` | entrada role picker, mass assignment admin bloqueado, e-mail PT-BR, reset, preferred world |

---

## TESTES PENDENTES / CRÍTICOS (o que ainda falta cobrir end-to-end)

| Fluxo | O que testar | Como |
|-------|--------------|------|
| Cadastro membro/performer | Fluxo web completo até verificação | E2E navegador via túnel 8443 |
| Login / recuperação de senha | Link de reset PT-BR chega e funciona | E2E + inbox log mailer |
| KYC | Submit → webhook aprovado/rejeitado → e-mail → performer aparece no catálogo | Fake KYC + fila |
| Wallet / compra de tokens | Selecionar pacote → PixModal → webhook paga → saldo credita (ledger) | Fake Asaas + webhook manual |
| Webhook idempotente | Reenviar mesmo evento não duplica saldo | POST duplicado |
| Gorjetas | Enviar gorjeta → split correto → saldo debita/credita → rate limit | E2E + verificação do ledger |
| Payout | Solicitar → transfer webhook → reserva no ledger | Fake transfer |
| Catálogo por mundo | Trocar `preferred_world`, filtros, perfil | E2E |
| Dashboard performer | Métricas batem com ledger | E2E |
| Perfis | Perfil público não vaza PII | Asserção de resposta |

---

## MASSA DE TESTE

**Alvo:** 50 performers + 100 membros.

**Distribuição por mundo** (categorias reais: `mulheres, homens, casais, trans, gls, swing`):
distribuir os 50 performers pelos 6 mundos (ex.: 15 mulheres, 10 homens, 8 casais, 7 trans,
5 gls, 5 swing) e definir `preferred_world` dos 100 membros de forma proporcional.

**Níveis de performer** (`split_pct`): iniciante 65%, estrela 70%, premium 75%, vip 80%.
Distribuir os 50 pelos 4 níveis.

**Saldos:** creditar tokens aos membros **sempre via `TokenService`** (linha nova no
`token_ledger`, `entry_type = bonus` ou `purchase`), variando de 0 a alguns milhares.
Gerar histórico de compras/gorjetas para telas não ficarem vazias.

**Regras obrigatórias:**
- Avatares placeholder (dicebear / pravatar / picsum) — nunca imagem real.
- CPF **fake com dígito verificador válido** (respeitar `Rules/CpfValido`).
- E-mails `@teste.limen.local`.
- Senha padrão única e **documentada** em `TEST_ACCOUNTS.md`.
- SÓ `FakeAsaasClient` / `FakeKycClient`. Nunca provedor real.
- Saldo SEMPRE via ledger — nunca `UPDATE` direto.
- Rodar **apenas** em dev/staging, nunca produção.

---

## CONTAS DE TESTE (`TEST_ACCOUNTS.md` — formato)

| Nome | Username | E-mail | Papel | Mundo | Nível | Senha |
|------|----------|--------|-------|-------|-------|-------|
| Performer Teste | performer_teste | performer@teste.limen.local | performer | mulheres | estrela | (documentada) |
| Membro Teste | membro_teste | membro@teste.limen.local | consumer | mulheres | — | (documentada) |

> Seed atual (`DatabaseSeeder`) já cria `performer@limen.test` e `consumer@limen.test` +
> pacotes de tokens. A massa de QA deve **acrescentar** contas `@teste.limen.local` sem tocar
> nas de seed. `database/factories/` só tem `UserFactory` — a operação de QA precisa criar
> factories/seeder de performers/membros ou gerar via `synthetic-data-generator`.

---

## TESTES E2E / SEGURANÇA / CARGA NECESSÁRIOS

**E2E (fluxos completos, via túnel 8443):**
- Cadastro → verificação de e-mail → login → catálogo.
- Compra de tokens → PIX → webhook → saldo.
- Gorjeta → split → ledger.
- Performer: onboarding → KYC → aparece no catálogo → payout.

**Segurança (o security-validator / `security-reviewer`):**
- **IDOR:** acessar payment/perfil/payout de outro user (testes de wallet já cobrem parte).
- **Mass assignment:** registrar como `admin`; setar `user_id`/`split_pct` via request.
- **Saldo negativo / débito acima do saldo:** deve lançar `InsufficientBalanceException`.
- **Bypass de pagamento/gorjeta/conteúdo pago:** creditar sem webhook; gorjeta a si mesmo.
- **Webhook forjado:** POST sem assinatura/secret válido deve ser rejeitado.

**Carga (k6):** 100 / 500 / 1000 VUs. **Ressalva:** a VPS de dev (Hetzner CX) satura cedo;
tratar resultados como indicativos, não como capacidade de produção.

---

## GO-LIVE QA CHECKLIST

- [ ] 173 testes Pest verdes na `main` (CI).
- [ ] E2E dos 4 fluxos completos aprovados.
- [ ] Bateria de segurança (IDOR, mass assignment, saldo negativo, webhook forjado) sem falha.
- [ ] Massa de teste gerada só com Fake + ledger; `TEST_ACCOUNTS.md` documentado.
- [ ] Validação manual tela a tela dos FIXes da Fase 12 concluída.
- [ ] Nenhum bug reportado em módulo NÃO IMPLEMENTADO (revisar contra a Matriz de Capacidade).
- [ ] Carga executada com ressalva de ambiente registrada.
- [ ] Dados sintéticos removidos antes de produção real.
