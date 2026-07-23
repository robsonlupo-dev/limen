# LIMEN — MASTER HANDOFF FINAL

> **Documento de transferência oficial — encerramento de chat.**
> O próximo chat **não terá acesso a nenhuma mensagem anterior**. Este arquivo é
> autossuficiente para continuidade imediata do projeto. Leia-o inteiro antes de
> pegar qualquer tarefa; ele complementa (não substitui) o `CLAUDE.md`, que
> continua sendo o cérebro operacional do projeto.
>
> **Gerado em:** 22/07/2026 · **Branch de origem:** `feat/sprint6-final`
> **Método:** escrito a partir da **inspeção do código real** — `git log`,
> `route:list`, `composer.json`, migrations, services, controllers, configs e a
> suíte de testes rodada de ponta a ponta. Onde um doc antigo contradiz o código,
> **o código vence** e a divergência está registrada.
>
> **Regra de ouro herdada:** este projeto documenta suas próprias limitações em
> voz alta. Vários controles (geobloqueio, painel de visitantes, age
> verification, filtro de chat, aceite de documentos) são **deliberadamente mais
> fracos do que parecem**, e a disciplina de linguagem — não descrever como mais
> forte do que é — faz parte da entrega. Mantenha isso.

---

## ÍNDICE

1. [Snapshot do estado atual](#1-snapshot-do-estado-atual)
2. [Stack e versões](#2-stack-e-versões)
3. [Como rodar (ambiente, testes, comandos)](#3-como-rodar-ambiente-testes-comandos)
4. [Princípios de arquitetura não-negociáveis](#4-princípios-de-arquitetura-não-negociáveis)
5. [Modelo de dados — migrations e models](#5-modelo-de-dados--migrations-e-models)
6. [Economia de tokens — ledger append-only](#6-economia-de-tokens--ledger-append-only)
7. [Pagamentos — Asaas / PIX](#7-pagamentos--asaas--pix)
8. [KYC — verificação de identidade da performer](#8-kyc--verificação-de-identidade-da-performer)
9. [Age Verification — membro (ECA Digital)](#9-age-verification--membro-eca-digital)
10. [Autenticação — as duas portas](#10-autenticação--as-duas-portas)
11. [2FA da performer — TOTP](#11-2fa-da-performer--totp)
12. [Autorização — roles, policies, middleware](#12-autorização--roles-policies-middleware)
13. [Privacidade do membro — piso, modo discreto, FanAlias](#13-privacidade-do-membro--piso-modo-discreto-fanalias)
14. [Painel de visitantes — profile_visits](#14-painel-de-visitantes--profile_visits)
15. [Privacy perks — Ghost Mode, Read Receipts, Panic Button](#15-privacy-perks--ghost-mode-read-receipts-panic-button)
16. [Interesse Controlado](#16-interesse-controlado)
17. [Chat — interest-gated e filtro de conteúdo](#17-chat--interest-gated-e-filtro-de-conteúdo)
18. [Gorjetas (Tips)](#18-gorjetas-tips)
19. [Assinaturas e Círculos (tiers)](#19-assinaturas-e-círculos-tiers)
20. [Waitlist e Founding Members](#20-waitlist-e-founding-members)
21. [Payout — saque da performer](#21-payout--saque-da-performer)
22. [Geobloqueio — FOSTA-SESTA](#22-geobloqueio--fosta-sesta)
23. [Aceite de documentos da performer](#23-aceite-de-documentos-da-performer)
24. [LGPD — Hard Delete e sistema de Report](#24-lgpd--hard-delete-e-sistema-de-report)
25. [Rotas, CI/CD, deploy e ambiente](#25-rotas-cicd-deploy-e-ambiente)
- [Apêndice A — Backlog e próximos passos](#apêndice-a--backlog-e-próximos-passos)
- [Apêndice B — Limitações conhecidas (não redescobrir)](#apêndice-b--limitações-conhecidas-não-redescobrir)
- [Apêndice C — Glossário](#apêndice-c--glossário)
- [Apêndice D — Inventário de arquivos por domínio](#apêndice-d--inventário-de-arquivos-por-domínio)

---

## 1. Snapshot do estado atual

| Métrica | Valor | Fonte |
|---|---|---|
| Suíte de testes | **859 testes verdes, 4547 asserts** | `php artisan test` (~145 s) |
| Migrations | **62** | `php artisan migrate:status` (linhas *Ran*) |
| Rotas registradas | **134** | `php artisan route:list \| wc -l` |
| `Route::` em `routes/web.php` | 90 | `grep` |
| Rotas HTTP em `routes/api.php` | 39 | `grep` |
| Services | 24 (+ subpastas `Asaas`, `Kyc`, `Waitlist`) | `ls app/Services/` |
| Models | 27 | `ls app/Models/` |
| Controllers Web | 37 | `find app/Http/Controllers/Web` |
| Controllers API | 21 | `find app/Http/Controllers/Api` |
| Middleware | 8 | `ls app/Http/Middleware/` |
| Commands (agendáveis) | 9 | `ls app/Console/Commands/` |
| Jobs | 2 | `ls app/Jobs/` |
| Policies | 4 | `ls app/Policies/` |
| Configs | 24 | `ls config/` |
| Tag Git | `v1.0-sprint7` (em `80ba300`, fecho do Sprint 7; restam `v1.0-sprint6` em `5070638` e `archive/qa-pre-prod-operation`) | `git tag` |

**Branch atual:** `main` (em `80ba300`, com os PRs #80/#81/#82 do Sprint 7 mergeados). Os últimos commits fecham o Sprint 7.
Últimos commits relevantes (mais recente primeiro):

```
6007c1c docs: clarify test count reflects current branch state in CLAUDE.md
2cd5932 docs: update test count to 819 in CLAUDE.md
e043077 style: apply Pint across repo (cosmetic only, no logic changed)
d9594ab refactor: chat filter — allow contact sharing, focus on legal risk + conduct
85eb33c feat: geo-block US (FOSTA-SESTA) + chat word filter (Sprint 6 final)
3ebda19 Merge pull request #76 from robsonlupo-dev/feat/2fa-performers
7fa7502 fix: close 2FA bypass on the Sanctum port + TOTP replay (security review)
a046d2b feat: TOTP 2FA for performers (Sprint 6)
acf1df2 Merge pull request #75 from robsonlupo-dev/feat/privacy-tiers
9160df8 feat: k-anonymity per slot in visitor panel (k=3) + update CLAUDE.md
7dc29da Merge pull request #74 from robsonlupo-dev/feat/hard-delete
91d4735 feat: LGPD hard delete with 30-day grace period (Sprint 6)
28285ea Merge pull request #73 from robsonlupo-dev/feat/report-system
07e6efa Merge pull request #72 from robsonlupo-dev/age-verification
080cb1e Merge pull request #71 from robsonlupo-dev/age-verification
0372e1e feat: panic button with session logout (Sprint 6)
acb788f Merge pull request #70 from robsonlupo-dev/age-verification
552b82c fix: close document acceptance gate bypasses (chat + API + version ordering)
34ac192 feat: document acceptance infrastructure (content policy + performance contract)
bce263f feat: derive member pseudonyms per performer via FanAlias
7374b40 feat: age verification via CPF+DOB for member registration (ECA Digital)
```

**O que o Sprint 6 entregou (todos confirmados no `git log`):**

| Entrega | Commit/PR | Seção deste doc |
|---|---|---|
| Age Verification (CPF+DOB, ECA Digital) | `7374b40` / PR #70 | §9 |
| FanAlias (pseudônimo por par) | `bce263f` | §13 |
| Document acceptance (política + contrato) | `34ac192`, `552b82c` | §23 |
| Panic Button | `0372e1e` / PR #71 | §15 |
| Shared-IP flag (anti-exploração) | `81e2369` | §8, §12 |
| Report system (compliance) | `401c650` / PR #73 | §24 |
| LGPD Hard Delete (grace 30d) | `91d4735` / PR #74 | §24 |
| Ghost Mode / Read Receipts / profile visits | `01d133f`, `26e3d30` / PR #75 | §14, §15 |
| k-anonimato por faixa (k=3) + timestamp coarsening | `76cf794`, `9160df8` | §14 |
| 2FA TOTP da performer | `a046d2b`, `7fa7502` / PR #76 | §11 |
| Geobloqueio FOSTA-SESTA | `85eb33c` | §22 |
| Filtro de conteúdo do chat | `85eb33c`, `d9594ab` | §17 |

> **Estado de módulo de conteúdo:** **NÃO EXISTE** pipeline de publicação de
> conteúdo. Não há model de post, feed, vídeo ou mídia paga — só `avatar_path` e
> `cover_path` no perfil da performer. Vários controles de "conteúdo" ainda não
> existem porque **a superfície que eles protegeriam ainda não foi construída**.
> Isso é uma janela: moderação e verificação de conteúdo devem ser construídas
> **antes** do primeiro upload, não depois. Ver `docs/LEGAL_GAP_ANALYSIS.md`.

---

## Sprint 7 — O que foi entregue

Fechado na tag `v1.0-sprint7` (`80ba300`). Todos os PRs seguiram a Regra de
Ouro do Git Flow (branch + PR para `main`, sem commit direto). Suíte passou de
819 → **859 testes** verdes.

| Entrega | Commit/PR | Seção deste doc |
|---|---|---|
| Migration de **tier** (`verificada`/`select`/`maison`) em `performer_profiles` | PR #77 (Sprint 6→7) | §12, §19 |
| Endpoint admin de **grant de tier** — `forceFill` + `Audit::log` + `DB::transaction`, campos `tier*` fora do `$fillable` | `admin.performers.tier.store` | §12.4 |
| **KYC no onboarding web** — `KycSubmissionService` como fonte única (mesma da API e do webhook Didit), `lockForUpdate` + `DuplicateKycSubmissionException` na race do submit | Sprint 7 | §8 |
| **Onboarding UX** — wizard de 5 passos, `KycGate`, `KycPendingBanner` no dashboard, empty states distintos (piso vs. lista vazia real) | PR #80 | §8 |
| **Painel admin de KYC** (`/admin/kyc`) — fila com filtro allowlist, aprovar/rejeitar sob `lockForUpdate` + guard de status, delegando ao `KycService`; PII do documento nunca chega à view | PR #81 | §8 |
| **Múltiplos mundos por performer** — coluna `worlds` (json), `activeWorlds()` (fallback para `category`), `scopeInWorld()` (`whereJsonContains` + fallback), step de mundo virou checkbox múltiplo; `category` derivada no servidor de `worlds[0]` | PR #82 | §5, §25 |
| **Fix flaky** `AnonimityFloorTest` — assert do "nunca expõe o número exato" passou a mirar as **props do Inertia**, não o HTML inteiro (hash de versão do Vite / CSRF / slug aleatório podiam conter o número por sorteio) | PR #80 (`a70a56f`) | §13 |
| **Git Flow obrigatório** (branch + PR a partir do Sprint 7) documentado no `CLAUDE.md` — exceção só para doc puro | `dd5cb03` | — |

**Segurança:** os itens sensíveis (grant de tier, painel de KYC, cadastro
multi-mundos) passaram pelo subagente de revisão antes do merge. Achado aplicado:
os e-mails de aprovação/rejeição de KYC passaram a `->afterCommit()` (o dispatch
dentro da transação aninhada podia vazar e-mail num rollback).

---

## 2. Stack e versões

| Camada | Tecnologia | Versão / restrição |
|---|---|---|
| Linguagem | PHP | 8.4.22 (composer exige `^8.3`; CI roda 8.5) |
| Framework | Laravel | `^13.8` |
| Banco principal | MySQL | 8.4 (via Docker em dev; service no CI) |
| Cache / filas | Redis | via Docker (`REDIS_CLIENT=phpredis`) |
| Front-end | Inertia + Vue 3 + Tailwind v4 | + Ziggy para rotas no JS |
| Auth API | Laravel Sanctum | `^4.3` |
| Realtime | Laravel Reverb | `^1.10` — **servidor não roda; driver `log`** |
| E-mail | Resend | `resend/resend-laravel: ^1.4` |
| 2FA TOTP | pragmarx/google2fa | `^9.0` |
| QR code | bacon/bacon-qr-code | `^3.1` (SVG inline, local) |
| Testes | Pest | `^4.7` (+ plugin-laravel `^4.1`) |
| Lint PHP | Laravel Pint | `^1.27` — **não há step de lint no CI** |
| Pagamento | Asaas / PIX | driver `fake` em dev/staging |

**Dependências JS (package.json):** `@inertiajs/vue3`, `vue ^3.5`, `ziggy-js`,
`laravel-echo`, `pusher-js` (para o Reverb quando subir), Tailwind v4 via
`@tailwindcss/vite`, Vite `^8`.

**Blade** sobrou apenas no layout raiz. **Mudar de stack exige aprovação do PO.**

**Streaming de vídeo (LiveKit):** planejado, **nada implementado**. Não há
dependência no projeto — não presuma que existe.

### Convenções de código (não-negociáveis)

- Migrations versionadas para TODA mudança de schema. Nunca alterar o banco à mão.
- Validação sempre via **Form Requests** — nunca confiar no input cru.
- Queries via Eloquent/Query Builder com bind. **Nunca** concatenar string em SQL.
- Dinheiro/tokens como **inteiros** (centavos / tokens), nunca float.
- Commits pequenos, em inglês, no imperativo ("add token ledger migration").
- 1 PR por entrega. Testes verdes antes de marcar como pronto.
- **Nada de segredo no Git.** Tudo em `.env`, fora do versionamento.
- Dados reais só em produção. Dev/staging usam dados sintéticos.

---

## 3. Como rodar (ambiente, testes, comandos)

### 3.1 Testes — a pegadinha do SQLite

O `phpunit.xml` aponta para SQLite, mas **o ambiente de dev não tem `pdo_sqlite`**
e o projeto usa MySQL. **Não edite o `phpunit.xml`** — prefixe as variáveis
`DB_*` no comando (é o que o CI faz):

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
DB_DATABASE=limen_test DB_USERNAME=limen DB_PASSWORD=limen_dev_pw \
php artisan test
```

> ⚠️ **Migration quebrada NÃO dá erro — parece hang.** Se uma migration falha, o
> Pest re-roda `migrate:fresh` a cada teste e o processo *parece travar*. Para
> ver a exceção real, rode `php artisan migrate:fresh` sozinho.

> A suíte tem 819 testes e leva **~2min30s**. Em foreground isso estoura o
> timeout de 120s de uma chamada de shell; rode em background e aguarde a
> notificação de conclusão.

### 3.2 Lint (Pint)

Não há CI de lint. Rode manualmente:

```bash
./vendor/bin/pint --test    # só reporta
./vendor/bin/pint           # auto-corrige
```

Em 22/07/2026 a árvore inteira foi normalizada (commit `e043077`) e está `passed`.
Os fixers usados são **cosméticos e preservam comportamento** (`concat_space`,
`binary_operator_spaces`, `ordered_imports`, `no_unused_imports`, etc.).

### 3.3 Setup local (composer.json `scripts`)

```bash
composer setup   # install + .env + key:generate + migrate + npm install + build
composer dev     # serve + queue:listen + pail (logs) + vite, concorrentes
```

### 3.4 Comandos agendáveis (`app/Console/Commands/`)

| Command | Função | Cadência esperada |
|---|---|---|
| `ExpireSubscriptions` | expira assinaturas por `next_due_date` | diária |
| `ProcessScheduledDeletions` | executa hard delete após grace de 30d | diária |
| `PurgeExpiredChatAccess` | encerra janelas de acesso ao chat vencidas | diária |
| `PurgeExpiredProfileVisits` | retenção de 7 dias das `profile_visits` (`visits:purge`) | diária |
| `ReconcilePayments` | reconciliação de cobranças Asaas | agendada |
| `ReconcilePayouts` | reconciliação de payouts (porta `needs_review`) | agendada |
| `ReconcileWallets` | verifica soma do ledger vs `token_wallets` | agendada |
| `SendWaitlistNurture` | drip de nurturing (7 e-mails) | agendada, teto por run |
| `BackfillPerformerAvatars` | `performers:backfill-avatars` (Sprint 1) | one-off |

> **Reverb não roda.** Broadcasting está em driver `log` em dev/staging. O chat
> em tempo real está montado, mas as mensagens não são empurradas — ver §17.

---

## 4. Princípios de arquitetura não-negociáveis

Estes cinco são **fundação, não feature**. Violá-los é regressão, não trade-off.

1. **Segurança e idade primeiro.** PII sensível, KYC, 18+ dos dois lados,
   prevenção de conteúdo ilegal. É a base de tudo.

2. **Saldo de tokens é derivado de um ledger append-only.** NUNCA fazer
   `UPDATE ... saldo = saldo + x`. Todo movimento é uma **linha nova** em
   `token_ledger`; o saldo é a soma. (Erro recorrente no projeto anterior — não
   repetir.) Ver §6.

3. **Idempotência em pagamento.** Crédito de tokens só via webhook idempotente
   por id de evento. Reprocessar **nunca** duplica saldo. Ver §7.

4. **PII isolada e criptografada.** CPF, documentos e dados de verificação ficam
   em tabela separada, criptografados em repouso, em storage privado. Nunca em
   log, nunca em URL. Ver §8, §9.

5. **Nada de segredo no Git.** Tudo em `.env`. **Dados reais só em produção.**

### Regra transversal — a fonte única (single source of truth)

Vários controles têm **uma dona única** de propósito, porque duplicar a regra
cria um oráculo:

| Regra | Fonte única |
|---|---|
| Visibilidade de seguidores / piso de anonimato | `app/Services/FollowerVisibilityService.php` |
| Elegibilidade do piso (7 dias + e-mail verificado) | `FollowerVisibilityService::applyFloorEligibility()` |
| Piso de visitantes | `ProfileVisitService` |
| Pseudônimo do membro | `app/Support/FanAlias.php` |
| Ranking de tiers | `Circle::TIER_ORDER` |
| Máscara de opt-out de interesse | `ChatService::performerMessageFromInterest` (filtro roda ANTES) |
| Fingerprint de IP/UA | `app/Support/ClientFingerprint.php` |
| Hash de CPF | `app/Support/CpfHash.php` |

Se a tela e o serviço discordarem, **o par de respostas HTTP vira oráculo** para
reconstruir o que a tela esconde. Sempre consulte a fonte única.

---

## 5. Modelo de dados — migrations e models

### 5.1 Models (27) e seus domínios

| Model | Domínio | Notas |
|---|---|---|
| `User` | conta base | `role` (consumer/performer/admin); colunas sensíveis fora do `$fillable` |
| `PerformerProfile` | perfil público | `stage_name` unique, `avatar_path`, `cover_path`, `slug` |
| `IdentityVerification` | KYC | `document_number`, `full_legal_name`, `date_of_birth` cast `encrypted` |
| `AgeVerification` | idade do membro | `method`, `cpf_hmac`, `verified_at`; user_id unique |
| `TokenWallet` | carteira | saldo materializado (verificado contra o ledger) |
| `TokenLedger` | ledger append-only | `entry_type`, `amount`, `balance_after`, `reference_*` |
| `TokenPackage` | pacotes de compra | com `bonus` |
| `Payment` | cobrança | Asaas / PIX |
| `PaymentEvent` | evento de webhook | idempotência por id de evento |
| `Payout` | saque da performer | estado `needs_review` (porta de saída) |
| `Tip` | gorjeta | split por nível da performer |
| `Follow` | seguir | `discrete_mode` por par |
| `PerformerInterest` | Interesse Controlado | status inclui `suppressed` |
| `Conversation` / `Message` | chat | interest-gated; soft-delete LGPD |
| `ChatAccess` | janela de acesso paga | 50 tokens / 30 dias + 15 grace |
| `Circle` | tier de assinatura | `TIER_ORDER` |
| `Subscription` / `SubscriptionCharge` | assinatura recorrente | `trial_ends_at`, `next_due_date` |
| `ProfileVisit` | visita ao perfil | painel de visitantes; retenção 7d |
| `Report` | denúncia | compliance; `reporter_id` + alvo morfável |
| `DeletionLog` | hard delete | trilha do LGPD |
| `DocumentAcceptance` | aceite jurídico | append-only (model recusa `update`) |
| `AuditLog` | trilha de auditoria | HMAC de rule/fingerprint, nunca corpo |
| `WaitlistEntry` / `WaitlistReferral` / `WaitlistEmailLog` | waitlist | double opt-in, drip, Founding Members |
| `PaymentEvent` | webhook Asaas | ver §7 |

### 5.2 Migrations (62) — linha do tempo

As três primeiras (`0001_01_01_*`) são o esqueleto do Laravel (users, cache,
jobs). A partir de `2026_06_24` começa o Limen. Marcos:

- **Fundação (jun/26):** `extend_users_table`, `performer_profiles`,
  `identity_verifications`, `token_wallets`, `token_ledger`, `token_packages`,
  `payments`, `payment_events`, `audit_logs`, `personal_access_tokens`.
- **Perfis/catálogo/follows (jun–jul):** `slug`, `follows`.
- **Gorjetas (jul):** `tips`, `tip_credit` no ledger.
- **Payout (jul):** `payouts`, `payout_id` em payment_events, `payout_reversal`
  no ledger, `needs_review` em payouts.
- **Waitlist (jul):** `waitlist_entries`, `founding_members`, `waitlist_referrals`,
  `waitlist_two_step_member_performer`, `waitlist_email_log`.
- **Interesse (jul):** `performer_interests`, `spend_interest_unlock` no ledger,
  `interests_opt_out` em users, `suppressed` no status de interesse.
- **Círculos/assinaturas (jul):** `retire_gls_swing_worlds`, `circles`,
  `subscriptions`, `subscription_charges`, `subscription_grant` no ledger,
  `trial_ends_at`.
- **Chat (jul):** `conversations`, `messages`, `chat_entry_types` no ledger,
  `chat_access`.
- **Sprint 6 (jul):** `discrete_mode` em follows e users, `age_verifications`,
  `document_acceptances`, `registration_ip_hash` em users, `reports`,
  `deletion_columns` em users, `deletion_logs`, `profile_visits`,
  `privacy_perk_columns` em users, `two_factor_columns` em users.
- **Sprint 7 (jul):** `add_tier_to_performer_profiles`,
  `add_worlds_to_performer_profiles` (json, multi-mundos).

> **`stage_name` é unique** (`2026_07_15_000001`) — foi bug de branch parada que
> regrediu isso antes; não remover o índice.

---

## 6. Economia de tokens — ledger append-only

### 6.1 O invariante central

**NUNCA** `UPDATE token_wallets SET balance = balance + x`. Todo movimento é uma
linha nova em `token_ledger`. O saldo é a **soma** das linhas. `token_wallets`
existe como materialização/cache do saldo, **verificada** contra o ledger pelo
command `ReconcileWallets`.

Cada linha do ledger tem: `entry_type`, `amount` (inteiro, com sinal),
`balance_after` (saldo após a linha), `reference_id`, `reference_type`,
`description`, `wallet_id`.

### 6.2 `entry_type` — tipos existentes (extraídos das migrations)

| entry_type | Sinal | Origem |
|---|---|---|
| `purchase` | + | compra de pacote via PIX |
| `bonus` | + | bônus de pacote |
| `tip_credit` | + | crédito da gorjeta ao performer (após split) |
| `spend_tip` | − | membro gasta em gorjeta |
| `spend_private` | − | sessão privada (reservado) |
| `spend_camera` | − | câmera (reservado) |
| `spend_interest_unlock` | − | membro paga 15 tokens para desbloquear interesse |
| `spend_chat_access` | − | membro paga a janela de acesso ao chat |
| `chat_access_credit` | + | crédito ao performer pela janela de chat |
| `subscription_grant` | + | franquia de tokens do tier assinado |
| `payout_reserve` | − | reserva de saque |
| `payout_reversal` | + | estorno de payout que falhou |
| `refund` | + | estorno |
| `adjustment` | ± | ajuste manual |
| `staging_seed_backfill` | + | seed sintético (só staging) |

> **Adicionar um novo `entry_type` exige migration** (é enum na coluna). Ver os
> exemplos `*_add_*_to_token_ledger_entry_type.php`.

### 6.3 Skill e regras

Ao mexer em crédito/débito/consulta de tokens ou integrar pagamento/gorjeta/payout,
**invoque a skill `token-ledger-rules`**. O débito é atômico e deve ser feito sob
transação/lock — não confie em leitura-e-escrita separadas (race → saldo negativo).

---

## 7. Pagamentos — Asaas / PIX

### 7.1 Fluxo

1. Cliente escolhe um `TokenPackage`.
2. Cria-se uma cobrança PIX no Asaas (`PaymentService`, `app/Services/Asaas/`).
3. O Asaas envia webhook `PAYMENT_RECEIVED`.
4. O webhook credita tokens **idempotentemente por id de evento** — reprocessar
   nunca duplica saldo (`PaymentEvent` guarda o id).

### 7.2 Clientes

- `app/Services/Asaas/AsaasHttpClient.php` — cliente real.
- `app/Services/Asaas/FakeAsaasClient.php` — mock; **é o driver de dev/staging**
  (`ASAAS_DRIVER=fake`).

### 7.3 Segurança do webhook

- `VerifyAsaasWebhookIp` (middleware `asaas.webhook_ip`) valida a origem por IP.
- Webhook de **transfer** (payout) tem controller separado:
  `AsaasTransferWebhookController`.

### 7.4 Pegadinhas registradas

- **`ASAAS_API_KEY` começa com `$`** — precisa de **aspas simples** no `.env`,
  senão o shell interpreta como variável e a chave vira vazia → 401.
- Skill relevante: **`asaas-pix-integration`** (invoque ao criar cobranças,
  tratar webhooks ou conciliar pagamentos).
- Config: `config/asaas.php`. Vars: `ASAAS_DRIVER`, `ASAAS_ENV`,
  `ASAAS_BASE_URL`, `ASAAS_API_KEY`, `ASAAS_WEBHOOK_TOKEN`.

### 7.5 PCI

Endurecimento PCI SAQ-D documentado em `docs/PCI_SAQ_D.md` (Sprint 5). Campos de
cartão (`card_number`, `card_cvv`, `card_holder`, `cpf`, `cpfCnpj`) estão no
`dontFlash` de exceções (`bootstrap/app.php`) — nunca voltam à sessão/log num
erro de validação.

---

## 8. KYC — verificação de identidade da performer

### 8.1 O que está implementado

- Provedor: **Didit** (real, Sprint 5). Driver `fake` (`FakeKycClient`) em dev.
- Request: `SubmitKycRequest` exige `document_type in:cpf,rg,cnh`,
  `document_front` (obrigatório), `document_back` (opcional), `selfie`
  (obrigatória), jpeg/png até 10 MB.
- `identity_verifications` guarda `document_number`, `full_legal_name`,
  `date_of_birth` com cast **`encrypted`** (APP_KEY).
- Arquivos vão para o disco privado **`kyc`**, cifrados com `Crypt` e sufixo
  `.enc` (`app/Services/Kyc/KycDocumentStore.php`). **Nunca em log, nunca em URL.**
- Aprovação/rejeição gravam `reviewed_by`, `reviewed_at` e linha de `audit_log`.
- Autenticação Didit: **`x-api-key`** (não Bearer). Webhook v3 com assinatura
  **`X-Signature-V2`**.
- E-mails de resultado: jobs `SendKycApprovedEmail`, `SendKycRejectedEmail`.

### 8.2 Config e vars

`config/kyc.php`. Vars: `KYC_PROVIDER` (`fake`|`didit`), `KYC_API_KEY`,
`KYC_WORKFLOW_ID`, `KYC_WEBHOOK_SECRET`, `KYC_BASE_URL`
(`https://verification.didit.me`).

### 8.3 Limitações conhecidas (registrar em auditoria)

- **Liveness / face match são 🟡 PARCIAIS**: a selfie é coletada e a decisão vem
  da Didit, mas **quais checagens rodam depende do workflow configurado no
  provedor**, não do código.
- **Vínculo entre conteúdo publicado e pessoa verificada: 🔴 FALTA** — porque o
  módulo de conteúdo não existe.
- **Revalidação periódica: 🔴 FALTA.**
- **Rotacionar `APP_KEY` quebra a decodificação** de tudo que está cifrado com
  ela (docs KYC, casts encrypted). Retenção/expurgo de documentos KYC é
  follow-up.

### 8.4 Shared-IP flag (Sprint 6)

`SharedRegistrationIpService` + `registration_ip_hash` em users: sinaliza contas
de performer que se cadastraram do **mesmo IP** (detecção de rede de exploração).
O IP é gravado como **HMAC**, não cru. É sinal para revisão humana, não bloqueio
automático.

---

## 9. Age Verification — membro (ECA Digital)

> **Status: 🟠 PARCIAL.** Suficiente para documentar esforço, **insuficiente para
> auditoria robusta**. É registro de escopo — não descreva como mais forte do que é.

### 9.1 O que existe

- **Age gate de navegação** — `AgeGateModal.vue` grava cookie `limen_age_confirmed`
  por 365 dias. É **controle de UI/UX, não verificação** (o próprio arquivo diz
  isso em comentário — mantenha essa redação). Cookie **não** é criptografado
  (está no `encryptCookies(except:)`), para o servidor conseguir lê-lo.
- **CPF + data de nascimento no cadastro de membro** —
  `RegisterWebRequest`. CPF é `required_if:role,consumer` (performer não informa;
  entrega no KYC). Dígitos verificadores validados em `app/Rules/CpfValido.php`.
  Data com `before_or_equal` de 18 anos — o corte é **hoje**, não o ano (véspera
  do aniversário é rejeitada corretamente).
- **CPF nunca persistido em texto puro** — só HMAC-SHA256 com a `APP_KEY`
  (`app/Support/CpfHash.php`) em `age_verifications.cpf_hmac`.
- `method = 'cpf_dob'` distingue este nível de verificações futuras.
- `cpf_hmac` **indexado, não unique** — detecta conta duplicada, não bloqueia.

### 9.2 O que NÃO existe

- Consulta a base oficial (Serpro/DataValid) — **previsto Sprint 7**.
- Prova de que o CPF pertence a quem se cadastrou.
- KYC documental para membro (só performer tem).

### 9.3 Redação defensável para auditoria

> "CPF estruturalmente validado + data de nascimento autodeclarada; consulta a
> base oficial prevista para o Sprint 7 (`method = 'cpf_dob'`)."

**NÃO** descrever como "verificação de CPF" seco. O algoritmo do CPF é público;
gerar CPF válido é resultado de primeira busca. O registro prova que **um CPF
estruturalmente válido foi digitado**, não que a pessoa tem 18 anos.

### 9.4 Decisão de design a preservar

`users.age_verified_at` **NÃO** é marcado no cadastro de membro. Aquela coluna é
escrita só pelo `KycService`, quando um documento passou por provedor. O sinal do
membro mora em `age_verifications.method`. Misturar os dois faria qualquer
`whereNotNull` tratar declaração como documento conferido.

---

## 10. Autenticação — as duas portas

> **Duas portas de auth, não confundir.** É a distinção que mais gera bug no
> projeto.

| Porta | Quem usa | Mecanismo |
|---|---|---|
| **API** `/api/v1/*` | integrações, mobile futuro | **Sanctum** (token) |
| **Web** (resto) | frontend Vue | **sessão + CSRF** |

**Consequência prática:** fora de `api/*`, uma exceção **não** vira JSON
automaticamente (`shouldRenderJsonWhen(fn => is('api/*'))` em `bootstrap/app.php`).
Erro que o front precisa consumir exige **`response()->json()` explícito**.

### 10.1 Endpoints de auth API (`Api/V1/Auth/`)

- `RegisterController`, `LoginController`, `LogoutController`, `MeController`
- `PasswordController` (reset), `EmailVerificationController`
- `TwoFactorChallengeController` (ver §11)

### 10.2 Endpoints de auth Web (`Web/Auth/`)

- `RegisterController`, `LoginController`, `EmailVerificationController`
- `ForgotPasswordController`, `ResetPasswordController`

### 10.3 Throttle

`POST /cadastro` tem `throttle:5,1` (foi a última rota de auth sem throttle;
corrigido no PR #69). Login, reset e cadastro da API já tinham.

---

## 11. 2FA da performer — TOTP

A conta da performer guarda o KYC (documento + selfie) e é a identidade
verificada sob a qual o conteúdo é publicado. Um take-over vaza PII sensível **e**
deixa terceiro publicar como ela. Senha não basta.

### 11.1 Implementação

- **Fortify NÃO está instalado** (não é dependência do core). O TOTP é
  `pragmarx/google2fa` direto.
- O QR é desenhado **localmente** em SVG inline (`bacon/bacon-qr-code`) —
  **nunca** por serviço externo de QR, porque a `otpauth://` carrega o segredo em
  claro.
- Regra em `app/Services/TwoFactorService.php`. Controller:
  `Web/Performer/TwoFactorController.php` (web) e `Api/V1/Auth/TwoFactorChallengeController.php` (API).

### 11.2 Regras (todas cobertas por teste)

- **`two_factor_confirmed_at` é o que LIGA o 2FA**, não a presença do secret.
  Entre `enable()` e `confirm()` a performer ainda não provou o autenticador —
  gatear nesse intervalo trancaria a conta com um QR nunca escaneado.
- Secret e recovery codes: cast `encrypted` / `encrypted:array` (APP_KEY),
  `$hidden`, **fora do `$fillable`**. Rotacionar APP_KEY derruba os dois → a
  performer cai no re-cadastro do autenticador.
- **Recovery code é de uso único, sob `lockForUpdate`** (dois POSTs simultâneos
  autenticariam duas sessões sem o lock).
- **TOTP também é de uso único** (`two_factor_last_used_ts`, `verifyKeyNewer`) —
  sem isso o código capturado no desafio serviria em seguida para `/2fa/disable`.
- **`confirm()` NÃO aceita recovery code** (o passo existe para provar que o app
  funciona). `disable()` e a reemissão de códigos **aceitam** e **exigem** um
  fator: quem só tem a sessão não remove o segundo fator.

### 11.3 O gate vale nas DUAS portas — e a prova é diferente

Middleware `2fa` (`TwoFactorChallenge`). Ignora quem não é performer com 2FA
confirmado (pode ir em grupo compartilhado, como `documents.accepted`).

- **Web (sessão):** marca na sessão o **id do usuário** (não `true` — não
  herdável por sessão que trocou de dono). Aplicado no grupo `auth` **INTEIRO**,
  não só em `performer.*`: a sessão da performer alcança chat e catálogo, e
  gatear só o dashboard deixaria a conta sequestrada conversando com membros.
- **API (Sanctum):** o fator vem **antes do token**. `POST /api/v1/auth/login` de
  quem tem 2FA devolve `two_factor_required` + token com a habilidade
  `2fa:challenge` **e nada mais** (10 min); `POST /api/v1/auth/2fa/challenge`
  troca por código e devolve o token real. O middleware testa a habilidade com
  **`in_array`, NÃO `$token->can()`** — o `can()` do Sanctum responde `true` para
  qualquer coisa num token `*`, o que barrava justamente quem passou pelo desafio.
- `/broadcasting/auth` entra pelo `withBroadcasting` com `['web','auth','2fa']`
  (ver `bootstrap/app.php`) — no padrão sairia só com `web` e a sessão mandada ao
  desafio ainda assinaria `conversation.{id}`.
- **Fora do gate ficam só o desafio e o logout.**

> **Ressalva:** o login da web **completa** antes do fator (`Auth::login` e depois
> o middleware barra). É mais fraco que desafiar antes da sessão; o que fecha o
> buraco é o gate cobrir o grupo `auth` inteiro. Login em dois passos é follow-up.
>
> **Não implementado:** alerta em N falhas de desafio (hoje só grava
> `performer.2fa_challenge_failed` no audit e ninguém consome).

**Rota autenticada nova entra no gate — nas duas portas.**

---

## 12. Autorização — roles, policies, middleware

### 12.1 Roles

`User.role` ∈ {`consumer`, `performer`, `admin`}. Middleware `role`
(`EnsureUserHasRole`).

### 12.2 Policies (`app/Policies/`)

| Policy | Protege |
|---|---|
| `UserPolicy` | ações sobre a própria conta / admin |
| `PerformerProfilePolicy` | edição de perfil de performer |
| `PaymentPolicy` | acesso a cobranças |
| `ConversationPolicy` | participação em conversa (chat) |

### 12.3 Middleware (`app/Http/Middleware/`)

| Middleware | Alias | Função |
|---|---|---|
| `SecurityHeaders` | (append global) | headers de segurança, HSTS |
| `GeoBlock` | (prepend web+api) | geobloqueio FOSTA-SESTA (§22) |
| `HandleInertiaRequests` | (append web) | props compartilhadas do Inertia |
| `EnsureUserHasRole` | `role` | autorização por papel |
| `EnsureActiveCircle` | `circle` | exige assinatura ativa (tier) |
| `DocumentsAccepted` | `documents.accepted` | aceite de docs da performer (§23) |
| `TwoFactorChallenge` | `2fa` | gate de 2FA (§11) |
| `VerifyAsaasWebhookIp` | `asaas.webhook_ip` | valida IP do webhook |

### 12.4 Regra anti mass-assignment

Colunas sensíveis ficam **FORA do `$fillable`** do `User` de propósito:
`discrete_mode`, `ghost_mode`, `invisible_status`, `read_receipts_enabled`,
`two_factor_secret`, `two_factor_recovery_codes`, colunas de deleção. A troca
passa por **endpoint dedicado** que checa autorização (tier, fator, etc.).

`$hidden` do User: `password`, `remember_token`, `deletion_token_hash`,
`two_factor_secret`, `two_factor_recovery_codes`.

---

## 13. Privacidade do membro — piso, modo discreto, FanAlias

> **Regra central do produto, não detalhe de implementação. Não rediscutir sem o
> PO.** Fonte única: `app/Services/FollowerVisibilityService.php`.

### 13.1 As decisões locked

1. **Piso de Anonimato:** a performer só vê a lista a partir de **5 seguidores**.
2. **Modo Discreto** (Black/FC): o membro **conta para o piso mas nunca é
   listado**. `discrete_mode` **não** está em `$fillable`; a troca passa por
   endpoint dedicado que checa o tier.
3. **Perder o tier NÃO desativa** o Modo Discreto — quem está discreto continua
   (não reexpomos por lapso de pagamento), sempre pode **DESLIGAR**, mas não
   **religar** sem o tier.
4. **Piso vs. faixa:** o piso conta só contas com **7+ dias E e-mail verificado**
   (mitigação de sybil); a faixa exibida conta **todos** os ativos. Logo, "5+"
   com a lista escondida é estado **legítimo**, não bug. Os cortes valem para
   *destravar*, não para *filtrar*: aberta a lista, conta nova aparece nela.
5. **Contagem de seguidores é sempre exibida em FAIXA** — inclusive para a
   própria performer. Faixas: "Menos de 5", "5+", "10+", "50+", "100+", exato a
   partir de 500.

### 13.2 O ataque mitigado

A performer registrava 4 contas de consumidor, seguia a si mesma, destravava a
lista — e o próximo seguidor real ficava sendo o único nome que ela não plantou.
Os cortes de 7 dias + e-mail verificado encarecem esse setup. **Não eliminam**
(ver Apêndice B).

### 13.3 FanAlias — pseudônimo do membro (`app/Support/FanAlias.php`)

Toda exposição de membro à performer passa por aqui. Pseudônimo derivado por
**par** `(performer_profile_id, member_id)` com HMAC sobre a `APP_KEY`.

**O problema que resolveu:** `'Membro #12345'` (seguidores) e `'Fã #2345'`
(gorjetas, `consumer_id % 10000`) viviam no mesmo espaço de ids — Membro #12345
era Fã #2345. A lista de gorjetas **não passa por piso nenhum**, então bastava
mandar uma gorjeta para correlacionar.

**Duas saídas, e a distinção importa:**

| Método | Formato | Uso |
|---|---|---|
| `for()` / `label()` | 4 dígitos | **exibição**. Colide; nunca use como chave. |
| `handle()` | 16 hex | **identificação**. É o que a tela de Seguidores manda no lugar do `member_id` e o que volta no POST do Interesse. |

- Estável por par (a performer reconhece "o Fã #0042 de sempre" — é o produto).
- O `member_id` cru **não trafega mais** no POST — a lista manda `member_handle`
  e o `SendInterestRequest` resolve handle→membro varrendo os **seguidores
  listáveis** do perfil. O Piso de Anonimato continua sendo a barreira de
  autorização, não a obscuridade do handle.
- **Não mudou:** ledger, audit log e chaves internas (seguem sendo `member_id`).
  Isto é camada de **apresentação**.
- Cobertura: `tests/Unit/FanAliasTest.php`.

Registro completo: `docs/SECURITY_ISSUES.md`.

---

## 14. Painel de visitantes — profile_visits

O painel "visitantes recentes" (dashboard da performer) é a **segunda superfície**
que expõe membro à performer. O piso de seguidores sozinho não a cobre — libera a
tela, não limita quem aparece nela. Por isso há **dois** cortes:
`canRevealList()` (seguidores) **e** um piso de visitantes distintos.

Fonte da regra: `ProfileVisitService`. Critério de elegibilidade do piso:
`FollowerVisibilityService::applyFloorEligibility()` — **não copie o número nem a
regra para outro service.**

### 14.1 As decisões (numeradas como no CLAUDE.md)

6. **Piso de visitantes conta só ELEGÍVEIS:** conta com 7+ dias, e-mail
   verificado, `role=consumer`, `status=active`. Mesma mitigação de sybil do item
   4 (a performer plantava 4 aliases de véspera e o 5º saía por eliminação).
7. **Elegibilidade destrava, não filtra:** aberto o painel, a lista sai
   **completa** — visitante de conta nova aparece nela. Só o **contador** aplica
   os cortes.
8. **`limit < piso` lança `LogicException`** (`ProfileVisitService::panelFor()`),
   nunca clamp silencioso. É erro de chamador — quebra alto em teste/staging.
9. **O guard do Ghost Mode vive no Service** (`ProfileVisitService::record()`),
   não nos controllers. Dois pontos de entrada (`CatalogController`,
   `PublicCatalogController`) delegam. `record()` barra Ghost Mode, Modo Discreto
   e a própria performer. **Não existe coluna `hidden`/`ghost` em
   `profile_visits`** — visita de quem tem o perk **não é gravada**. A ausência
   de linha É o produto.
10. **O painel usa `FanAlias::label(performer_profile_id, visitor_id)`** — nunca o
    `visitor_id` cru.
11. **`profile_visits` são apagadas no Hard Delete**
    (`DeletionService::purgeProfileVisits()`, `DELETE` real na transação).
    Retenção normal: 7 dias (`visits:purge`); painel consome 24h. As visitas
    RECEBIDAS saem quando a performer encerra (`purgeVisitsToOwnProfile()`) — as
    FKs `cascadeOnDelete` **nunca disparam** (os dois lados são soft-delete). Não
    escreva código contando com o cascade.
12. **Horário só em FAIXA, nunca em relógio.** O painel devolve `visited_slot`
    (Madrugada/Manhã/Tarde/Noite, faixas de 6h). **`visited_at` não é exposto.**
    A faixa deriva de `ProfileVisitService::DISPLAY_TIMEZONE` (`America/Sao_Paulo`),
    **não** de `config('app.timezone')` (`UTC`).
13. **Ordem embaralhada DENTRO da faixa** (`revealableSlots()`) — sem isso a
    posição entregaria o que o relógio entregava. A ordem **entre** faixas fica
    (mais recente primeiro).
14. **k-anonimato por faixa: a faixa só aparece com `SLOT_MIN_K` (3) aliases.**
    Faixa incompleta **some por inteiro** — sem placeholder, contador ou "1 visita
    oculta". Copy de lista vazia é deliberadamente ambígua ("Nada a mostrar") e
    **não** afirma que não houve visita. O k é filtro DENTRO da lista, **não**
    substituto do piso.

### 14.2 Ressalvas conhecidas (Apêndice B tem o resumo)

O painel **não é anônimo contra adversário ativo**: polling numa faixa já visível
entrega o novo por diferença entre refreshes; eliminação com contas envelhecidas
é custo de setup único. **Não descreva este painel como anônimo** em copy,
política ou auditoria.

---

## 15. Privacy perks — Ghost Mode, Read Receipts, Panic Button

Colunas em `users` (migration `2026_07_21_100002_add_privacy_perk_columns`),
todas **fora do `$fillable`**, cast `boolean`: `ghost_mode`, `invisible_status`,
`read_receipts_enabled`. Serviço: `PrivacyPerkService`.

| Perk | Efeito | Notas |
|---|---|---|
| **Ghost Mode** | visita ao perfil **não é gravada** | guard em `ProfileVisitService::record()` (§14 item 9). A ausência de linha É o produto. |
| **Read Receipts** | controla confirmação de leitura no chat | **fail-closed** (`a7ff23e`): na dúvida, não vaza que leu. |
| **Invisible status** | oculta status online | flag booleana |

### 15.1 Panic Button (`0372e1e`, PR #71)

Botão de pânico que **desloga a sessão** e redireciona para uma URL neutra.
`PANIC_REDIRECT_URL` no `.env` (default `https://www.google.com.br`). Objetivo:
saída rápida da tela em situação de risco físico.

### 15.2 Cobertura no Hard Delete

As colunas de perk saem no hard delete (`a7ff23e`: "perk columns in hard delete").

---

## 16. Interesse Controlado

Ver `docs/INTEREST_SYSTEM_SPEC.md` e `docs/INTEREST_ANONYMITY_FLOOR.md`.

**Fluxo:** a **performer sinaliza** interesse num membro; o **membro paga 15
tokens** (`spend_interest_unlock`, **100% plataforma** — não credita a performer)
para desbloquear e ver quem sinalizou.

- Model: `PerformerInterest`. Service: `InterestService`.
- Status inclui **`suppressed`** (opt-out mascarado).
- **Opt-out (`interests_opt_out` em users):** quando o membro opta por não
  receber interesse, o status `suppressed` **vira o status que ele teria sem o
  opt-out, no ponto-no-tempo**. Quebrar isso vaza o opt-out para a performer.
- Rate limit e idempotência conforme a spec.
- Controllers: `Web/Consumer/InterestController`, `Web/Performer/InterestController`,
  `Web/Performer/SentInterestsController`.

> **Máscara de opt-out é invariante:** ver §17.1 — o filtro de chat roda ANTES da
> máscara, senão o par de respostas HTTP vira oráculo do opt-out.

---

## 17. Chat — interest-gated e filtro de conteúdo

### 17.1 Modelo (Sprint 4)

Chat interest-gated em tempo real (Reverb). Models `Conversation` / `Message`;
`ChatAccess` é a **janela de acesso paga** (50 tokens / 30 dias + 15 dias de
grace) — o acesso é por **janela**, não por mensagem. Soft-delete para LGPD.

- Service: `ChatService`, `ChatAccessService`. Policy: `ConversationPolicy`.
- **Reverb não roda** — driver `log` em dev/staging. O tempo real está montado,
  não empurrando mensagens (`config/broadcasting.php`).
- Command `PurgeExpiredChatAccess` encerra janelas vencidas.

**Invariante crítico:** o **filtro de conteúdo roda ANTES da máscara de opt-out**
em `ChatService::performerMessageFromInterest`. Depois dela, o suprimido daria 202
e o normal 422 — o par viraria oráculo do opt-out. **Guardado por teste.**

### 17.2 Filtro de conteúdo (`config/chat_filters.php`, `app/Support/ChatContentFilter.php`)

Duas categorias, respostas diferentes:

- **TIPO 1 `legal`** — encontro mediante pagamento e transação fora do ledger.
  **422** com mensagem que **cita os Termos de Uso**.
- **TIPO 2 `conduct`** — ameaça/sextorsão e insulto **direcionado**. **422** com
  mensagem de política de conduta + `flagged_for_review` no audit.

### 17.3 O que o filtro deliberadamente NÃO barra (decisão do PO — não "consertar")

1. **Troca de contato é PERMITIDA** (WhatsApp, telefone, Instagram, endereço). A
   versão anterior barrava isso e derrubava "comprei um fone de ouvido".
2. **Palavrão em contexto sexual consentido é PERMITIDO.** "que puta gostosa" é o
   vocabulário do produto. Só entra **insulto DIRECIONADO** (pronome +
   xingamento), e um **qualificador consensual** (`safada`, `gostosa`, `linda`)
   **desarma** o casamento: "sua puta safada" passa, "sua puta nojenta" não.
   Heurística — erra no elogio seco ("sua puta"). O caminho para o caso ambíguo é
   a **denúncia** (`Report`), que tem contexto e um humano.
3. **Encontro SEM valor monetário é PERMITIDO.** "vamos num motel" passa; "motel,
   300 reais" não. Termo ambíguo (`programa`, `motel`, `presencial`) só bloqueia
   junto de `money_signals` na MESMA mensagem.

### 17.4 Invariantes técnicos

- Normalização fecha **ZWSP e fullwidth**: `\p{Cf}` sai **antes** do `Str::ascii`
  (que virava ZWSP em espaço real) e **NFKC** colapsa fullwidth (que `Str::ascii`
  descartava, zerando a mensagem).
- `audit_logs` leva **categoria + `rule_hash` (HMAC)**, nunca a regra em claro (a
  lista está no repo; `sha256` seria revertido por tabela) e **nunca o corpo**
  (seria 2ª cópia do conteúdo do chat, fora do soft-delete do LGPD).
- Deduplicado por (usuário, regra) — `CHAT_FILTER_AUDIT_DEDUP_MINUTES` (10).
- Moderação age por **REPETIÇÃO**, não por caso isolado ("usuário X disparou
  conduta 9x").

> **Não é anti-evasão, e o "segredo" nunca foi real.** A lista está no repo; o
> remetente distingue as categorias pela resposta. A mensagem de erro é específica
> de propósito. Ausência de bloqueio **não** é prova de que nada foi combinado.

---

## 18. Gorjetas (Tips)

Entregue na fundação. `TipService`, model `Tip`.

- No gasto, a plataforma **retém um split por nível do performer**; o restante
  credita o performer (`tip_credit` no ledger).
- **Ledger append-only + idempotência.**
- **Rate limit 10/min.**
- Débito do membro: `spend_tip`. Crédito do performer: `tip_credit`.
- Controller: `Web/Consumer/TipController`, `Api/V1/TipController`.
- **A lista de gorjetas não passa por piso nenhum** — foi por isso que o FanAlias
  (§13.3) precisou existir: era o vetor de correlação mais barato.

---

## 19. Assinaturas e Círculos (tiers)

### 19.1 Ranking — a fonte única

`Circle::TIER_ORDER` (código, autoritativo):

```php
public const TIER_ORDER = ['explorador', 'insider', 'prestige', 'black', 'founders_circle'];
```

Métodos: `tierRank()` (0-based, −1 se desconhecido), `tierAtLeast($minSlug)`.

> **Fail-closed:** `tierAtLeast` usa `array_search` estrito. Um tier fora do
> `TIER_ORDER` (renomeação/reordenação) faz a comparação **falhar fechado** (nega
> o acesso), não abrir. `a97b4f7`, `3e7d003` cuidaram disso.

### 19.2 ⚠️ Divergência documentada de preços/nomes

Os docs de tiers **conflitam entre si e com o código**:

- `Circle::TIER_ORDER` usa slugs `explorador / insider / prestige / black / founders_circle`.
- `docs/SUBSCRIPTION_TIERS.md` fala em `FREE / SELECT / BLACK / PRESTIGE` com
  PRESTIGE no topo (R$ 799,90).
- `docs/CIRCLES_SYSTEM_V4.md` inverte a hierarquia (BLACK R$ 749,90 acima de
  PRESTIGE R$ 389,90).

**Ao mexer em tiers, o código (`TIER_ORDER`) vence.** Confirme os slugs reais no
seeder de `circles` antes de assumir qualquer preço de doc. Isto é uma **fonte de
bug conhecida** — não reaproveite preços de doc sem checar.

### 19.3 Mecânica

- Models: `Subscription`, `SubscriptionCharge`, `Circle`.
- `trial_ends_at` (trial de 7 dias dos Founding Members), `next_due_date`.
- `subscription_grant` no ledger credita a franquia de tokens do tier.
- Middleware `circle` (`EnsureActiveCircle`) exige assinatura ativa.
- Command `ExpireSubscriptions` expira por `next_due_date`.
- Controller: `Web/Consumer/SubscriptionController`.
- Fases entregues: A (Explorador→Prestige), B (Black/FC).

---

## 20. Waitlist e Founding Members

Fora da trilha numerada de Sprints. Ver `docs/WAITLIST_SPEC.md`.

- **Double opt-in** (confirmação por e-mail).
- **Drip de nurturing** — 7 e-mails para confirmados (`SendWaitlistNurture`).
  **Setar `WAITLIST_NURTURE_START_AT` na ativação**, senão dispara blast.
  `WAITLIST_NURTURE_MAX_PER_RUN` (200) é o teto por execução. Copy final e halt
  pós-launch são follow-ups.
- **Founding Members** — `FOUNDER_CUTOFF_AT`, trial de 7 dias.
- **Painel admin** — `Web/Admin/WaitlistAdminController`, `FounderPanelController`.
- Models: `WaitlistEntry`, `WaitlistReferral`, `WaitlistEmailLog`.
- **Ação em link de e-mail:** GET confirma, POST executa (prefetch de mailbox
  dispara GET; token opaco cifrado, sem PII na URL/log).

---

## 21. Payout — saque da performer

Ver memória `payout-needs-review-exit-door` e `docs/`.

- Service: `PayoutService`. Model: `Payout`. Ledger: `payout_reserve` (−) e
  `payout_reversal` (+, estorno).
- **Porta de saída `needs_review`** (`2026_07_15_120000`): quando a reconciliação
  não resolve, o payout vira `needs_review` → **alerta + requeue** (Sprint 5, PR
  #66). O prazo conta de `unresolved_since`, não de `requested_at`.
- Command `ReconcilePayouts`. Webhook de transfer: `AsaasTransferWebhookController`.
- Admin: `Api/V1/AdminPayoutController`, `Web/Performer/PayoutController`.

> **Furo conhecido (memória `payout-ambiguous-failure-double-pay`):** 429/408 no
> `createTransfer` ainda pode estornar indevidamente. Verificar antes do go-live
> com Asaas real.

---

## 22. Geobloqueio — FOSTA-SESTA

> **Estado: MONTADO, NÃO ATIVO.** Com `GEO_DRIVER=none` (padrão e valor de hoje),
> o middleware `GeoBlock` roda em toda requisição `web` e `api` e **não bloqueia
> ninguém**. Fail-OPEN de propósito — fail-closed sem fonte derruba o site.

Detalhes: `docs/GEOBLOCKING.md`, `config/geo.php`.

### 22.1 Por que existe

FOSTA-SESTA (EUA, 2018) retirou a imunidade da Section 230 para plataformas em
conteúdo de terceiros ligado a prostituição. O Limen não opera nos EUA. Barrar na
borda reduz exposição e demonstra intenção.

### 22.2 O que está implementado

| Peça | Arquivo |
|---|---|
| Config (driver, países, fail-open) | `config/geo.php` |
| Resolução do país | `app/Services/GeoLocationService.php` |
| Bloqueio + audit | `app/Http/Middleware/GeoBlock.php` |
| Testes | `tests/Feature/GeoBlockTest.php` |

- Resposta **451 Unavailable For Legal Reasons** (não 403). HTML na web, JSON em
  `api/*`.
- `BLOCKED_COUNTRIES` (CSV ISO alfa-2), padrão `US`.
- `/up` fica **de fora** (monitor de uptime sonda dos EUA).
- `access.geo_blocked` no audit, **deduplicado por IP/hora** (`GEO_AUDIT_DEDUP_MINUTES`).

### 22.3 Como ativar (resumo)

- **Cloudflare** (`GEO_DRIVER=cloudflare`): **só funciona com o origin fechado
  aos ranges do CF.** `CF-IPCountry` é header; `curl -H` direto no IP do servidor
  passa. Sem a trava de rede, o driver não bloqueia nada e ainda dá impressão de
  que bloqueia. Pior que não ter.
- **MaxMind GeoLite2** (recomendado): independe de proxy. Exige `.mmdb` +
  `geoipupdate` + driver `maxmind` + **configurar `TrustProxies`** (hoje o
  projeto não configura nenhum).

### 22.4 Limite jurídico

**VPN contorna.** Isto reduz exposição, não impede acesso. **Não escreva
"americanos não conseguem acessar"** em política, contrato ou auditoria. Redação
correta: "bloqueamos acessos identificados como originários dos EUA".

---

## 23. Aceite de documentos da performer

Middleware `documents.accepted` (`DocumentsAccepted`). Política de Conteúdo
Proibido + Contrato de Performance. Versão vigente em `config/documents.php`.

- **A versão é a data de publicação** (`2026-07-20`), não um contador. **Bumpar a
  versão força re-aceite de TODAS** — não bumpe por typo (derruba a plataforma
  inteira na tela de aceite).
- **A versão nunca vem do request:** o servidor resolve pelo config, senão
  bastaria postar a versão velha para satisfazer o gate sem ver o texto novo.
- `document_acceptances` é **append-only** (o model recusa `update`): versão nova
  é LINHA nova — é o lastro jurídico.
- IP e user-agent entram como **HMAC** (`app/Support/ClientFingerprint.php`),
  nunca crus. (Ressalva: o `audit_logs` do mesmo evento ainda grava IP em claro —
  registrado em `docs/SECURITY_ISSUES.md`.)
- **Vale nas duas portas:** web (redirect) e API Sanctum (403 JSON). O middleware
  ignora quem não é performer — rota compartilhada (chat) pode recebê-lo direto.
  Fora do gate: a própria tela de aceite e as páginas públicas dos textos.
- Controller: `Web/Performer/DocumentAcceptanceController`. Textos públicos:
  `Web/LegalDocumentsController`.

> **O texto jurídico ainda é PLACEHOLDER** (aguardando escritório Opice Blum).
> **NÃO descrever para auditoria como "contrato aceito"** até o texto definitivo
> entrar. **Rota nova de performer entra no grupo `documents.accepted`.**

---

## 24. LGPD — Hard Delete e sistema de Report

### 24.1 Hard Delete (`91d4735`, PR #74)

- Service: `DeletionService`. Model: `DeletionLog`. Colunas de deleção em `users`
  (`deletion_requested_at`, `deletion_scheduled_at`, `deletion_confirmed_at`,
  `deletion_token_hash`, `deletion_token_expires_at`) — **fora do `$fillable`**.
- **Grace period de 30 dias.** Command `ProcessScheduledDeletions` executa após o
  prazo.
- Controller: `Web/Account/DeletionController`.
- **`profile_visits` são apagadas** (`purgeProfileVisits()`, `DELETE` real na
  transação); visitas recebidas saem quando a performer encerra
  (`purgeVisitsToOwnProfile()`).
- **`deletion_token_hash` é `$hidden`.**
- O que sobrevive ao hard delete: registros com valor fiscal/legal (ledger, audit
  log). O que é apagado: PII, mapa de interesses (profile_visits), perks.

### 24.2 Report system (`401c650`, PR #73)

Sistema mínimo viável de denúncia (compliance legal).

- Model: `Report`. Exige `reporter_id` e um **alvo morfável** (`morphTo`).
- Alias do denunciante: `app/Support/ReporterAlias.php`.
- Controllers: `Web/Consumer/ReportController` (criar),
  `Web/Admin/ReportAdminController` (moderar).
- É o **caminho para o caso ambíguo** que o filtro de chat não barra (§17.3): tem
  contexto e um humano do outro lado.
- **Mensagem bloqueada pelo filtro NÃO é persistida** — então a fila humana com
  contexto de verdade é follow-up.

---

## 25. Rotas, CI/CD, deploy e ambiente

### 25.1 Rotas

125 rotas no total. `routes/web.php` (90 `Route::`), `routes/api.php` (39 HTTP),
`routes/channels.php` (broadcasting), `routes/console.php` (commands).

> **⚠️ Ziggy allowlist — tela preta.** `config/ziggy.php` tem um `only`
> (allowlist). Se um componente Vue chamar `route('x')` e `x` não estiver na
> lista, o Ziggy lança erro, o Vue morre na montagem e **TODAS as páginas ficam
> pretas**. **Toda rota nova usada no frontend PRECISA entrar em
> `config/ziggy.php`.** Há teste de allowlist (`ZiggyAllowlistTest`).

### 25.2 CI (`.github/workflows/deploy.yml`)

- Dispara em push/PR para `main`.
- Job **Testes**: MySQL 8.4 service, PHP 8.5 (extensions mbstring, pdo, pdo_mysql,
  bcmath, intl, redis), Node 20, `composer install`, `composer audit --no-dev ||
  true` (informativo), `npm ci`, `npm run build`, e a suíte com `DB_*` de MySQL.
- **Não há step de lint (Pint).**
- **Security audit** é informativo (`|| true`) — endurecer para hard fail é
  follow-up (P quando a poeira de advisories for triada).

### 25.3 Deploy

- Deploy via SSH (host de dev `62.238.46.212`, `/var/www/limen`). Usa
  `git reset --hard origin/main`.
- **`gh` CLI ausente e sem token:** não dá para abrir PR/issue por código. O push
  devolve a URL de `pull/new` para o PO abrir manualmente.
- **Sudoers do deploy NÃO cobre `mkdir`** — NOPASSWD só para chown/supervisorctl/
  nginx. `sudo mkdir` quebra deploy.
- **Deploy pode falhar por permissão do vendor** — `composer install --no-dev`
  morre se `vendor/` estiver com dono errado no servidor.
- **prod público e staging = mesmo host** (`thelimen.com.br` no box de dev). Se
  algo parece "desatualizado", suspeitar de **opcache/CDN**, não de código.
- **Acesso a staging via túnel `:8443`.** Origem `:8443` ≠ `APP_URL :443` quebra
  POSTs do Inertia (logout); o backend fica OK.

### 25.4 Variáveis de ambiente (`.env.example`, não-comentadas)

Grupos relevantes (valores default/exemplo):

```
APP_NAME=Limen · APP_ENV=local · APP_DEBUG=true · APP_URL=http://localhost
PANIC_REDIRECT_URL=https://www.google.com.br
DB_CONNECTION=sqlite   (dev/CI usam MySQL via DB_* no comando)
SESSION_DRIVER=database · CACHE_STORE=database · QUEUE_CONNECTION=database
BROADCAST_CONNECTION=log   (Reverb não roda)
REVERB_APP_ID/KEY/SECRET/HOST/PORT/SCHEME (+ VITE_REVERB_*)
MAIL_MAILER=resend · MAIL_FROM_ADDRESS=noreply@thelimen.com.br · RESEND_API_KEY=...
ADMIN_EMAIL=admin@thelimen.com.br
WAITLIST_NURTURE_START_AT= · WAITLIST_NURTURE_MAX_PER_RUN=200 · FOUNDER_CUTOFF_AT=
AWS_* (S3, vazio)
KYC_PROVIDER=fake · KYC_API_KEY= · KYC_WORKFLOW_ID= · KYC_WEBHOOK_SECRET= · KYC_BASE_URL=https://verification.didit.me
ASAAS_DRIVER=fake · ASAAS_ENV=sandbox · ASAAS_BASE_URL=https://sandbox.asaas.com/api/v3 · ASAAS_API_KEY= · ASAAS_WEBHOOK_TOKEN=
ANONYMITY_FLOOR_ACCOUNT_AGE_DAYS=7
GEO_DRIVER=none · BLOCKED_COUNTRIES=US · GEO_BLOCK_UNKNOWN=false
CHAT_FILTER_ENABLED=true · CHAT_FILTER_AUDIT_DEDUP_MINUTES=10
```

> **`ASAAS_API_KEY` começa com `$`** → aspas simples no `.env` (senão vira
> variável do shell → 401).

---

## Apêndice A — Backlog e próximos passos

### A.1 Go-live (pré-produção)

- [ ] **Integrações reais** — sair do driver `fake`: Asaas (chaves sandbox/prod),
      Didit (KYC_API_KEY, workflow, webhook secret).
- [ ] **Texto jurídico definitivo** (Opice Blum) entra em `config/documents.php`
      → bump de versão força re-aceite. Só então descrever como "contrato aceito".
- [ ] **Ativar geobloqueio** (MaxMind recomendado) + `TrustProxies`.
- [ ] **Payout com Asaas real** — verificar o furo 429/408 → estorno indevido.
- [ ] **HSTS condicional ao ambiente** (P0 histórico: reset --hard restaura 1 ano
      + preload; tornar condicional no código).
- [ ] **Subir o Reverb** (chat em tempo real hoje em driver `log`).

### A.2 Sprint 8 (previsto)

Novos para o Sprint 8:

- [ ] **KYC Nível 2 para membros** — documento + selfie via Didit, fila de
      revisão de 48h. Hoje o membro só passa por `cpf_dob` (§9); é o próximo
      nível de verificação de idade/identidade do lado do consumidor.
- [ ] **Status `banned` (permanente)** separado de `suspended` (temporário) —
      hoje há só `active`/`pending`/`suspended`; expulsão definitiva e suspensão
      reversível não devem colidir no mesmo estado.
- [ ] **Lista negra antifraude** — hash de CPF + hash de documento, para barrar
      recadastro de conta banida sem guardar a PII crua (mesma disciplina do
      `CpfHash`/`ClientFingerprint`).
- [ ] **Editar `worlds` pós-cadastro no profile-edit** — o Sprint 7 entregou
      multi-mundos só no cadastro; o `UpdatePerformerProfileRequest` ainda só
      aceita `category`. Sem isso, `category` e `worlds` podem divergir num
      rename de mundo (§5, pegadinha registrada).
- [ ] **Soft descriptor Asaas** (nome na fatura do cartão/PIX) — depende do CNPJ.
- [ ] **KYC Didit em produção** — sair do driver `fake`, confirmar o encoding do
      `x-signature` do webhook v3 contra o ambiente real.

Arrastados do Sprint 7 (previstos e **não iniciados** — seguem abertos):

- [ ] **Age verification contra base oficial** (Serpro/DataValid) — gravar
      `method = 'serpro'` na mesma tabela para distinguir níveis.
- [ ] **Login web em dois passos** (desafiar 2FA antes de estabelecer a sessão).
- [ ] **Alerta em N falhas de desafio 2FA** (hoje só grava audit, ninguém consome).
- [ ] **Fila humana de moderação com contexto** (reports com corpo/contexto).
- [ ] **Módulo de conteúdo** — quando existir, construir moderação e pipeline de
      verificação **antes** do primeiro upload. Vincular conteúdo↔pessoa
      verificada. Ver `docs/LEGAL_GAP_ANALYSIS.md`.

### A.3 Higiene / dívida técnica

- [ ] CI de lint (Pint `--test`) — hoje não existe; a árvore está limpa (`e043077`)
      mas nada impede regressão.
- [ ] `composer audit` como hard fail (hoje `|| true`).
- [ ] Retenção/expurgo de documentos KYC (follow-up).
- [ ] `.env.example` induz a SQLite (P2) — documentar/ajustar.

---

## Apêndice B — Limitações conhecidas (não redescobrir)

Registro para **não serem redescobertas como novidade**. Todas são decisões
conscientes, não bugs.

1. **Painel de visitantes NÃO é anônimo contra adversário ativo.**
   - *Polling numa faixa já visível:* uma faixa já visível que ganha um visitante
     o entrega por diferença entre dois refreshes (o diff devolve exatamente 1
     alias novo). Fechar exigiria release em lote (não implementado).
   - *Eliminação com contas envelhecidas (A2):* os cortes do piso (7 dias +
     e-mail) são custo de setup **único**, não recorrente. Pagos uma vez, o painel
     fica destravado e cada visitante real seguinte sai por eliminação contra os
     aliases plantados. O k e a faixa encarecem; não eliminam.

2. **Geobloqueio é contornado por VPN.** Reduz exposição, não impede acesso. Não é
   garantia jurídica.

3. **Age verification `cpf_dob`** prova que um CPF estruturalmente válido foi
   digitado — não que a pessoa tem 18 anos nem que o CPF é dela.

4. **Filtro de chat não é anti-evasão.** A lista está no repo; o remetente
   distingue categorias pela resposta. Ausência de bloqueio não prova que nada foi
   combinado. Erra no elogio seco ("sua puta").

5. **Login web completa antes do fator 2FA.** Mitigado pelo gate cobrir o grupo
   `auth` inteiro; login em dois passos é follow-up.

6. **Aceite de documentos é sobre texto PLACEHOLDER.** Não descrever como
   "contrato aceito" até o texto definitivo entrar.

7. **FanAlias `label()` (4 dígitos) colide** — dois membros podem cair no mesmo
   rótulo com poucas centenas de seguidores. Nunca use como chave; use `handle()`
   (16 hex) para identificação.

8. **Rotacionar `APP_KEY`** derruba: pseudônimos FanAlias, secret/recovery 2FA,
   documentos KYC cifrados, CPF HMAC. Nada "quebra" catastroficamente, mas
   históricos/decodificações se perdem — planeje.

9. **Arquivos KYC órfãos em falha do provider.** Quando
   `kycClient->submitVerification()` lança exceção, a transação faz rollback
   (nenhum registro criado no banco), mas os arquivos já gravados em
   `storage/app/kyc/` pelo `KycDocumentStore::store()` permanecem no disco sem
   referência. Não é exploitável (disco privado, não servível), mas acumula lixo
   em falhas repetidas. Correção futura: mover o store para dentro da transação
   com cleanup em caso de rollback, ou job de GC que compara paths no banco vs
   disco periodicamente. Registrado em: c302560 (lockForUpdate KYC) —
   comportamento pré-existente, não regressão.

> **Disciplina de linguagem (transversal):** vários controles acima são
> deliberadamente mais fracos do que parecem. **Não os descreva como mais fortes
> do que são** em copy de produto, política de privacidade, contrato, pitch ou
> auditoria. Uma ressalva ausente custa mais numa auditoria do que o controle
> fraco em si.

---

## Apêndice C — Glossário

| Termo | Significado |
|---|---|
| **Piso de Anonimato** | performer só vê a lista de seguidores a partir de 5 (elegíveis) |
| **Modo Discreto** | membro Black/FC conta para o piso mas nunca é listado |
| **FanAlias** | pseudônimo do membro derivado por par via HMAC (`label` 4 díg. / `handle` 16 hex) |
| **Interesse Controlado** | performer sinaliza, membro paga 15 tokens (100% plataforma) para ver |
| **ChatAccess** | janela de acesso paga ao chat (50 tokens / 30 dias + 15 grace) |
| **Ghost Mode** | perk: visita ao perfil não é gravada (ausência de linha) |
| **needs_review** | estado de payout que a reconciliação não resolveu (alerta + requeue) |
| **k-anonimato (k=3)** | faixa de horário do painel de visitantes só aparece com ≥3 aliases |
| **suppressed** | status de interesse mascarado pelo opt-out do membro |
| **Founding Members** | primeiros assinantes (trial 7d, `FOUNDER_CUTOFF_AT`) |
| **Duas portas de auth** | API = Sanctum (token); Web = sessão + CSRF |
| **Fonte única** | serviço dono de uma regra; duplicar cria oráculo |
| **Sprint N** | única numeração válida (Fase N em docs antigos é legado, ≠ Sprint N) |

> **Numeração — só existe UMA: Sprint.** O trabalho fundacional era numerado por
> "Fase" e as duas sequências colidiam. Docs antigos (`fase2-*`, `fase4-*`) ainda
> falam em Fase — são históricos, e "Fase N" ali **não** é "Sprint N". **Sprint 2
> não tem registro** — a numeração pula de 1 para 3 de propósito.

---

## Apêndice D — Inventário de arquivos por domínio

### Services (`app/Services/`)

```
AuthService · TokenService · PaymentService · TipService · PayoutService
KycService · SubscriptionService · FollowService · InterestService
PerformerProfileService · PerformerCatalogService
ChatService · ChatAccessService
FollowerVisibilityService · DiscreteModeService · ProfileVisitService · PrivacyPerkService
DocumentAcceptanceService · DeletionService · TwoFactorService
GeoLocationService · SharedRegistrationIpService
Asaas/ (AsaasHttpClient, FakeAsaasClient) · Kyc/ (DiditKycClient, KycHttpClient,
FakeKycClient, KycDocumentStore) · Waitlist/ (FounderPresenter, …)
```

### Support (`app/Support/`)

```
FanAlias · ReporterAlias · ClientFingerprint · CpfHash · Audit
ChatContentFilter · AvatarPlaceholder
```

### Middleware (`app/Http/Middleware/`)

```
SecurityHeaders · GeoBlock · HandleInertiaRequests · EnsureUserHasRole
EnsureActiveCircle · DocumentsAccepted · TwoFactorChallenge · VerifyAsaasWebhookIp
```

### Commands (`app/Console/Commands/`)

```
ExpireSubscriptions · ProcessScheduledDeletions · PurgeExpiredChatAccess
PurgeExpiredProfileVisits · ReconcilePayments · ReconcilePayouts
ReconcileWallets · SendWaitlistNurture · BackfillPerformerAvatars
```

### Configs (`config/`)

```
app · asaas · auth · broadcasting · cache · chat · chat_filters · cors · database
documents · filesystems · geo · inertia · interest · kyc · logging · mail · queue
reverb · sanctum · services · session · waitlist · ziggy
```

### Docs relevantes (`docs/`)

```
CLAUDE.md (raiz — cérebro do projeto)
MASTER_HANDOFF_FINAL.md (este arquivo)
MASTER_HANDOFF_SPRINT6.md · MASTER_HANDOFF_SPRINT5.md
SECURITY_ISSUES.md · LEGAL_GAP_ANALYSIS.md · PCI_SAQ_D.md
GEOBLOCKING.md · INTEREST_SYSTEM_SPEC.md · INTEREST_ANONYMITY_FLOOR.md
SUBSCRIPTION_TIERS.md · CIRCLES_SYSTEM_V4.md (⚠️ preços divergem — código vence)
WAITLIST_SPEC.md · COMMUNICATION_ECONOMY.md · CURRENT_ISSUES_AND_NEXT_ACTIONS.md
```

### Skills disponíveis (invoque quando o domínio bater)

| Skill | Quando |
|---|---|
| `token-ledger-rules` | creditar/debitar/consultar tokens; integrar pagamento/gorjeta/payout |
| `asaas-pix-integration` | criar cobranças, tratar webhooks, conciliar pagamentos |
| `laravel-api-conventions` | criar rotas/controllers/requests/resources/auth de API |
| `catalog-ux` | telas de descoberta, cards de performer, filtros, perfil público |

---

## Checklist de continuidade para o próximo chat

- [ ] Ler o `CLAUDE.md` inteiro (é o cérebro; este handoff é o mapa).
- [ ] Rodar a suíte com os `DB_*` de MySQL e confirmar **819 verdes** antes de
      começar.
- [ ] Antes de tarefa sensível (cadastro, KYC, pagamento, payout, privacidade),
      rodar o **subagente de segurança** (`security-reviewer`).
- [ ] Toda rota nova de frontend → `config/ziggy.php`.
- [ ] Toda rota autenticada nova de performer → gate `2fa` **e** `documents.accepted`,
      nas **duas portas**.
- [ ] Todo movimento de token → **linha nova no ledger**, nunca UPDATE de saldo.
- [ ] Toda nova superfície que mostre membro à performer → `FanAlias`, nunca id.
- [ ] Não descrever nenhum controle como mais forte do que é (Apêndice B).
- [ ] Migration para toda mudança de schema; Form Request para toda validação.
- [ ] 1 PR por entrega; testes verdes antes de "pronto".

---

*Fim do MASTER_HANDOFF_FINAL. Gerado em 22/07/2026 a partir da inspeção do código
real na branch `feat/sprint6-final`. Onde este doc e o código divergirem no
futuro, o código vence — e a divergência deve ser registrada aqui ou no CLAUDE.md.*
