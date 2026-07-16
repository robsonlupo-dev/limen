# LIMEN — RETOMADA EM CHAT NOVO

> **Gerado em:** 16/07/2026 (fim do dia) · **Base:** `main` em `a07a8c5` (Merge PR #44) · **Suíte:** 396 testes verdes
> **Método:** escrito a partir da inspeção do código real (`git log`, `route:list`,
> `migrate:status`, leitura dos specs), não de memória. Onde o código contradiz um doc,
> o código venceu e a divergência está registrada.
>
> **Substitui a RETOMADA anterior de 16/07** (base `c7d9b24` / PR #39, 380 testes). O que
> mudou hoje: **4 mundos** entraram no código (§3.3), a **Fase A das assinaturas (Círculos)**
> foi mergeada — billing recorrente por cartão + middleware de Círculo (§1.2) —, gorjeta no
> perfil público e o backup do KYC corrigido. Continua substituindo os handoffs de 02/07
> (`CURRENT_ISSUES_AND_NEXT_ACTIONS.md`, `TECHNICAL_HANDOFF_MASTER.md`, `QA_HANDOFF_MASTER.md`).

---

## 1. ESTADO ATUAL DO PRODUTO

Limen é uma plataforma premium de conteúdo adulto verificado para o mercado brasileiro.
Hoje está **pré-lançamento**: o produto logado funciona ponta a ponta em staging, e o
domínio público serve apenas a captação de waitlist.

### 1.1 Stack real (verificada no código, não a do CLAUDE.md)

| Item | Real | Observação |
|---|---|---|
| PHP | **8.4.22** | `composer.json` exige `^8.3`. O CLAUDE.md diz "PHP 8.5" — **está errado** |
| Laravel | **13** (`^13.8`) | |
| Front | Inertia + Vue 3 + Tailwind v4 + Ziggy | frontend usa rotas **web** (sessão/CSRF), não Sanctum |
| Banco | MySQL 8.4 (Docker) | Redis para cache/filas |
| Mail | Resend (`resend/resend-laravel`) | webhook em `/resend/webhook` |
| Auth | Sanctum (API v1) + **sessão/CSRF (web)** | as duas superfícies coexistem |
| Rotas | **92** registradas (`route:list`) | |
| Testes | **396** verdes (1803 asserts) | |

### 1.2 O que está implementado e funcionando

Tudo abaixo está na `main`, com teste.

**Fundação e dinheiro**
- **Ledger append-only** (`token_ledger`): saldo é sempre a soma das linhas; update/delete
  bloqueados e testados (princípio nº 2 do CLAUDE.md). Tipos de lançamento hoje:
  `purchase, bonus, refund, adjustment, spend_tip, tip_credit, spend_private, spend_camera,
  spend_interest_unlock, payout_reserve, payout_reversal, staging_seed_backfill, subscription_grant`.
- **Compra de tokens via PIX/Asaas** com webhook idempotente por id de evento + reconciliação
  agendada. Driver **Fake** por padrão (`ASAAS_DRIVER=fake`). O preço aplica o **desconto do
  Círculo ativo** do usuário (sobre o preço, nunca sobre a quantidade de tokens).
- **Gorjetas** (`TipService`) com split por nível da performer, rate limit 10/min. Enviável
  também do **perfil público** por membro logado (PR #41, `TipModal.vue` compartilhado).
- **Payouts** (saque PIX da performer) — `/performer/payouts`. Hardening anti-pagamento-em-dobro
  na `main`; restam follow-ups menores (§4.4).

**Assinaturas / Círculos — Fase A (billing backend, PR #44) — NOVO**
- **Só backend.** Não há tela nem endpoint de assinatura ainda (§1.3). É a infraestrutura de
  cobrança e a autorização por Círculo.
- Tabelas: **`circles`** (5 tiers seedados com preço/tokens/desconto/vagas/invite), **`subscriptions`**
  (uma ativa por usuário via índice único em `active_lock`, mantido pelo model), **`subscription_charges`**
  (âncora de idempotência do grant mensal).
- **`SubscriptionService`**: `subscribe` (cartão tokenizado no Asaas — **PAN nunca armazenado**,
  só `card_token` cifrado em repouso + last4 + brand), `cancel` (`cancel_at_period_end`), e
  `handleWebhook` idempotente que credita a franquia mensal de tokens pelo ledger append-only
  (`subscription_grant`). O grant inicial é ancorado no **id real da primeira cobrança** (buscado
  no Asaas), então o mês 1 não duplica em produção; o webhook **reconsulta o gateway** antes de conceder.
- **Asaas**: `createSubscription/getSubscription/getSubscriptionPayments/cancelSubscription`
  (billingType CREDIT_CARD) nos clients HTTP e Fake.
- **Middleware `circle`** (`->middleware('circle:prestige')` = Prestige **ou superior**) + gate
  `circle-active`; `auth.user.circle` (slug do Círculo ativo) exposto ao Inertia. Ordem dos tiers
  em `Circle::TIER_ORDER`.
- `security-reviewer` aprovado (um crítico de double-grant + 3 alertas corrigidos antes do merge).

**Identidade e acesso**
- Cadastro/login/logout/me, verificação de e-mail, reset de senha, middleware de role,
  policies, audit log. Web (sessão) e API v1 (Sanctum) coexistem.
- **KYC** de performer (webhook Didit, resubmissão). Documentos **criptografados em repouso**
  no disco isolado `kyc` via `APP_KEY`. Driver **Fake**.
- Gate de idade (18+) e `SecurityHeaders` com HSTS condicional por ambiente.

**Descoberta**
- **Catálogo autenticado** `/catalogo` (por mundo) + **catálogo público** `/performers` com
  meta OG server-side (SEO, sem auth). **4 mundos** oficiais: mulheres, homens, casais, trans
  (§3.3). Follows (restrito a membros ativos). Onboarding + edição de perfil pela performer ativa.

**Waitlist (a superfície pública de hoje)**
- Cadastro em 2 passos (membro/performer), double opt-in, Founding Members v3 com
  referrals/painel do fundador (`/f/{invite_code}`), convites (`/convite/{code}`).
- **Drip de nurturing:** 7 e-mails. ⚠️ exige `WAITLIST_NURTURE_START_AT` (§4.4).
- Descadastro GET-confirma / POST-executa (à prova de prefetch de mailbox). Admin: `/admin/waitlist`.

**Interesse Controlado (Sprint 3 — fechado)**
- Performer sinaliza a um seguidor; membro paga **15 tokens** (100% plataforma) para revelar quem é.
- Limite 5 envios/dia, cooldown 30 dias por par, **opt-out silencioso**. Painéis `/interesses`,
  `/painel` e aba "Interesses enviados" na performer. Envio restrito a quem já segue.

### 1.3 O que **NÃO** existe (não reportar como bug)

Verificado por `grep` no código, não por memória:

- **Frontend das assinaturas** — a Fase A é **só backend**. Não há endpoint HTTP de `subscribe`,
  Form Request de cartão, nem tela Vue. O `SubscriptionService` é chamável, mas nada o expõe ao
  usuário ainda. ⚠️ Quando o endpoint entrar: adicionar `number`/`ccv`/`expiryMonth`/`expiryYear`/
  `holderName` ao `dontFlash` e validar via Form Request (achado não-bloqueante da revisão do #44).
- **Chat / mensagens** — não construído. É benefício do **Explorador** ("chat livre", hoje **oco**)
  e pré-requisito da spec de Interesse (canal + 1ª mensagem grátis). O desbloqueio só revela identidade.
- **Fase B dos Círculos** — **Black e FC não abrem** (regra: só após 5–10 performers Exclusive).
  `seat_limit` existe na tabela `circles` mas **não é aplicado** (nenhum cap de vagas Black/FC);
  não há **`fc_numbers`** (pool 1–9999 + aposentadoria aos 6 meses), Halls, nem número BLACK.
- **Hold de tokens no ledger (Mementos)** — não implementado. Não há `entry_type` de reserva; o
  hold de 800 tokens do Memento (`MAISON_PROGRAM.md`) segue documental (§4.3).
- **Cofre das FC Sessions** — ⛔ TRAVADO por decisão jurídica (§4.2). Nada de código.
- **Trial de 7 dias dos Founding Members** — a "semana grátis de lançamento" (assinar qualquer
  Círculo → 7 dias grátis) está travada no doc, **sem código**.
- **Feed, conteúdo pago destravável, streaming (LiveKit), score/tiers de performer** — não construídos.

---

## 2. PRs MERGEADOS HOJE (16/07 — #40 → #44)

A RETOMADA anterior (de manhã) entrou como PR #42.

| PR | Branch | O que entregou |
|---|---|---|
| **#40** | `fix/kyc-backup` | Corrige a **issue #38**: `docs/backup.sh` agora cobre `storage/app/kyc` (docs KYC cifrados) além de `storage/app/private`, com `mkdir -p` de guarda. Squash-merge (`0609b58`) |
| **#41** | `feat/tip-on-public-profile` | Gorjeta no **perfil público** para membro logado (`role === 'consumer'`), reusando `TipModal.vue` compartilhado com o catálogo |
| **#42** | `docs/retomada-16-07` | RETOMADA da manhã + alinhamento/remoção da régua de marcos físicos no `MAISON_PROGRAM.md` |
| **#43** | `feat/4-worlds` | **4 mundos** (gls→homens, swing→casais); migration production-safe; SSOT em `PerformerProfile::WORLDS` (§3.3) |
| **#44** | `feat/subscriptions-phase-a` | **Círculos Fase A** — billing recorrente por cartão (Asaas) + middleware de Círculo (§1.2) |

> **`gh` CLI não existe** no ambiente (§6.6) — PRs são abertos manualmente pela URL que o `push` devolve.
> Numeração antiga: **#38 foi issue** (backup KYC), não PR; #39 travou os Círculos nos docs.

---

## 3. DECISÕES DE PRODUTO

### 3.1 Sprint 3 — Interesse Controlado (travadas)
- 15 tokens de desbloqueio = **100% plataforma**; performer não é creditada.
- **Chat adiado explicitamente**; opt-out do membro **silencioso**; desbloqueio permanente, pago uma vez por performer.

### 3.2 Sistema de Círculos / MAISON / Mementos (travadas, PR #39)

> Docs: **`CIRCLES_SYSTEM_V4.md`** e **`MAISON_PROGRAM.md`**. **"Círculos", nunca "Planos".**
> A **Fase A já é código** (§1.2); Black/FC/Mementos/Trial ainda são só spec (§1.3).

| Tier | Preço/mês | Vagas | Marca |
|---|---|---|---|
| Explorador | R$ 89,90 | ilimitadas | 75 tokens/mês, chat livre, badge prata |
| Insider | R$ 189,90 | ilimitadas | 200 tokens/mês, prioridade no Interesse |
| Prestige | R$ 389,90 | ilimitadas | 500 tokens/mês, 1 live privada, Modo Discrição básico |
| Black | R$ 749,90 | **máx. 500** | 1.200 tokens/mês, Número BLACK, Exclusive/Maison |
| **Founders Circle** | R$ 1.490,00 | **convite, máx. 100** | escolhe o número FC (1–9999) |

- **Invariante:** assinatura **não substitui** tokens — reduz atrito e custo. (Já refletido: desconto
  por Círculo no `PaymentService`; franquia mensal via `subscription_grant`.)
- **Número FC / aposentadoria** (divisor: 6 meses de FC ativo), **colecionáveis somem ao cancelar**,
  **endereço de entrega é PII** (Locker, nunca residência), hierarquia MAISON (Verificada 20% →
  Select 17% → Maison 12%, máx. 50; Conselho a partir do 6º mês). Lançamento: semana grátis;
  Black/FC só após 5–10 Exclusive; tier abaixo do Explorador rejeitado.
- **Limen Mementos:** custo logístico fixo **800 tokens** (100% plataforma) via **hold no ledger** na
  aprovação da foto; mensagem de bloqueio **sempre genérica** (mesma doutrina da máscara do opt-out).

### 3.3 4 mundos (travada e implementada, PR #43)
4 mundos oficiais: **mulheres, homens, casais, trans**. GLS→homens, Swing→casais (migration
production-safe; remapeou `performer_profiles.category`, `users.preferred_world` e `waitlist_entries.world`).
SSOT em **`PerformerProfile::WORLDS`**.

---

## 4. PENDÊNCIAS E DECISÕES EM ABERTO

### 4.1 ✅ Resolvidos recentemente
- **Hardening de payout** contra pagamento em dobro (na `main` desde 15/07).
- **4 vs 6 mundos**, **régua de marcos físicos**, **Círculos vs Planos** — todas as contradições de
  spec fechadas (§3.3 e histórico).
- **Backup do KYC** (issue #38) — o tarball agora cobre `storage/app/kyc` (PR #40).

### 4.2 🔴 Bloqueio jurídico — cofre das FC Sessions
`MAISON_PROGRAM.md` descreve gravação backend por 90 dias para uso **exclusivo** em investigação.
**⛔ TRAVADO até aprovação jurídica** — dado de vida sexual (art. 11, LGPD) sem transparência nos
termos; consentimento não é base suficiente (o cofre precisa sobreviver à revogação). Falta infra
que **não existe** (D1–D6): cripto de vídeo em streaming, modelo de roles para o Curador (hoje
`consumer|performer|admin`), expurgo automático verificável, audit log de **leitura**.

### 4.3 Monetização — o que falta além da Fase A
A Fase A entregou o **billing** dos Círculos. Ainda falta:
- **Frontend das assinaturas** — tela de escolha de Círculo, coleta de cartão (Form Request +
  `dontFlash`), fluxo de subscribe/cancel, exibição do Círculo ativo. É a próxima entrega natural.
- **Chat** — benefício do Explorador (hoje oco) e pré-requisito do Interesse. ⚠️ ler o aviso de
  `INTEREST_ANONYMITY_FLOOR.md`: mensagem a uma linha mascarada tem de **parecer sucesso e não
  entregar nada**, senão o opt-out vaza.
- **Fase B — Black/FC:** enforcement de `seat_limit` (vagas), `fc_numbers` (pool 1–9999 +
  aposentadoria aos 6 meses), Halls, número BLACK. Gate de lançamento: 5–10 Exclusive antes de abrir.
- **Hold no ledger (Mementos)** — novo `entry_type` (ex. `memento_hold`), reserva na aprovação da
  foto, append-only (reservar não é `UPDATE saldo`).
- **Trial de 7 dias dos Founding Members** — semana grátis ao assinar qualquer Círculo no lançamento.

### 4.4 Outras pendências abertas
- **Drip de nurturing dispara em blast** se `WAITLIST_NURTURE_START_AT` não for setado na ativação.
- **Payout — follow-ups menores:** falta **alerta/requeue** para `needs_review`; revisar 429/408 no `createTransfer`.
- **Piso de anonimato do Interesse** (`INTEREST_ANONYMITY_FLOOR.md`) — **não decidido**; decidir com
  dado (distribuição de follows por membro).
- **`unlock()` não revalida se a performer segue ativa** — decisão de produto.
- **Pseudônimos correlacionáveis** entre o painel (`Fã #0042`) e a lista de seguidores (`Membro #42`).
- **Retenção/expurgo de documentos de KYC** — nunca feito; rotacionar `APP_KEY` quebra os `.enc`.
- **Integrações reais (Asaas/KYC) ainda em Fake** — pré-requisito de go-live.
- **`.env.example` induz a SQLite**, mas o projeto é MySQL (§6.4).

### 4.5 Afirmações de handoffs antigos que hoje são FALSAS
Não confie em `CURRENT_ISSUES_AND_NEXT_ACTIONS.md` / `TECHNICAL_HANDOFF_MASTER.md` sem checar:
"173/344 testes" (**396** hoje), "domínio limen.com.br" (produção é **thelimen.com.br**), CLAUDE.md
"PHP 8.5" (**8.4.22**) e "Próxima: Fase 8" (entregue há tempos). **Ainda válidas:** ledger append-only;
CPF só no checkout e PII isolada; `category` é o mundo (não criar coluna `world`); deploy por
`reset --hard`; sudoers restrito; idempotência de pagamento por id de evento; stack só muda com o PO.

---

## 5. PRÓXIMO SPRINT — O QUE ATACAR

Ordem sugerida.

1. **Frontend das assinaturas (Círculos Fase A → usável).** A infra de billing está pronta e testada;
   falta a experiência: tela de Círculos, coleta de cartão via Form Request (+ `dontFlash` dos campos
   de cartão), subscribe/cancel, e exibir o Círculo ativo (`auth.user.circle` já está no Inertia).
   Passa pelo `security-reviewer` (cartão/PCI).
2. **Chat/mensagens.** Destrava o benefício do Explorador e cumpre a spec do Interesse. ⚠️ respeitar
   o aviso do piso de anonimato (mensagem a linha mascarada = sucesso vazio).
3. **Follow-ups de payout** (§4.4) — alerta/requeue de `needs_review`.
4. **Decidir o piso de anonimato** (§4.4) com dado.
5. **Depois:** Fase B (Black/FC + vagas + `fc_numbers` + Halls), hold do Memento, trial de 7 dias.
6. **Não** iniciar o cofre das FC Sessions (§4.2) — bloqueio jurídico.

---

## 6. INFRAESTRUTURA ATUAL

### 6.1 Servidor
- **Hetzner `limen-dev-01`**, IP **62.238.46.212**, Ubuntu 24.04.
- Projeto em `/var/www/limen`; nginx + `php8.4-fpm`; SSL Let's Encrypt (ECDSA) via Certbot.
- Usuários SSH: `deploy` e `root`. **Não rodar git como root lá.**

### 6.2 Domínios
- **`limen.dev.br`** — staging, ativo, app completo.
- **`thelimen.com.br`** — produção. **Portão de marketing, não o app.** O vhost só deixa passar
  `/`, `/links`, `/interesse`, `/convite/`, `/f/`, `/waitlist/`; o resto redireciona para `/`. Handler
  PHP `internal`. Está **HTTP-only** no repo; rodar certbot **só depois** do DNS apontar. ⚠️
  `/performers` (catálogo público) **não está no allowlist** — decidir se entra no pré-lançamento.

### 6.3 CI/CD (`.github/workflows/deploy.yml`)
- Dispara em push/PR na `main`. **Testes:** `composer install` → `npm ci` → `npm run build` →
  `key:generate` → `php artisan test` (MySQL de serviço). **Não roda lint** (não há `pint.json`).
- **Deploy (SSH):** `reset --hard origin/main` → `composer install --no-dev` → `npm ci && build` →
  `migrate --force` → `config/route/view:cache` → restart do worker.
- **Armadilhas:** `composer install --no-dev` morre se algo em `vendor/` ficar com dono != `deploy`;
  o sudoers só libera **chown / supervisorctl / nginx** sem senha — **`sudo mkdir` não é permitido**.

### 6.4 Desenvolvimento local
- Docker: `limen-mysql` (3306), `limen-redis` (6379), `limen-adminer` (8080). App: `limen` /
  `limen_dev_pw`. Bancos: `limen` (dev), **`limen_test`** (usar nos testes p/ não zerar o dev).
- **Não há SQLite local.** `phpunit.xml` força `DB_CONNECTION=sqlite` e as vars de CLI vencem:
  ```bash
  DB_CONNECTION=mysql DB_DATABASE=limen_test DB_HOST=127.0.0.1 DB_PORT=3306 \
    DB_USERNAME=limen DB_PASSWORD=limen_dev_pw php artisan test
  ```
- **VM de trabalho:** VirtualBox Ubuntu na rede Verallia. Zscaler bloqueia `limen.dev.br` — **não é
  bug do site**. Túnel SSH `:8443`. ⚠️ Origem `:8443` ≠ `APP_URL :443` quebra POST do Inertia (logout).

### 6.5 Integrações
| Serviço | Estado |
|---|---|
| **Asaas / PIX + cartão** | `ASAAS_DRIVER=fake` (default e staging). Fase A usa `createSubscription` (CREDIT_CARD) — validar em sandbox antes de produção. Bootar produção com `fake` lança por design |
| **KYC (Didit)** | Fake. Documentos cifrados em repouso no disco `kyc` via `APP_KEY` |
| **Resend (e-mail)** | Configurado; webhook em `/resend/webhook` |
| **LiveKit** | Não integrado |
| ⚠️ `ASAAS_API_KEY` | Começa com `$` — **precisa de aspas simples no `.env`**, senão vira variável e dá 401 |

### 6.6 Ferramental do agente
- **Não há `gh` CLI nem token:** não dá para abrir PR por código. O `push` devolve a URL `pull/new/...`.
- Subagente **`security-reviewer`** é obrigatório antes de qualquer coisa sensível (cadastro, KYC,
  pagamento, payout, **cartão/PCI**, PII) — CLAUDE.md.
- **Toda rota nova usada no front precisa entrar em `config/ziggy.php`** (allowlist `only`), senão o
  Ziggy lança na montagem do Vue e o site inteiro fica em tela preta.
- **Backup** (`docs/backup.sh`): dump MySQL + tar de storage (`private` **e** `kyc`), cifrado por GPG.

---

## 7. ARRANQUE RÁPIDO PARA O PRÓXIMO CHAT

```bash
cd /home/robson/teste
git fetch origin && git checkout main && git reset --hard origin/main
docker ps                       # limen-mysql / limen-redis / limen-adminer no ar?
DB_CONNECTION=mysql DB_DATABASE=limen_test DB_HOST=127.0.0.1 DB_PORT=3306 \
  DB_USERNAME=limen DB_PASSWORD=limen_dev_pw php artisan test   # esperado: 396 verdes
```

Leia, nesta ordem: `CLAUDE.md` (princípios — ignore o "Estado atual", está velho) → este arquivo →
`CIRCLES_SYSTEM_V4.md` + `MAISON_PROGRAM.md` (as decisões da monetização) →
`INTEREST_SYSTEM_SPEC.md` + `INTEREST_ANONYMITY_FLOOR.md` (sprint entregue + decisão pendente).

**Primeira ação sugerida:** o **frontend das assinaturas** (§5.1) — a Fase A de billing está na `main`,
falta a experiência do usuário. Depois, **chat** (§5.2). Nada de cartão/PCI sem `security-reviewer`.
