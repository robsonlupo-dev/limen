# LIMEN — RETOMADA EM CHAT NOVO

> **Gerado em:** 15/07/2026 · **Base:** `main` em `a95226a` (Merge PR #29) · **Suíte:** 344 testes verdes
> **Método:** este documento foi escrito a partir da inspeção do código real (`git log`,
> `route:list`, `migrate:status`, leitura dos specs), não de memória. Onde o código contradiz
> um doc antigo, o código venceu e a divergência está registrada.
>
> **Substitui, para fins de retomada:** `CURRENT_ISSUES_AND_NEXT_ACTIONS.md` (02/07),
> `TECHNICAL_HANDOFF_MASTER.md` (02/07) e `QA_HANDOFF_MASTER.md` (02/07). Aqueles três
> descrevem um estado de duas semanas atrás e **têm afirmações hoje falsas** — ver §4.6.

---

## 1. ESTADO ATUAL DO PRODUTO

Limen é uma plataforma premium de conteúdo adulto verificado para o mercado brasileiro.
Hoje ela está **pré-lançamento**: o produto logado funciona ponta a ponta em staging, e o
domínio público serve apenas a captação de waitlist.

### 1.1 Stack real (verificada, não a do CLAUDE.md)

| Item | Real | Observação |
|---|---|---|
| PHP | **8.4.22** | `composer.json` exige `^8.3`. O CLAUDE.md diz "PHP 8.5" — **está errado** |
| Laravel | **13.17.0** | |
| Front | Inertia 3.1 + Vue 3 + Tailwind v4 + Ziggy 2.6 | |
| Banco | MySQL 8.4 (Docker) | Redis para cache/filas |
| Mail | Resend (`resend/resend-laravel` 1.4) | |
| Auth | Sanctum 4.3 (API) + **sessão/CSRF (web)** | o frontend usa rotas **web**, não Sanctum |

### 1.2 O que está implementado e funcionando

Tudo abaixo está na `main`, com teste. **91 rotas** registradas (`php artisan route:list`).

**Fundação e dinheiro**
- **Ledger append-only** (`token_ledger`): saldo é sempre a soma das linhas. Update/delete
  bloqueados e testados. É o princípio nº 2 do CLAUDE.md e está de pé.
- **Compra de tokens via PIX/Asaas** com webhook idempotente por id de evento + reconciliação
  agendada. Driver **Fake** por padrão (`ASAAS_DRIVER=fake`).
- **Gorjetas** (`TipService`) com split por nível da performer, rate limit 10/min.
- **Payouts** (saque PIX da performer) — `/performer/payouts`. ⚠️ ver §4.1, o hardening
  anti-pagamento-em-dobro **não está na main**.

**Identidade e acesso**
- Cadastro/login/logout/me, verificação de e-mail, reset de senha, middleware de role,
  policies, audit log. Web (sessão) e API v1 (Sanctum) coexistem.
- **KYC** de performer (webhook Didit, resubmissão). Documentos **criptografados em repouso**
  no disco isolado `kyc` via `APP_KEY`. Driver **Fake**.
- Gate de idade (18+) e `SecurityHeaders` com **HSTS condicional por ambiente**.

**Descoberta**
- **Catálogo autenticado** `/catalogo` (por mundo) + **catálogo público** `/performers` com
  meta OG renderizada no servidor (superfície de SEO, sem auth).
- **Follows** (restrito a membros ativos).
- Perfil de performer: onboarding e **edição pela performer ativa** (nome/bio/foto — PR #29).

**Waitlist (a superfície pública de hoje)**
- Cadastro em 2 passos (membro/performer), double opt-in, Founding Members v3 com
  referrals/painel do fundador (`/f/{invite_code}`), convites (`/convite/{code}`).
- **Drip de nurturing:** 7 e-mails. ⚠️ ver §4.4 — exige `WAITLIST_NURTURE_START_AT`.
- Descadastro com padrão GET-confirma / POST-executa (à prova de prefetch de mailbox).
- Admin: `/admin/waitlist`.

**Interesse Controlado (Sprint 3 — a entrega mais recente)**
- Performer envia sinal binário a um seguidor; membro paga **15 tokens** (100% plataforma,
  performer não é creditada) para revelar quem é.
- Limite 5 envios/dia por performer, cooldown 30 dias por par, **opt-out silencioso** do membro.
- Painel do membro `/interesses` (caixa, desbloqueio, opt-out) e `/painel` (dashboard do membro).
- Envio a partir de `/performer/seguidores`, restrito a quem já segue (fecha o oráculo de
  enumeração de membros).

### 1.3 O que **NÃO** existe (não reportar como bug)

Verificado por `grep` no código, não por memória:

- **Chat / mensagens** — não construído. É pré-requisito declarado da spec de Interesse (§5:
  "canal de conversa", "1ª mensagem grátis"), e essa parte **não existe**. O desbloqueio hoje
  só revela identidade.
- **Assinaturas / Círculos** — `grep` por `subscription` e `circle` no `app/` e nas migrations
  volta **vazio**. Os specs existem; o código, não.
- **Feed, conteúdo pago destravável, streaming (LiveKit)** — não construídos.
- **Sistema de tiers de performer** — não existe. O "5 envios/dia" é o piso via config.

---

## 2. PRs MERGEADOS DESDE O ÚLTIMO HANDOFF (#16 → #29)

O handoff anterior é de 02/07. Tudo abaixo entrou depois, entre 13 e 14/07.

| PR | Data | Branch | O que entregou |
|---|---|---|---|
| #16, #17 | 13/07 | `feat/links-page` | Página pública `/links` (link-in-bio) + og-image PNG + handle X corrigido |
| #18 | 13/07 | `feat/resend-mailer` | Resend como transporte de e-mail |
| #19 | 13/07 | `feat/seo-titles` | Convenção de título `<página> · Limen`; meta OG do catálogo no servidor |
| #20, #21 | 13/07 | `feat/waitlist-2-steps` | Waitlist em 2 passos (membro/performer) + spec §8 com decisões do PO |
| #22, #23 | 13–14/07 | `feat/waitlist-nurturing` | Drip de 7 e-mails de nurturing + cobertura |
| #24 | 14/07 | `feat/interest-system` | **Interesse Controlado** — backend + testes (ledger, idempotência, corridas) |
| #25 | 14/07 | `feat/interest-ui-and-payouts` | UI do interesse + payouts no painel; lista de seguidores só com membros ativos |
| #27 | 14/07 | `feat/member-dashboard` | Dashboard do membro em `/painel` |
| #28 | 14/07 | `docs/interest-anonymity-floor` | Documenta o piso de anonimato como **decisão em aberto** |
| #29 | 14/07 | `feat/performer-profile-edit` | Performer ativa edita nome artístico, bio e foto |

**Lacuna: o PR #26 não aparece no histórico da `main`.** Os merges saltam de #25 para #27.
Foi fechado sem merge ou nunca existiu — sem o `gh` CLI não dá para confirmar daqui. Se ele
continha algo que você julga entregue, **verifique**.

---

## 3. SPRINTS CONCLUÍDOS

> Aviso de nomenclatura: o projeto tem **duas numerações sobrepostas** — "Fases 0–12" (o
> vocabulário do CLAUDE.md e dos handoffs antigos) e "Sprints 1–3" (o vocabulário recente).
> Elas não se alinham. Abaixo, o que o registro do git de fato mostra.

### Fases 0–12 (fundação, até ~02/07)
Fundação do repo/Docker → modelo de dados + `TokenService` → auth (Sanctum, verificação,
reset, roles, policies, audit log) → tokens + Asaas/PIX (webhook idempotente, reconciliação)
→ perfis, catálogo e follows → KYC (Didit, cripto em repouso) → gorjetas (split, ledger) →
frontend Inertia/Vue/Tailwind (design system, gate de idade, Ziggy) → catálogo visual →
FIXes de UX (Fase 12).

⚠️ **O CLAUDE.md está desatualizado aqui.** Ele diz "Fase 7 … Próxima: Fase 8 (catálogo de
performers no frontend)". A Fase 8 está entregue (`Performers/Index.vue`,
`PublicCatalogController`, `docs/fase8-catalogo-visual.md`) e o projeto está muito além disso.

