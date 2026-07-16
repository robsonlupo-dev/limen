# LIMEN — RETOMADA EM CHAT NOVO

> **Gerado em:** 16/07/2026 · **Base:** `main` em `c7d9b24` (Merge PR #39) · **Suíte:** 380 testes verdes
> **Método:** escrito a partir da inspeção do código real (`git log`, `route:list`,
> `migrate:status`, leitura dos specs), não de memória. Onde o código contradiz um doc,
> o código venceu e a divergência está registrada.
>
> **Substitui a RETOMADA de 15/07** (base `a95226a` / PR #29, 344 testes). As mudanças
> materiais desde então: o **P0 de payout foi resolvido** (§4.1), o **Sprint 3 fechou**,
> a **operação de QA foi arquivada** e as **decisões de Círculos/MAISON foram travadas**
> (§3.2). Continua substituindo, para fins de retomada, os handoffs de 02/07
> (`CURRENT_ISSUES_AND_NEXT_ACTIONS.md`, `TECHNICAL_HANDOFF_MASTER.md`,
> `QA_HANDOFF_MASTER.md`), que descrevem um estado antigo e têm afirmações hoje falsas.

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

### 1.2 O que está implementado e funcionando

Tudo abaixo está na `main`, com teste.

**Fundação e dinheiro**
- **Ledger append-only** (`token_ledger`): saldo é sempre a soma das linhas; update/delete
  bloqueados e testados (princípio nº 2 do CLAUDE.md). Tipos de lançamento hoje:
  `purchase, bonus, refund, adjustment, spend_tip, tip_credit, spend_private, spend_camera,
  spend_interest_unlock, payout_reserve, payout_reversal, staging_seed_backfill`.
- **Compra de tokens via PIX/Asaas** com webhook idempotente por id de evento + reconciliação
  agendada. Driver **Fake** por padrão (`ASAAS_DRIVER=fake`).
- **Gorjetas** (`TipService`) com split por nível da performer, rate limit 10/min.
- **Payouts** (saque PIX da performer) — `/performer/payouts`. O hardening anti-pagamento-em-dobro
  **agora está na `main`** (§4.1); restam follow-ups menores (§4.4).

**Identidade e acesso**
- Cadastro/login/logout/me, verificação de e-mail, reset de senha, middleware de role,
  policies, audit log. Web (sessão) e API v1 (Sanctum) coexistem.
- **KYC** de performer (webhook Didit, resubmissão). Documentos **criptografados em repouso**
  no disco isolado `kyc` via `APP_KEY`. Driver **Fake**.
- Gate de idade (18+) e `SecurityHeaders` com HSTS condicional por ambiente.

**Descoberta**
- **Catálogo autenticado** `/catalogo` (por mundo) + **catálogo público** `/performers` com
  meta OG server-side (superfície de SEO, sem auth).
- **Follows** (restrito a membros ativos).
- Perfil de performer: onboarding e **edição pela performer ativa** (nome/bio/foto).

**Waitlist (a superfície pública de hoje)**
- Cadastro em 2 passos (membro/performer), double opt-in, Founding Members v3 com
  referrals/painel do fundador (`/f/{invite_code}`), convites (`/convite/{code}`).
- **Drip de nurturing:** 7 e-mails. ⚠️ exige `WAITLIST_NURTURE_START_AT` (§4.4).
- Descadastro com padrão GET-confirma / POST-executa (à prova de prefetch de mailbox).
- Admin: `/admin/waitlist`.

**Interesse Controlado (Sprint 3 — fechado)**
- Performer envia sinal binário a um seguidor; membro paga **15 tokens** (100% plataforma,
  performer não é creditada) para revelar quem é.
- Limite 5 envios/dia por performer, cooldown 30 dias por par, **opt-out silencioso** do membro.
- Painel do membro `/interesses` e `/painel`; **aba "Interesses enviados"** no painel da
  performer (PR #30). Envio a partir de `/performer/seguidores`, restrito a quem já segue.

### 1.3 O que **NÃO** existe (não reportar como bug)

Verificado por `grep` no código (`app/` + migrations), não por memória:

- **Assinaturas / Círculos** — `grep subscription|maison|memento` no `app/` volta **vazio**.
  As decisões de produto estão travadas nos docs (§3.2), o código **não existe**.
- **Limen Mementos / hold no ledger** — não implementado. Não há `entry_type` de reserva de
  Memento; o único "reserve" existente é `payout_reserve` (payouts). O hold de 800 tokens
  descrito na `MAISON_PROGRAM.md` é intenção documental (§4.3).
- **Chat / mensagens** — não construído. É pré-requisito da spec de Interesse (canal + 1ª
  mensagem grátis) **e** de vários Círculos (Explorador = "chat livre"). O desbloqueio hoje
  só revela identidade.
- **Cofre das FC Sessions** — ⛔ TRAVADO por decisão jurídica (§4.3). Nada de código.
- **Feed, conteúdo pago destravável, streaming (LiveKit), sistema de score/tiers de
  performer** — não construídos.

---

## 2. PRs MERGEADOS DESDE A RETOMADA ANTERIOR (#30 → #39)

Todos mergeados em **15/07/2026**. A RETOMADA anterior entrou como PR #32.

| PR | Branch | O que entregou |
|---|---|---|
| **#30** | `feat/performer-interests-tab` | Aba "Interesses enviados" no painel da performer — fecha o Sprint 3 |
| **#31** | `fix/unique-stage-name-index` | Índice único de `stage_name` no banco |
| **#32** | `docs/retomada-chat-novo` | A RETOMADA anterior (handoff de 15/07) |
| **#33** | `fix/payout-edge-cases` | Trata 408/429 como ambíguo e limita o lock do reconcile |
| **#34, #35** | `feat/qa-seeder-and-tests` | Seeder de QA (50 performers / 100 membros) + `QaOperationTest` (login suspenso, limites, unicode, paginação) |
| **#36** | `fix/payout-needs-review` | `needs_review` como porta de saída do reconcile para payouts irresolvíveis |
| **#37** | `docs/qa-operation-reports` | Arquiva playbook da operação de QA, charters dos agentes e relatórios |
| **#39** | `docs/circles-system-final` | **Trava as decisões dos Círculos** e aposenta `SUBSCRIPTION_TIERS.md`; adiciona cofre legal, Banca, elegibilidade/hold/mensagem-genérica dos Mementos |

> **Notas de numeração:**
> - **#38 é uma _issue_, não um PR** — é o bug do backup de KYC (o `backup.sh` não cobria
>   `storage/app/kyc`). Por isso o histórico salta de #37 para #39.
> - **#40 e #41 ainda não existem como PR.** São duas branches já empurradas neste ciclo,
>   aguardando abertura manual (não há `gh` CLI — §6.6):
>   - `fix/kyc-backup` — corrige a issue #38 (adiciona `storage/app/kyc` ao tarball + guarda
>     os diretórios com `mkdir -p`).
>   - `feat/tip-on-public-profile` — habilita gorjeta no perfil **público** para membro logado
>     (`role === 'consumer'`), reusando um `TipModal.vue` compartilhado com o catálogo.
>   Ambas com testes verdes e, no caso da de gorjeta, `security-reviewer` aprovado.

---

## 3. DECISÕES DE PRODUTO

### 3.1 Sprint 3 — Interesse Controlado (travadas)
- 15 tokens de desbloqueio = **100% plataforma**; a performer **não** é creditada.
- **Chat adiado explicitamente** — o desbloqueio revela identidade, não abre canal.
- Opt-out do membro é **silencioso**; desbloqueio é permanente e pago **uma vez por performer**.
- Segurança: `unlock()` trava todas as linhas do par (performer, membro) em leitura ordenada —
  sem isso, dois interesses da mesma performer cobravam 15 tokens em dobro.

### 3.2 Sistema de Círculos / MAISON / Mementos (travadas em 15/07, PR #39)

> Documentos de referência: **`CIRCLES_SYSTEM_V4.md`** e **`MAISON_PROGRAM.md`**.
> **"Círculos", nunca "Planos".** `SUBSCRIPTION_TIERS.md` está aposentado (superseded).
> **Nada disto está no código** — é o alicerce da próxima sprint de monetização.

**Os 4 Círculos + Founders Circle (5 tiers pagos):**

| Tier | Preço/mês | Vagas | Marca |
|---|---|---|---|
| Explorador | R$ 89,90 | ilimitadas | 75 tokens/mês, chat livre, badge prata |
| Insider | R$ 189,90 | ilimitadas | 200 tokens/mês, prioridade no Interesse, badge dourado |
| Prestige | R$ 389,90 | ilimitadas | 500 tokens/mês, 1 live privada, Modo Discrição básico |
| Black | R$ 749,90 | **máx. 500 globais** | 1.200 tokens/mês, Número BLACK (o membro não vê o nº), Exclusive/Maison |
| **Founders Circle** | R$ 1.490,00 | **por convite, máx. 100** | categoria à parte; o membro **escolhe** o número FC (1–9999) |

- **Invariante:** a assinatura **não substitui** tokens — tokens seguem sendo a moeda de toda
  interação (PPV, 1:1, gorjeta, mensagem sem assinatura). O Círculo reduz **atrito** e **custo**.
- **Número FC e aposentadoria:** o divisor é **6 meses de FC ativo** (o marco da Placa). Cancelou
  antes → número volta ao pool. Cancelou com 6+ meses → número **aposentado para sempre** e vira
  **FC Histórico** (número, badge histórico e "Fundador desde 2027" permanentes; benefícios de
  membro ativo suspensos).
- **Colecionáveis digitais somem ao cancelar** (decidido 15/07, sem exceção por tipo). O que
  **sobrevive**: badges de antiguidade, número/badge FC aposentado e objetos físicos já entregues.
- **Endereço de entrega dos marcos físicos é PII** (Locker/Caixa Postal, nunca residência):
  mesma regra do CPF/KYC — tabela isolada, criptografado, nunca em log/URL.
- **Hierarquia de performers (MAISON):** Verificada (20%) → Select (17%, score 70–84) →
  **Maison (12%, convite + Banca, máx. 50)**. A Banca ganha um **Conselho** (Robson + Bruno +
  Curador) a partir do 6º mês de operação.
- **Lançamento:** semana grátis para waitlist que assinar qualquer Círculo; **Black e FC só
  abrem** após 5–10 performers Exclusive cadastradas; tier abaixo de Explorador (R$ 39,90)
  **rejeitado**.

**Limen Mementos (Maison → membro FC) — regras já travadas, ainda sem código:**
- Custo logístico **fixo de 800 tokens**, 100% plataforma, **hold no ledger** disparado na
  **aprovação da foto** do item (não no envio); reprovada → libera; recebido no hub → débito.
- Verificação de saldo **antes** de a performer submeter a foto. Se bloqueado (saldo,
  toggle desativado ou limite), a performer vê **sempre a mesma mensagem genérica**
  *"Não é possível enviar para este membro no momento"* — mesma doutrina da máscara do opt-out
  do Interesse: motivos distinguíveis viram canal de vazamento.

---

## 4. PENDÊNCIAS E DECISÕES EM ABERTO

### 4.1 ✅ RESOLVIDO — hardening de payout contra pagamento em dobro
Era o P0 da RETOMADA anterior. **Está na `main` desde 15/07**: o reconcile só move dinheiro em
estado terminal explícito, estado ambíguo (timeout/408/429) **não estorna**, e o `needs_review`
virou a porta de saída para payouts irresolvíveis (#33, #36). O merge do
`fix/payout-double-pay-hardening` também entrou. Não é mais bloqueador.

### 4.2 🔴 Bloqueio jurídico — cofre das FC Sessions
`MAISON_PROGRAM.md` descreve gravação backend das FC Sessions em cofre por 90 dias para uso
**exclusivamente** em investigação/denúncia. **⛔ TRAVADO: não implementar até aprovação
jurídica.** É dado de vida sexual (art. 11, LGPD) retido sem transparência nos termos; a base
legal não é consentimento (o titular revoga, e o cofre precisa sobreviver à revogação). Além
disso, falta infraestrutura que **não existe hoje** (D1–D6):
- criptografia de vídeo em **streaming** (o `Crypt`/`APP_KEY` do KYC carrega o arquivo inteiro
  em memória — serve para JPEG, não para live de horas);
- **modelo de roles** que comporte o Curador (hoje `consumer|performer|admin`: dar acesso ao
  Curador = dar `admin` = dar KYC + waitlist + cofre de brinde — gap estrutural);
- **expurgo automático verificável** (não há retenção em lugar nenhum; o backup guarda cópias
  que o dia 90 não alcança);
- **audit log de leitura** (o audit atual cobre escrita; no cofre a leitura é o evento crítico).

### 4.3 Assinaturas / Mementos ainda não implementados
Todas as regras de §3.2 são documentais. A sprint de monetização precisa, no mínimo, de:
- máquina de assinatura (cobrança recorrente Asaas, estados de Círculo, franquia mensal de
  tokens, descontos por tier);
- **hold/reserva no ledger** para os 800 tokens do Memento (novo `entry_type`, ex. `memento_hold`,
  seguindo o padrão append-only — reservar não é `UPDATE saldo`);
- **chat**, que é benefício de Círculo (Explorador = chat livre) e pré-requisito do Interesse.

### 4.4 Outras pendências abertas
- **Drip de nurturing dispara em blast** se `WAITLIST_NURTURE_START_AT` não for setado na ativação.
  Copy final e halt pós-launch seguem como follow-up.
- **Payout — follow-ups menores** que sobraram do hardening: falta **alerta/requeue** para
  `needs_review` (o reconcile para de tentar após 2h de buscas vazias, e o prazo conta de
  `unresolved_since`); revisar se algum caminho de `createTransfer` com 429/408 ainda pode estornar.
- **Piso de anonimato do Interesse** (`INTEREST_ANONYMITY_FLOOR.md`) — **ainda não decidido**.
  Como o envio é restrito a seguidores, o conjunto de candidatos ao remetente é a lista de follows
  do membro (1 follow → acerta sem pagar). Decidir com dado (distribuição de follows por membro).
- **`unlock()` não revalida se a performer segue ativa** — membro pode gastar 15 tokens para
  revelar performer desativada depois do envio. Decisão de produto.
- **Pseudônimos correlacionáveis:** painel mostra `Fã #0042` (`id % 10000`) e a lista de
  seguidores mostra `Membro #42` (id cru). O mesmo membro é correlacionável entre telas. Unificar.
- **Retenção/expurgo de documentos de KYC** — follow-up nunca feito. Rotacionar `APP_KEY` quebra
  a decodificação dos `.enc`.
- **Integrações reais (Asaas/KYC) ainda em Fake** — pré-requisito de go-live.
- **`.env.example` induz a SQLite**, mas o projeto é MySQL. `php artisan test` sem sobrescrever
  falha com "could not find driver" (§6.4).

### 4.5 Contradições entre specs
- ✅ **"Círculos vs Planos" — RESOLVIDO.** `SUBSCRIPTION_TIERS.md` foi aposentado; a nomenclatura
  e a hierarquia oficiais são as de `CIRCLES_SYSTEM_V4.md`.
- ✅ **Régua de marcos físicos — RESOLVIDO (16/07).** Seção de marcos físicos removida do
  `MAISON_PROGRAM.md`. A régua (Carta 1 mês, Placa 6 meses, Chave 12 meses) vive exclusivamente em
  `CIRCLES_SYSTEM_V4.md`. Não havia conteúdo de membro FC no doc de performers.
- ⚠️ **4 mundos vs 6 categorias — ainda em aberto.** `WORLDS_ARCHITECTURE.md` diz 4 mundos; o banco
  tem 6 (`enum('mulheres','homens','casais','trans','gls','swing')`). `CatalogController`/
  `RegisterWebRequest` aceitam 6; `PublicCatalogController` aceita 4 → `/performers?mundo=gls`
  devolve 422 embora performers `gls`/`swing` apareçam no catálogo sem filtro. Decidir.

### 4.6 Afirmações de handoffs antigos que hoje são FALSAS
Não confie em `CURRENT_ISSUES_AND_NEXT_ACTIONS.md` / `TECHNICAL_HANDOFF_MASTER.md` sem checar:
"HSTS será restaurado pelo deploy" (já é condicional no código), "173/344 testes" (**380** hoje),
"domínio limen.com.br" (produção é **thelimen.com.br**), CLAUDE.md "PHP 8.5" (**8.4.22**) e
"Próxima: Fase 8" (entregue há tempos). **Ainda válidas:** ledger append-only; CPF só no checkout
e PII isolada; `category` é o mundo (não criar coluna `world`); deploy por `reset --hard`; sudoers
restrito; idempotência de pagamento por id de evento; stack só muda com o PO.

---

## 5. PRÓXIMO SPRINT — O QUE ATACAR

Ordem sugerida, do barato/risco para a feature.

1. **Abrir os PRs pendentes deste ciclo** (§2): `fix/kyc-backup` (corrige a issue #38 de backup
   de KYC) e `feat/tip-on-public-profile` (gorjeta no perfil público). Ambos prontos e testados.
2. **Fechar os follow-ups de payout** (§4.4): alerta/requeue de `needs_review` e a revisão do
   caminho 429/408. É dinheiro — vem antes de feature.
3. **Decidir a contradição de spec restante do §4.5**: 4-vs-6 mundos (a régua de marcos físicos
   já foi resolvida em 16/07). É decisão de PO e bloqueia trabalho abaixo.
4. **Decidir o piso de anonimato** (§4.4) com o dado de follows por membro.
5. **Iniciar a sprint de monetização (Círculos)** — agora destravada pela nomenclatura oficial,
   mas com três dependências reais (§4.3):
   - **chat/mensagens** (benefício de Círculo e pré-requisito do Interesse); ⚠️ ler o aviso de
     `INTEREST_ANONYMITY_FLOOR.md` — enviar a uma linha mascarada tem de parecer sucesso e não
     entregar nada, senão o opt-out vaza;
   - **máquina de assinatura** (recorrência Asaas, estados de Círculo, franquia/descontos);
   - **hold no ledger** para os 800 tokens do Memento (novo `entry_type`, append-only).
6. **Não** iniciar o cofre das FC Sessions (§4.2) — bloqueio jurídico, não de engenharia.

---

## 6. INFRAESTRUTURA ATUAL

### 6.1 Servidor
- **Hetzner `limen-dev-01`**, IP **62.238.46.212**, Ubuntu 24.04.
- Projeto em `/var/www/limen`; nginx + `php8.4-fpm`; SSL Let's Encrypt (ECDSA) via Certbot.
- Usuários SSH: `deploy` e `root`. **Não rodar git como root lá.**

### 6.2 Domínios
- **`limen.dev.br`** — staging, ativo, app completo.
- **`thelimen.com.br`** — produção. **Portão de marketing, não o app.** O vhost
  (`deploy/nginx/thelimen.com.br`) só deixa passar `/`, `/links`, `/interesse`, `/convite/`,
  `/f/`, `/waitlist/`; o resto redireciona para `/`. Handler PHP `internal` (não dá para furar
  o allowlist com `/index.php/catalogo`). Está **HTTP-only** no repo; rodar certbot **só depois**
  do DNS apontar. ⚠️ `/performers` (catálogo público de SEO) **não está no allowlist** — decidir
  se entra no pré-lançamento.

### 6.3 CI/CD (`.github/workflows/deploy.yml`)
- Dispara em push/PR na `main`.
- **Testes:** `composer install` → `npm ci` → `npm run build` → `key:generate` → `php artisan test`
  (MySQL de serviço). **Não roda lint** (não há `pint.json`) — siga o estilo do arquivo vizinho.
- **Deploy (SSH):** `git fetch` + `reset --hard origin/main` → `composer install --no-dev` →
  `npm ci && npm run build` → `migrate --force` → `config/route/view:cache` → restart do worker.
- **Armadilhas conhecidas:** `composer install --no-dev` morre se algo em `vendor/` ficar com dono
  != `deploy`; o sudoers (`/etc/sudoers.d/deploy-limen`) só libera **chown / supervisorctl / nginx**
  sem senha — **`sudo mkdir` não é permitido** (já quebrou o deploy do Sprint 2). Ajustar o passo,
  não ampliar o sudoers.

### 6.4 Desenvolvimento local
- Docker: `limen-mysql` (3306), `limen-redis` (6379), `limen-adminer` (8080). App: `limen` /
  `limen_dev_pw`. Bancos: `limen` (dev), **`limen_test`** (usar este nos testes p/ não zerar o dev).
- **Não há SQLite local.** `phpunit.xml` força `DB_CONNECTION=sqlite` e as vars de CLI vencem:
  ```bash
  DB_CONNECTION=mysql DB_DATABASE=limen_test DB_HOST=127.0.0.1 DB_PORT=3306 \
    DB_USERNAME=limen DB_PASSWORD=limen_dev_pw php artisan test
  ```
- **VM de trabalho:** VirtualBox Ubuntu na rede Verallia. Zscaler bloqueia `limen.dev.br` (domínio
  novo + adulto) — **não é bug do site**. Acesso por túnel SSH `:8443`. ⚠️ Origem `:8443` ≠
  `APP_URL :443` quebra POST do Inertia (logout); o backend está OK.

### 6.5 Integrações
| Serviço | Estado |
|---|---|
| **Asaas / PIX** | `ASAAS_DRIVER=fake` (default e staging). Bootar produção com `fake` lança por design |
| **KYC (Didit)** | Fake. Documentos cifrados em repouso no disco `kyc` via `APP_KEY` |
| **Resend (e-mail)** | Configurado; webhook em `/resend/webhook` |
| **LiveKit** | Não integrado |
| ⚠️ `ASAAS_API_KEY` | Começa com `$` — **precisa de aspas simples no `.env`**, senão o shell interpola e dá 401 |

### 6.6 Ferramental do agente
- **Não há `gh` CLI nem token:** não dá para abrir PR por código. O `push` devolve a URL
  `pull/new/...` para o PO abrir no navegador.
- Subagente **`security-reviewer`** é obrigatório antes de qualquer coisa sensível (cadastro, KYC,
  pagamento, payout, PII) — CLAUDE.md.
- **Toda rota nova usada no front precisa entrar em `config/ziggy.php`** (allowlist `only`).
  Esquecer = Ziggy lança na montagem do Vue e **o site inteiro fica em tela preta**.
- **Backup** (`docs/backup.sh`): dump MySQL + tar de storage, tudo cifrado por GPG. ⚠️ até a
  issue #38 ser mergeada, o tarball **não cobre `storage/app/kyc`** (só `storage/app/private`) —
  ver a branch `fix/kyc-backup`.

---

## 7. ARRANQUE RÁPIDO PARA O PRÓXIMO CHAT

```bash
cd /home/robson/teste
git fetch origin && git checkout main && git reset --hard origin/main
docker ps                       # limen-mysql / limen-redis / limen-adminer no ar?
DB_CONNECTION=mysql DB_DATABASE=limen_test DB_HOST=127.0.0.1 DB_PORT=3306 \
  DB_USERNAME=limen DB_PASSWORD=limen_dev_pw php artisan test   # esperado: 380 verdes
```

Leia, nesta ordem: `CLAUDE.md` (princípios — ignore o "Estado atual", está velho) → este arquivo →
`CIRCLES_SYSTEM_V4.md` + `MAISON_PROGRAM.md` (as decisões travadas da próxima sprint) →
`INTEREST_SYSTEM_SPEC.md` + `INTEREST_ANONYMITY_FLOOR.md` (a sprint entregue e a decisão pendente).

**Primeiras ações sugeridas:** abrir os PRs de `fix/kyc-backup` e `feat/tip-on-public-profile`
(§2, §5.1), depois fechar os follow-ups de payout (§4.4). Nenhuma feature de monetização antes de
decidir as contradições de spec (§4.5) e sem chat + hold no ledger (§4.3).
