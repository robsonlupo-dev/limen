<!-- Vocabulário: "Fase N" neste doc é LEGADO (ciclo da fundação) e NÃO
     corresponde ao "Sprint N" atual. Ex.: Fase 4 = perfis/catálogo;
     Sprint 4 = chat. O ciclo de entrega vigente é "Sprint N" — ver CLAUDE.md. -->

# LIMEN — RETOMADA EM CHAT NOVO

> **Gerado em:** 17/07/2026 · **Base:** `main` em `edbb1c1` · **Suíte:** 440 testes verdes (2066 asserts)
> **Método:** escrito a partir da inspeção do código real (`git log`, `route:list`,
> `migrate:status`, `php artisan test`), não de memória. Onde o código contradiz um doc,
> o código venceu e a divergência está registrada.
>
> **Substitui a RETOMADA de 16/07** (base `a07a8c5` / PR #44, 396 testes). O que mudou desde
> então: o **chat interest-gated ganhou tempo real de ponta a ponta** (Reverb) e está **no ar em
> staging** (§1.2, §2); o **frontend das assinaturas** foi mergeado (PR #46), tornando os
> Círculos Fase A **usáveis** (§1.2); e a **infra de WebSocket** (Reverb + `setup-reverb-server.sh`)
> entrou (§6). Continua substituindo os handoffs de 02/07
> (`CURRENT_ISSUES_AND_NEXT_ACTIONS.md`, `TECHNICAL_HANDOFF_MASTER.md`, `QA_HANDOFF_MASTER.md`).

---

## 1. ESTADO ATUAL DO PRODUTO

Limen é uma plataforma premium de conteúdo adulto verificado para o mercado brasileiro.
Hoje está **pré-lançamento**: o produto logado funciona ponta a ponta em staging
(`limen.dev.br`), e o domínio público (`thelimen.com.br`) serve só a captação de waitlist
(portão de marketing, **não o app** — §6.2).

### 1.1 Stack real (verificada no código, não a do CLAUDE.md)

| Item | Real | Observação |
|---|---|---|
| PHP | **8.4.22** | `composer.json` exige `^8.3`. O CLAUDE.md diz "PHP 8.5" — **está errado** |
| Laravel | **13** (`^13.8`) | |
| Front | Inertia + Vue 3 (`^3.5.39`) + Tailwind v4 + Ziggy | usa rotas **web** (sessão/CSRF), não Sanctum |
| Tempo real | **Laravel Reverb `^1.10`** + `laravel-echo ^2.1` + `pusher-js ^8.4` | transporte WebSocket do chat (§6.7) |
| Banco | MySQL 8.4 (Docker) | Redis para cache/**filas/sessão/broadcast** |
| Mail | Resend (`resend/resend-laravel`) | webhook em `/resend/webhook` |
| Auth | Sanctum (API v1) + **sessão/CSRF (web)** | as duas superfícies coexistem |
| Rotas | **101** registradas (`route:list`) | +9 desde o RETOMADA anterior (chat + subscribe) |
| Migrations | **48** arquivos, todas `Ran` | últimas 4 são do chat (17/07) |
| Testes | **440** verdes (2066 asserts) | `php artisan test` |

### 1.2 O que está implementado e funcionando

Tudo abaixo está na `main`, com teste.

**Fundação e dinheiro**
- **Ledger append-only** (`token_ledger`): saldo é sempre a soma das linhas; update/delete
  bloqueados e testados (princípio nº 2 do CLAUDE.md). Tipos de lançamento hoje incluem:
  `purchase, bonus, refund, adjustment, spend_tip, tip_credit, spend_private, spend_camera,
  spend_interest_unlock, payout_reserve, payout_reversal, staging_seed_backfill,
  subscription_grant, spend_chat_access, chat_access_credit`.
- **Compra de tokens via PIX/Asaas** com webhook idempotente por id de evento + reconciliação
  agendada. Driver **Fake** por padrão (`ASAAS_DRIVER=fake`). O preço aplica o **desconto do
  Círculo ativo** do usuário (sobre o preço, nunca sobre a quantidade de tokens).
- **Gorjetas** (`TipService`) com split por nível da performer, rate limit 10/min. Enviável
  também do **perfil público** por membro logado (`TipModal.vue` compartilhado).
- **Payouts** (saque PIX da performer) — `/performer/payouts`. Hardening anti-pagamento-em-dobro
  na `main`; restam follow-ups menores (§4.4, §5).

**Chat interest-gated — tempo real (Sprint 4, PRs #55/#56/#58/#59) — NOVO**
- **Modelo:** a conversa **nasce no desbloqueio do Interesse** (não há endpoint de abertura pelo
  membro). A performer manda a **1ª mensagem grátis**; o membro conversa de graça com **Círculo
  ativo** OU precisa de um **ACESSO pago por performer** — janela de **50 tokens / 30 dias + 15
  de carência** (`CHAT_ACCESS_COST`, `config/chat.php`). Cobrança é por **janela**, não por mensagem.
- **Paywall de leitura real:** em carência (grace) o **corpo é retido no servidor** (não é só UI);
  o membro sem janela vigente vê metadados/cadeado, nunca o texto. A performer sempre lê.
- **Máscara de opt-out:** enviar para uma linha de Interesse mascarada **parece sucesso e não
  entrega nada** (`INTEREST_ANONYMITY_FLOOR.md`) — testado.
- **Soft-delete LGPD** nas mensagens; unread por participante; preview da lista gateado igual ao `show()`.
- **Tempo real (Reverb):**
  - `MessageSent` → canal privado **`conversation.{id}`** (só metadados, o corpo nunca vai no
    broadcast — respeita o paywall). O `Show.vue` recebe o "ping" e busca o corpo pelo `show()`.
  - `NewMessage` → canal privado **`user.{id}`** de cada participante: atualiza **preview + badge +
    timestamp** da **lista** (`Chat/Index.vue`) em tempo real, sem reload; o preview do membro
    respeita o paywall (sem leitura → cadeado). Autorização em `routes/channels.php`
    (`conversation.{id}` = participantes; `user.{id}` = só o próprio).
  - Sem Reverb configurado o chat **degrada limpo**: histórico via Inertia, só sem push.
- **Frontend:** `Chat/Index.vue` (lista, tempo real), `Chat/Show.vue` (thread, timer de acesso,
  banner de expiração/renovação, scrollbar escura). `bootstrap.js` instancia o Echo só com
  `VITE_REVERB_APP_KEY` presente.
- **Rotas:** `chat` (index), `chat/{conversation}` (show), `chat/{conversation}/mensagens` (store),
  `chat/{conversation}/acesso` (open/renova acesso), `chat/interesse/{interest}/mensagem` (1ª msg da performer).

**Assinaturas / Círculos — Fase A completa (backend #44 + frontend #46) — usável**
- Billing recorrente por cartão (Asaas), **PAN nunca armazenado** (só `card_token` cifrado + last4
  + brand). Tabelas `circles` (5 tiers), `subscriptions` (uma ativa por usuário, índice único
  `active_lock`), `subscription_charges` (idempotência do grant mensal). `subscription_grant`
  credita a franquia mensal pelo ledger append-only, ancorado no id real da 1ª cobrança.
- **Frontend (PR #46):** telas `assinar` (`subscribe.index/store/cancel`), coleta de cartão,
  subscribe/cancel, Círculo ativo exposto ao Inertia (`auth.user.circle`). Middleware `circle:` +
  gate `circle-active`. ⚠️ tokenização do cartão ainda é **server-side** — mover p/ client-side é
  a **issue #47** (§5).

**Identidade e acesso**
- Cadastro/login/logout/me, verificação de e-mail, reset de senha, middleware de role, policies,
  audit log. Web (sessão) e API v1 (Sanctum) coexistem.
- **KYC** de performer (webhook Didit, resubmissão). Documentos **criptografados em repouso** no
  disco isolado `kyc` via `APP_KEY`. Driver **Fake**.
- Gate de idade (18+) e `SecurityHeaders` com HSTS condicional por ambiente.

**Descoberta e waitlist**
- Catálogo autenticado `/catalogo` + catálogo público `/performers` (OG server-side). **4 mundos**:
  mulheres, homens, casais, trans (SSOT `PerformerProfile::WORLDS`). Follows (membros ativos).
- Waitlist em 2 passos, double opt-in, Founding Members v3 (referrals, `/f/{invite_code}`,
  `/convite/{code}`), drip de 7 e-mails (⚠️ `WAITLIST_NURTURE_START_AT`, §4.4). Admin `/admin/waitlist`.

**Interesse Controlado (Sprint 3 — fechado)**
- Performer sinaliza a um seguidor; membro paga **15 tokens** (100% plataforma) para revelar quem é.
  Limite 5/dia, cooldown 30 dias/par, **opt-out silencioso**. O desbloqueio agora **abre o chat**.

### 1.3 O que **NÃO** existe (não reportar como bug)

Verificado por `grep` no código, não por memória:

- **Fase B dos Círculos** — Black e FC **não abrem** (regra: só após 5–10 performers Exclusive).
  `seat_limit` existe em `circles` mas **não é aplicado**; não há **`fc_numbers`** (pool 1–9999 +
  aposentadoria aos 6 meses), Halls, nem número BLACK.
- **Hold de tokens no ledger (Mementos)** — sem `entry_type` de reserva; hold de 800 tokens segue documental.
- **Cofre das FC Sessions** — ⛔ TRAVADO por decisão jurídica (§4.2). Nada de código.
- **Trial de 7 dias dos Founding Members** — "semana grátis de lançamento" travada no doc, **sem código**.
- **Tokenização de cartão client-side (PCI)** — hoje o cartão é tokenizado server-side; issue **#47**.
- **Feed, conteúdo pago destravável, streaming (LiveKit), score/tiers de performer** — não construídos.

---

## 2. PRs MERGEADOS DESDE O ÚLTIMO RETOMADA (#46 → #59 + commits diretos)

O RETOMADA anterior cobriu até #44. Desde então (o `gh` CLI não existe — §6.6 — PRs abertos pela URL do `push`):

| PR | Branch | O que entregou |
|---|---|---|
| **#46** | `feat/subscriptions-frontend` | **Frontend das assinaturas** — fecha o loop dos Círculos Fase A (telas subscribe/cancel, cartão, Círculo ativo) |
| **#48–#52** | `feat/new-logo`, `feat/logo-fix`, `fix/optimize-logo-images` | Marca: arco 3D dourado, wordmark, imagens de OG; `limen-icon.png` de 745KB→49KB |
| **#51/#53/#54** | `fix/thelimen-serve-images`, `fix/og-image-nginx` | nginx do portão `thelimen.com.br` passa a servir `/images/` e `/og-image.png` |
| **#55** | `feat/chat-reverb` | **Backend do chat interest-gated** (Reverb-ready): conversa no unlock, janela de acesso paga, eventos de broadcast |
| **#56** | `feat/chat-frontend` | **Frontend do chat** — Vue + Echo + Reverb, timer de acesso, banner de expiração |
| **#58** | `feat/add-reverb-dependency` | Adiciona `laravel/reverb` como **dependência de produção** + `config/reverb.php` (faltava: `reverb:start` não existia no servidor) |
| **#59** | `feat/chat-ui-fixes` | **Lista de conversas em tempo real** (canal `user.{id}` + `NewMessage`) + fix de scroll do `Show.vue` |

**Commits diretos na `main` (17/07):**
- `0876b6c` — `scripts/setup-reverb-server.sh` idempotente (provisiona o Reverb no servidor).
- `13fa09b` — `package-lock.json` com `laravel-echo`/`pusher-js`.
- `435d336` — **fix de deploy-ordering:** o `setup-reverb-server.sh` roda `queue:restart` **após**
  `config:cache` (workers precisam recarregar `BROADCAST_CONNECTION=reverb`, senão descartam broadcasts).
- `edbb1c1` — scrollbar escura na área de mensagens.
- `8c5a543` — `scripts/create-sprint5-issues.sh` (as 4 issues de follow-up do chat, §5).

> **#47 é uma ISSUE** (tokenização client-side PCI), não PR. **#57** não foi mergeado (o
> `feat/chat-frontend` teve dois pushes; o que entrou foi o #56).

---

## 3. DECISÕES DE PRODUTO (travadas)

### 3.1 Interesse Controlado + Chat
- 15 tokens de desbloqueio = **100% plataforma**; performer não creditada. Desbloqueio **abre o chat**.
- Chat: cobrança por **janela de acesso** (50t/30d + 15 grace), **não** por mensagem; performer manda
  grátis; assinante ativo tem chat livre. **Máscara de opt-out** vale no chat (sucesso vazio).
- **Broadcast não carrega o corpo** — o paywall de leitura é server-side.

### 3.2 Círculos / MAISON / Mementos (PR #39/#44)
> Docs: `CIRCLES_SYSTEM_V4.md`, `MAISON_PROGRAM.md`. **"Círculos", nunca "Planos".** Fase A é código
> (backend #44 + frontend #46); Black/FC/Mementos/Trial ainda são só spec (§1.3).

| Tier | Preço/mês | Vagas | Marca |
|---|---|---|---|
| Explorador | R$ 89,90 | ilimitadas | 75 tokens/mês, chat livre, badge prata |
| Insider | R$ 189,90 | ilimitadas | 200 tokens/mês, prioridade no Interesse |
| Prestige | R$ 389,90 | ilimitadas | 500 tokens/mês, 1 live privada, Discrição básico |
| Black | R$ 749,90 | **máx. 500** | 1.200 tokens/mês, Número BLACK, Exclusive/Maison |
| **Founders Circle** | R$ 1.490,00 | **convite, máx. 100** | escolhe o número FC (1–9999) |

- **Invariante:** assinatura **não substitui** tokens — reduz atrito/custo. Número FC aposenta aos
  6 meses; colecionáveis somem ao cancelar; endereço de entrega é PII (Locker). Black/FC só após 5–10 Exclusive.

### 3.3 4 mundos (implementada, PR #43)
mulheres, homens, casais, trans. GLS→homens, Swing→casais. SSOT `PerformerProfile::WORLDS`.

---

## 4. PENDÊNCIAS E DECISÕES EM ABERTO

### 4.1 ✅ Resolvidos desde o último RETOMADA
- **Chat/mensagens** — entregue e no ar (tempo real em staging). Era o benefício "oco" do Explorador
  e o pré-requisito da spec de Interesse.
- **Frontend das assinaturas** — entregue (PR #46); Círculos Fase A agora usável.
- **`laravel/reverb` como dependência** — faltava no composer; `reverb:start` não existia no servidor (PR #58).
- **Deploy-ordering do broadcast** — workers recarregam o driver reverb após `config:cache` (`435d336`).

### 4.2 🔴 Bloqueio jurídico — cofre das FC Sessions
`MAISON_PROGRAM.md` descreve gravação backend por 90 dias para investigação. **⛔ TRAVADO até
aprovação jurídica** — dado de vida sexual (art. 11, LGPD). Falta infra que não existe (D1–D6):
cripto de vídeo em streaming, roles p/ o Curador (hoje `consumer|performer|admin`), expurgo
verificável, audit log de **leitura**.

### 4.3 Follow-ups do chat (as 4 issues do `create-sprint5-issues.sh`)
1. **Idempotência não-permanente no `chat_access`** permite recobrança em replay tardio.
2. **Opt-out de Interesse não congela** conversa de chat já aberta.
3. **Broadcast entrega metadados a membro em grace/expired** (revisar o piso vs. o ping).
4. **Lançamento do ledger não referencia o `ChatAccess`** no primeiro open.

### 4.4 Outras pendências abertas
- **Drip de nurturing dispara em blast** se `WAITLIST_NURTURE_START_AT` não for setado na ativação.
- **Payout — follow-ups:** falta **alerta/requeue** para `needs_review`; revisar 429/408 no `createTransfer`.
- **Piso de anonimato do Interesse** — **não decidido**; decidir com dado (distribuição de follows/membro).
- **`unlock()` não revalida se a performer segue ativa** — decisão de produto.
- **Pseudônimos correlacionáveis** entre painel (`Fã #0042`) e seguidores (`Membro #42`).
- **Retenção/expurgo de KYC** — nunca feito; rotacionar `APP_KEY` quebra os `.enc`.
- **Integrações reais (Asaas/KYC) ainda em Fake** — pré-requisito de go-live.
- **`.env.example` induz a SQLite**, mas o projeto é MySQL (§6.4).

### 4.5 Afirmações de handoffs antigos que hoje são FALSAS
Não confie sem checar: contagens de teste antigas (**440** hoje), "limen.com.br" (produção é
**thelimen.com.br**), CLAUDE.md "PHP 8.5" (**8.4.22**) e "Próxima: Fase 8" (entregue). **Ainda
válidas:** ledger append-only; CPF só no checkout e PII isolada; `category` é o mundo (não criar
`world`); deploy por `reset --hard`; sudoers restrito; idempotência de pagamento por id de evento.

---

## 5. PRÓXIMO SPRINT (SPRINT 5) — O QUE ATACAR

Rodar primeiro **`scripts/create-sprint5-issues.sh`** (cria a milestone "Sprint 5" + as 4 issues do
chat §4.3). Ordem sugerida:

1. **Asaas/KYC fora do modo Fake** — **bloqueia o go-live**. Validar em sandbox, cuidar do
   `ASAAS_API_KEY` com `$` (§6.5), conferir webhooks idempotentes em produção.
2. **Issue #47 — tokenização de cartão client-side (PCI).** Hoje o PAN passa pelo servidor; mover
   p/ tokenização no cliente (Asaas) para tirar o cartão do escopo. **Passa pelo `security-reviewer`.**
3. **Fase B — Black/FC:** enforcement de `seat_limit` (vagas), **`fc_numbers`** (pool 1–9999 +
   aposentadoria aos 6 meses), Halls, número BLACK. Gate: 5–10 Exclusive antes de abrir.
4. **Payout — alerta/requeue de `needs_review`** (§4.4).
5. **Trial de 7 dias dos Founding Members** — semana grátis ao assinar qualquer Círculo no lançamento.
6. Follow-ups do chat (§4.3), à medida que doerem.
7. **Não** iniciar o cofre das FC Sessions (§4.2) — bloqueio jurídico.

---

## 6. INFRAESTRUTURA ATUAL

### 6.1 Servidor
- **Hetzner `limen-dev-01`**, IP **62.238.46.212**, Ubuntu 24.04.
- Projeto em `/var/www/limen`; nginx + `php8.4-fpm`; SSL Let's Encrypt (ECDSA) via Certbot.
- Usuários SSH: `deploy` e `root`. **Não rodar git como root lá** (deploys usam `sudo -u deploy`).

### 6.2 Domínios
- **`limen.dev.br`** — staging, ativo, **app completo** (chat em tempo real inclusive).
- **`thelimen.com.br`** — produção, **portão de marketing, NÃO o app**. O vhost só passa `/`,
  `/links`, `/interesse`, `/convite/`, `/f/`, `/waitlist/`; o resto redireciona. `/chat` e
  `/performers` **não** estão no allowlist — mesmo host e mesmo banco, mas o app logado não é servido lá.

### 6.3 CI/CD (`.github/workflows/deploy.yml`)
- Dispara em push/PR na `main`. **Testes:** `composer install` → `npm ci` → `npm run build` →
  `key:generate` → `php artisan test`. Não roda lint.
- **Deploy (SSH):** `reset --hard origin/main` → `composer install --no-dev` → `npm ci && build` →
  `migrate --force` → `config/route/view:cache` → restart do worker. **Rodar `artisan` como `deploy`**
  (senão os caches ficam com dono `root` e o próximo deploy quebra).
- **Armadilhas:** `composer install --no-dev` morre se `vendor/` tiver dono != `deploy`; o sudoers
  só libera **chown / supervisorctl / nginx** sem senha — **`sudo mkdir` não é permitido**.

### 6.4 Desenvolvimento local
- Docker: `limen-mysql` (3306), `limen-redis` (6379), `limen-adminer` (8080). App: `limen` /
  `limen_dev_pw`. Bancos: `limen` (dev), **`limen_test`** (testes).
- **Não há SQLite local.** `phpunit.xml` força `DB_CONNECTION=sqlite`; as vars de CLI vencem:
  ```bash
  DB_CONNECTION=mysql DB_DATABASE=limen_test DB_HOST=127.0.0.1 DB_PORT=3306 \
    DB_USERNAME=limen DB_PASSWORD=limen_dev_pw php artisan test
  ```
- **`npm` local:** node não valida o cert do registry (proxy TLS). Use
  `NODE_EXTRA_CA_CERTS=/etc/ssl/certs/ca-certificates.crt npm ...` (não desligar `strict-ssl`).
- **VM de trabalho:** VirtualBox na rede Verallia; Zscaler bloqueia `limen.dev.br` (não é bug).
  Túnel `:8443` — origem `:8443` ≠ `APP_URL :443` quebra POST do Inertia (logout).

### 6.5 Integrações
| Serviço | Estado |
|---|---|
| **Asaas / PIX + cartão** | `ASAAS_DRIVER=fake` (default e staging). Fase A usa `createSubscription` (CREDIT_CARD). Tirar do Fake é go-live (§5) |
| **KYC (Didit)** | Fake. Documentos cifrados em repouso no disco `kyc` via `APP_KEY` |
| **Resend (e-mail)** | Configurado; webhook em `/resend/webhook` |
| **LiveKit** | Não integrado |
| ⚠️ `ASAAS_API_KEY` | Começa com `$` — **aspas simples no `.env`**, senão vira variável e dá 401 |

### 6.6 Ferramental do agente
- **Não há `gh` CLI nem token:** PRs abertos manualmente pela URL do `push`.
- Subagente **`security-reviewer`** obrigatório antes de qualquer coisa sensível (cadastro, KYC,
  pagamento, payout, **cartão/PCI**, PII).
- **Toda rota nova usada no front entra em `config/ziggy.php`** (allowlist `only`), senão o Ziggy
  lança na montagem do Vue e o site fica preto.

### 6.7 Reverb / WebSocket (NOVO)
- **`laravel/reverb` rodando** no servidor via supervisor (`limen-reverb`, `reverb:start
  --host=0.0.0.0 --port=8080`). `BROADCAST_CONNECTION=reverb`; `REVERB_*` reais no `.env`;
  `VITE_REVERB_*` apontam p/ **wss/443** via proxy nginx.
- **nginx:** bloco `location /app/` **dentro** do server 443 faz proxy p/ `127.0.0.1:8080` com
  upgrade de WebSocket. Handshake confirmado (`GET /app/... 101` + `POST /broadcasting/auth 200`).
- **`scripts/setup-reverb-server.sh`** (idempotente) provisiona tudo pós-deploy: verifica que o
  `reverb:start` existe (aborta se não), liga `BROADCAST_CONNECTION=reverb` + `VITE_REVERB_*`
  (wss/443) no `.env` in-place, `config:cache`, escreve o programa do supervisor, insere o
  `location /app/` no bloco 443 (parser de chaves, valida com `nginx -t`), `npm run build`, e
  **`queue:restart`** (workers precisam recarregar o driver de broadcast — senão descartam eventos).
- **Gotcha permanente:** filas/broadcast são **Redis**. Um worker que sobe **antes** do
  `config:cache` com o driver novo fica com o broadcast antigo em memória e **descarta os eventos
  em silêncio** (mensagem persiste, mas não chega ao outro lado). Reiniciar `limen-worker` **depois**
  do `config:cache`.

---

## 7. ARRANQUE RÁPIDO PARA O PRÓXIMO CHAT

```bash
cd /home/robson/teste
git fetch origin && git checkout main && git reset --hard origin/main
docker ps                       # limen-mysql / limen-redis / limen-adminer no ar?
DB_CONNECTION=mysql DB_DATABASE=limen_test DB_HOST=127.0.0.1 DB_PORT=3306 \
  DB_USERNAME=limen DB_PASSWORD=limen_dev_pw php artisan test   # esperado: 440 verdes
```

Leia, nesta ordem: `CLAUDE.md` (princípios — ignore o "Estado atual", está velho) → este arquivo →
`CIRCLES_SYSTEM_V4.md` + `MAISON_PROGRAM.md` (monetização) → `INTEREST_SYSTEM_SPEC.md` +
`INTEREST_ANONYMITY_FLOOR.md` (Interesse + chat) → `COMMUNICATION_ECONOMY.md` (economia do chat).

**Primeira ação sugerida:** rodar `scripts/create-sprint5-issues.sh` e atacar **Asaas/KYC fora do
Fake** (§5.1, go-live) — depois **issue #47 (PCI client-side)**. Nada de cartão/PCI sem `security-reviewer`.