### Sprint 1 (crescimento / waitlist)
Waitlist, Founding Members v3, referrals, painel do fundador. Fechamento exigiu passos
manuais de servidor (`ASAAS_DRIVER=fake` no staging, `performers:backfill-avatars`, sudoers
do vendor).

### Sprint 2 (superfície pública)
`/links`, Resend, títulos de SEO, meta OG server-side, waitlist em 2 passos, drip de nurturing.
O deploy do S2 quebrou por sudoers (`sudo mkdir` não é permitido) — ver §6.

### Sprint 3 (Interesse Controlado) — **o último**
Modelo escolhido: performer sinaliza (sem texto), membro paga 15 tokens para revelar.
Entregue: backend + testes (#24), UI + payouts (#25), dashboard do membro (#27), doc do piso
de anonimato (#28), edição de perfil (#29).

**Decisões do PO travadas no Sprint 3:**
- 15 tokens de desbloqueio = **100% plataforma**; a performer **não** é creditada.
- **Chat adiado explicitamente** — o desbloqueio revela identidade, não abre canal.
- Opt-out do membro é **silencioso** (a performer não pode perceber).
- Desbloqueio é permanente e pago **uma vez por performer**.

**Segurança do Sprint 3 (corridas corrigidas):** `unlock()` trava todas as linhas do par
(performer, membro) numa leitura ordenada — sem isso, dois interesses da mesma performer
cobravam 15 tokens duas vezes. `send()` serializa na linha de `performer_profiles`.

**O último item do Sprint 3 está pronto mas não mergeado:** aba "Interesses enviados"
(`feat/performer-interests-tab`) — ver §4.2.

---

## 4. PENDÊNCIAS E DECISÕES EM ABERTO

### 4.1 🔴 P0 — Hardening de payout contra pagamento em dobro NÃO está na `main`

O achado mais sério desta inspeção. A branch **`fix/payout-double-pay-hardening`**
(commit `26cb784`, "Fase 0 · Etapa 7") existe no remoto e **nunca foi mergeada**.
Confirmado: `grep TRANSFER_DONE app/` na `main` volta **vazio**.

Ela contém (404 linhas, 11 arquivos):
- `PayoutService` — reconcile só move dinheiro em **estado terminal explícito**; estado
  ambíguo (timeout/erro de rede) **nunca** estorna;
- nome real do evento do webhook: **`TRANSFER_DONE`**, não `PAID`;
- `AsaasUnavailableException` / `AsaasRequestException` e `ReconcilePayouts` endurecido;
- +131 linhas de teste em `PayoutTest.php`.

Ou seja: **a `main` de hoje tem o payout na versão sem esse endurecimento**, e o webhook de
transferência escuta o nome de evento errado. Isso é dinheiro real saindo. Trate como o
primeiro item do próximo sprint: revisar, rebasear na `main` e mergear.

### 4.2 Trabalho pronto aguardando PR

| Branch | Estado | Ação |
|---|---|---|
| `feat/performer-interests-tab` | Aba "Interesses enviados"; 353 testes verdes; revisão de segurança feita | Abrir PR |
| `fix/unique-stage-name-index` | Índice único de `stage_name`; 344 verdes; migração verificada em `migrate:fresh --seed` | Abrir PR |
| `fix/payout-double-pay-hardening` | Ver §4.1 | Revisar e mergear — **prioridade** |
| `qa/pre-prod-operation` | **9 commits, 1851 linhas**: seeder de QA (50 performers/100 membros), `QaOperationTest`, script de carga k6, relatórios de QA/segurança/UX/growth e veredito de go-live | Decidir: mergear ou descartar |
| `feat/performer-profile-edit` | Só o commit `4a2d46f`, já replicado em `fix/unique-stage-name-index` | Pode ser apagada após o merge |

> **Como o `4a2d46f` ficou órfão:** o PR #29 mergeou em `8cbd934`, mas o commit seguinte da
> branch (o índice único de `stage_name`) ficou para trás. Foi resgatado por cherry-pick em
> `fix/unique-stage-name-index`. Lição: conferir se a branch avançou depois de abrir o PR.

### 4.3 Decisão de produto em aberto: piso de anonimato do Interesse

Documentada em `INTEREST_ANONYMITY_FLOOR.md` (PR #28) e **ainda não decidida**.

Como o envio é restrito a seguidores, o conjunto de candidatos ao remetente **é** a lista de
follows do membro. Com 1 follow, o membro acerta o remetente sem pagar (100%); com 2, 50%.
Isso morde o membro novo — justamente quem se quer converter.

Opções: (1) piso de N follows; (2) reabrir a não-seguidores com resposta uniforme;
(3) ruído na contagem; (4) aceitar e documentar.

**A sugestão do doc é decidir com dado**, medindo a distribuição de follows por membro:
```sql
SELECT follows_por_membro, COUNT(*) AS membros FROM (
    SELECT user_id, COUNT(*) AS follows_por_membro FROM follows GROUP BY user_id
) t GROUP BY follows_por_membro ORDER BY follows_por_membro;
```
Mediana alta → opção 4 é defensável. Mediana 1–2 → opção 1 é necessária.

### 4.4 Outras pendências abertas

- **Drip de nurturing dispara em blast** se `WAITLIST_NURTURE_START_AT` não for setado na
  ativação. Copy final e halt pós-launch continuam como follow-up.
- **Tabela de limite diário por tier** (spec do Interesse §9) — o sistema de tiers não existe;
  5/dia é o piso via `config/interest.php`.
- **`unlock()` não revalida se a performer segue ativa** — o membro pode gastar 15 tokens para
  revelar uma performer desativada depois do envio. Decisão de produto, não corrigido.
- **Pseudônimos conflitantes:** o painel mostra gorjetas como `Fã #0042` (`id % 10000`) e a
  lista de seguidores mostra `Membro #42` (id cru). O mesmo membro é correlacionável entre as
  duas telas, o que anula o mascaramento. Convém unificar.
- **Retenção/expurgo de documentos de KYC** — follow-up nunca feito. Rotacionar a `APP_KEY`
  quebra a decodificação dos `.enc`.
- **Integrações reais (Asaas/KYC) ainda em Fake** — pré-requisito de go-live.
- **`.env.example` induz a SQLite** (`DB_CONNECTION=sqlite`), mas o projeto é MySQL. Rodar
  `php artisan test` sem sobrescrever falha com "could not find driver". Ver §6.4.

### 4.5 Contradições entre specs (nenhuma decidida)

Achadas ao ler os docs. **Nenhuma das duas está no código**, então ainda não custaram nada —
mas o próximo sprint de monetização vai bater de frente com elas.

1. **"Círculos" vs "Planos".** `CIRCLES_SYSTEM_V4.md` abre com *"Nomenclatura oficial: usamos
   **Círculos**, nunca 'Planos'"* e define **5 Círculos** (Explorador R$ 89,90/mês …).
   `SUBSCRIPTION_TIERS.md` define **4 planos** (FREE/SELECT/BLACK/PRESTIGE) e é o que
   `INTEREST_SYSTEM_SPEC.md` §5 e `COMMUNICATION_ECONOMY.md` referenciam. **Os dois modelos
   coexistem nos docs e se contradizem.** Decidir antes de implementar assinatura.

2. **4 mundos vs 6 categorias.** `WORLDS_ARCHITECTURE.md` diz **4 mundos** (Mulheres, Homens,
   Trans, Casais). O banco tem **6**: `enum('mulheres','homens','casais','trans','gls','swing')`.
   Isso já vazou para o código de forma inconsistente:
   - `CatalogController` (autenticado), `RegisterWebRequest`, `UserPreferencesController`: **6**;
   - `PublicCatalogController`: **4** (`'mundo' => 'nullable|in:mulheres,homens,casais,trans'`).

   **Efeito real:** o seeder cria performers de `gls` e `swing` (18 no banco de dev). Elas
   **aparecem** no catálogo público sem filtro, mas `/performers?mundo=gls` devolve **422**.
   Ou o doc está desatualizado, ou o catálogo público está errado. Decidir.

### 4.6 Afirmações dos handoffs antigos que hoje são FALSAS

Não confie em `CURRENT_ISSUES_AND_NEXT_ACTIONS.md` sem checar. Verificado:

| Afirmação antiga | Realidade em 15/07 |
|---|---|
| "P0 — HSTS de 1 ano será restaurado pelo deploy" | **Resolvido.** `SecurityHeaders.php` já é condicional: `production` → 1 ano + preload; resto → `max-age=300` |
| "173 testes verdes" | **344** na `main` |
| "Domínios: limen.com.br (produção futura)" | O domínio de produção é **thelimen.com.br** (o vhost existe no repo) |
| "Fases 1–12 entregues … próximo: QA + FIXes" | Vieram depois 3 sprints (waitlist, superfície pública, Interesse Controlado) |
| CLAUDE.md: "PHP 8.5" | **PHP 8.4.22**; `composer.json` pede `^8.3` |
| CLAUDE.md: "Próxima: Fase 8" | Fase 8 entregue há tempos |

**Ainda válidas** (não mexer): ledger append-only; CPF só no checkout e PII isolada;
`category` é o mundo (não criar coluna `world`); `/cadastro` reutilizada; catálogo autenticado
é auth-gated; deploy por `reset --hard` (não editar arquivo no servidor); sudoers restrito;
idempotência de pagamento por id de evento; stack Inertia/Vue/Tailwind só muda com o PO.

---

## 5. PRÓXIMO SPRINT — O QUE ATACAR

Ordem sugerida, do risco para a feature.

1. **Mergear o hardening de payout (§4.1).** É dinheiro saindo com o webhook escutando o
   evento errado. Revisar `fix/payout-double-pay-hardening`, rebasear, rodar
   `security-reviewer`, mergear. **Nada de feature nova antes disso.**
2. **Fechar o Sprint 3.** Abrir os PRs de `feat/performer-interests-tab` e
   `fix/unique-stage-name-index` (§4.2).
3. **Decidir o destino do `qa/pre-prod-operation`** (9 commits de seeder/carga/relatórios
   apodrecendo). Mergear ou apagar — deixar assim é o pior dos mundos.
4. **Decidir o piso de anonimato com o SQL do §4.3.** É barato e destrava a política do
   Interesse.
5. **Resolver as contradições de spec do §4.5** — "Círculos vs Planos" e "4 vs 6 mundos".
   Ambas são decisão de PO e bloqueiam a sprint de monetização.
6. **Aí sim, feature.** As duas candidatas naturais, ambas destravadas pelo Sprint 3:
   - **Chat/mensagens** — é o pré-requisito que falta para o Interesse Controlado cumprir a
     própria spec (canal + 1ª mensagem grátis). ⚠️ Ler antes o aviso no fim de
     `INTEREST_ANONYMITY_FLOOR.md`: enviar mensagem para uma linha mascarada tem de parecer
     bem-sucedido e não entregar nada, senão o opt-out vaza no envio.
   - **Assinaturas/Círculos** — bloqueada pelo §4.5.1 até o PO decidir a nomenclatura.

---

## 6. INFRAESTRUTURA ATUAL

### 6.1 Servidor
- **Hetzner `limen-dev-01`**, IP **62.238.46.212**, Ubuntu 24.04.
- Projeto em `/var/www/limen`; nginx + `php8.4-fpm`; SSL Let's Encrypt (ECDSA) via Certbot.
- Usuários SSH: `deploy` e `root`. **Não rodar git como root lá.**

### 6.2 Domínios
- **`limen.dev.br`** — staging, ativo, app completo.
- **`thelimen.com.br`** — produção. **É um portão de marketing, não o app.** O vhost
  (`deploy/nginx/thelimen.com.br`) só deixa passar `/`, `/links`, `/interesse`, `/convite/`,
  `/f/`, `/waitlist/`; todo o resto redireciona para `/`. O handler PHP é `internal`, então
  não dá para furar o allowlist com `/index.php/catalogo`.
  - **Estado:** o vhost está **HTTP-only (porta 80)** no repo. Rodar o certbot **só depois** do
    DNS apontar para o box, senão o desafio ACME falha. Instalação manual (o arquivo não é
    aplicado pelo deploy) — os comandos estão no cabeçalho do próprio vhost.
  - ⚠️ `/performers` (catálogo público, superfície de SEO) **não está no allowlist** — no
    domínio público ele redireciona para `/`. Intencional no pré-lançamento? Decidir.

### 6.3 CI/CD (`.github/workflows/deploy.yml`)
- Dispara em **push/PR na `main`**.
- **Testes:** `composer install` → `npm ci` → `npm run build` → `key:generate` →
  `php artisan test` (MySQL de serviço). **Não roda lint** — não há `pint.json` e o código
  mergeado reprova no Pint padrão; siga o estilo do arquivo vizinho.
- **Deploy (SSH):** `git fetch` + `reset --hard origin/main` → `composer install --no-dev` →
  `npm ci && npm run build` → `migrate --force` → `config/route/view:cache` → restart do worker.
- **Secrets:** `HETZNER_HOST`, `HETZNER_SSH_KEY`.
- **Armadilhas conhecidas do deploy:**
  - `composer install --no-dev` morre se algum arquivo em `vendor/` ficar com dono != `deploy`;
  - o sudoers (`/etc/sudoers.d/deploy-limen`) só libera **chown / supervisorctl / nginx** sem
    senha — **`sudo mkdir` não é permitido** e já quebrou o deploy do Sprint 2. Não ampliar o
    sudoers; ajustar o passo.

### 6.4 Desenvolvimento local
- Docker: `limen-mysql` (3306), `limen-redis` (6379), `limen-adminer` (8080).
  Root do MySQL: `root_dev_pw`; app: `limen` / `limen_dev_pw`. Bancos: `limen` (dev),
  `limen_test`, `limen_growth`.
- **Não há SQLite local.** `phpunit.xml` força `DB_CONNECTION=sqlite` e as vars de CLI vencem,
  então o comando que funciona é:
  ```bash
  DB_CONNECTION=mysql DB_DATABASE=limen_test DB_USERNAME=limen DB_PASSWORD=limen_dev_pw \
    php artisan test
  ```
- **VM de trabalho:** VirtualBox Ubuntu dentro da rede Verallia. O Zscaler bloqueia
  `limen.dev.br` (categoria de domínio novo + conteúdo adulto) — **não é bug do site**.
  Acesso por túnel SSH `:8443`. ⚠️ Origem `:8443` ≠ `APP_URL :443` quebra POST do Inertia
  (logout); o backend está OK.

### 6.5 Integrações
| Serviço | Estado |
|---|---|
| **Asaas / PIX** | `ASAAS_DRIVER=fake` (default e no staging). `config/asaas.php` aceita `http`. Bootar produção com `fake` lança por design |
| **KYC (Didit)** | Fake. Documentos cifrados em repouso no disco `kyc` via `APP_KEY` |
| **Resend (e-mail)** | Configurado; webhook em `/resend/webhook` |
| **LiveKit** | Não integrado |
| ⚠️ `ASAAS_API_KEY` | Começa com `$` — **precisa de aspas simples no `.env`**, senão o shell interpola, vira vazia e dá 401 |

### 6.6 Ferramental do agente
- **Não há `gh` CLI nem token** no ambiente: **não dá para abrir PR por código**. O `push`
  devolve a URL `pull/new/...` para o PO abrir no navegador.
- Subagente **`security-reviewer`** é obrigatório antes de qualquer coisa sensível (cadastro,
  KYC, pagamento, payout, PII) — CLAUDE.md.
- **Toda rota nova usada no front precisa entrar em `config/ziggy.php`** (allowlist `only`).
  Esquecer = o Ziggy lança na montagem do Vue e **o site inteiro fica em tela preta**. É o bug
  histórico de referência do projeto.

---

## 7. ARRANQUE RÁPIDO PARA O PRÓXIMO CHAT

```bash
cd /home/robson/teste
git fetch origin && git checkout main && git reset --hard origin/main
docker ps                       # limen-mysql / limen-redis / limen-adminer no ar?
DB_CONNECTION=mysql DB_DATABASE=limen_test DB_USERNAME=limen DB_PASSWORD=limen_dev_pw \
  php artisan test              # esperado: 344 verdes
```

Leia, nesta ordem: `CLAUDE.md` (princípios — mas ignore o "Estado atual", está velho) →
este arquivo → `INTEREST_SYSTEM_SPEC.md` + `INTEREST_ANONYMITY_FLOOR.md` (a sprint mais
recente e a decisão pendente).

**A primeira coisa a fazer é o §4.1** (payout sem hardening na `main`). Não é feature: é
dinheiro.
