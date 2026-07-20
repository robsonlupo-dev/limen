<!-- Vocabulário: "Fase N" neste doc é LEGADO (ciclo da fundação) e NÃO
     corresponde ao "Sprint N" atual. Ex.: Fase 4 = perfis/catálogo;
     Sprint 4 = chat. O ciclo de entrega vigente é "Sprint N" — ver CLAUDE.md. -->

# LIMEN — MASTER HANDOFF (Sprint 5)

> **Gerado em:** 18/07/2026 · **Base:** `main` em `edbb1c1` (+ RETOMADA `ee3a1b6`)
> **Suíte:** 440 testes verdes · 2066 asserts (`php artisan test`)
> **Método:** escrito a partir da inspeção do código real — `git log`, `route:list`,
> `migrate:status`, `php artisan test` e todos os docs em `docs/`. Onde um doc contradiz
> o código, **o código venceu** e a divergência está registrada.
>
> **Este arquivo é auto-contido.** Um Claude novo que leia só ele consegue continuar o
> projeto sem perguntar nada. Ele consolida `RETOMADA-CHAT-NOVO.md`, `CIRCLES_SYSTEM_V4.md`,
> `MAISON_PROGRAM.md`, `COMMUNICATION_ECONOMY.md`, `INTEREST_ANONYMITY_FLOOR.md`,
> `INTEREST_SYSTEM_SPEC.md`, `WORLDS_ARCHITECTURE.md`, `WAITLIST_SPEC.md` e os relatórios de
> QA em `docs/qa/`. Onde precisar do detalhe fino, o doc-fonte está citado.

---

## Índice

1. Visão executiva
2. Status atual (por área)
3. Arquitetura técnica
4. DNS e domínios
5. Decisões de produto
6. Founding Members (V1/V2/V3)
7. Filas de fundadores (membros e performers)
8. Interest Controlled System
9. Monetização
10. Círculos (assinaturas) — V4
11. Maison Program
12. Marketing
13. Growth OS
14. Captação de performers
15. Emails
16. Roadmap (Sprint 1→5)
17. Sprint 5 — plano detalhado (comece aqui)
18. Riscos
19. Dívidas técnicas
20. Checklist operacional

---

## 1. VISÃO EXECUTIVA

**O que é o Limen.** Plataforma premium de conteúdo adulto **verificado** para o mercado
brasileiro. Une descoberta de performers, economia de tokens, interações pagas (gorjeta,
chat, no futuro lives/videochamada) e um programa de fidelidade por assinatura (os
**Círculos**) com camada colecionável e físico-digital (**Maison**, **Founders Circle**).

**Problema que resolve.** O mercado atual (câmeras/assinatura) é de baixa curadoria, exposição
ampla e pouca proteção — tanto da performer (spam, assédio, investidas frias) quanto do membro
(rastro público, sem discrição). O Limen inverte o fluxo: **a performer sinaliza interesse** e o
membro paga para revelar/abrir canal (Interesse Controlado), com **anonimato desenhado** e
**modo discrição** por tier. O eixo é qualidade e escassez, não volume.

**Posicionamento.** Premium e discreto. Nunca "mais barato": um tier abaixo do Explorador (R$
39,90) foi **rejeitado** de propósito (§5). Referências de mercado como inspiração — não cópia —
foram meupatrocinio, cameraprive, chaturbate; o Limen se diferencia por curadoria (Maison),
verificação dos dois lados (18+/KYC) e economia de tokens append-only auditável.

**Visão de longo prazo.** Um clube fechado de conteúdo adulto com hierarquia de performers
(Verificada → Select → Maison), colecionáveis físicos numerados (Carta/Placa/Chave dos
Fundadores), sessões exclusivas (FC Sessions) e presentes físicos curados (Limen Mementos).
Receita recorrente (Círculos) sobre uma base transacional (tokens).

**Público-alvo.**
- **Membros:** homens e mulheres 18+, ticket médio-alto, que valorizam discrição e conexão
  curada. Segmentados por **4 mundos**: Mulheres, Homens, Trans, Casais (§ WORLDS).
- **Performers:** criadoras/es verificadas/os que buscam menor exposição, split transparente e
  um caminho de status (Select/Maison). Casais entram como uma unidade (os dois KYC + contrato).

**Modelo de negócio.**
- **Transacional (base):** venda de **pacotes de tokens** (PIX/Asaas); tokens pagam gorjeta,
  acesso ao chat, PPV, lives, videochamada. Plataforma retém split por interação (padrão 20%,
  reduzido por tier de performer e por tipo de interação).
- **Recorrente (topo):** **Círculos** (Explorador R$ 89,90 → Black R$ 749,90 + Founders Circle
  R$ 1.490 por convite). Assinatura **não substitui** tokens — reduz atrito (chat livre) e custo
  (desconto em tokens). Regra dura, não negociável.
- **Invariante financeiro:** todo movimento de token é uma linha nova no `token_ledger`
  **append-only**; saldo é a soma. Nunca `UPDATE saldo = saldo + x` (erro do projeto anterior).

---

## 2. STATUS ATUAL (por área)

Legenda: ✅ no ar/com teste · 🟡 parcial ou só spec · 🔴 não existe / bloqueado.

| Área | Estado | Nota |
|---|---|---|
| **Infra** | ✅ | Hetzner `limen-dev-01` (62.238.46.212), nginx + php8.4-fpm + MySQL/Redis Docker, Reverb via supervisor, CI/CD GitHub Actions |
| **Backend** | ✅ | Laravel 13, PHP 8.4.22, 101 rotas, 440 testes verdes |
| **Frontend** | ✅ | Inertia + Vue 3.5 + Tailwind v4 + Ziggy; rotas **web** (sessão/CSRF), não Sanctum |
| **Banco** | ✅ | MySQL 8.4, **48 migrations** (todas `Ran` na `main`; a DB de dev local pode estar atrás — rode `migrate`) |
| **Wallet** | ✅ | `/wallet` + histórico + pendentes; saldo derivado do ledger |
| **Ledger** | ✅ | `token_ledger` append-only; update/delete bloqueados e testados; ~14 `entry_type` |
| **Asaas** | 🟡 | Código completo (cobrança PIX + assinatura cartão + webhook idempotente + reconcile), mas **`ASAAS_DRIVER=fake`** — sair do Fake é **go-live** (P0) |
| **Waitlist** | ✅ | 2 passos, double opt-in, admin `/admin/waitlist`, drip de 7 emails (⚠️ `WAITLIST_NURTURE_START_AT`) |
| **Founding Members** | 🟡 | **V3 no ar** (referrals, `/f/{invite}`, `/convite/{code}`); trial de 7 dias e filas físicas ainda spec |
| **Marketing** | 🟡 | Portão `thelimen.com.br` no ar (waitlist + links + OG); marca (logo arco 3D dourado) entregue; campanhas = manual |
| **Growth** | 🟡 | `GROWTH_STRATEGY.md` (50 melhorias priorizadas) — recomendações, poucas implementadas |
| **Growth OS** | 🟡 | `growth_os/` (Python, MySQL `limen_growth`): Lead Scout escrito; Custom Search API **bloqueada** p/ projeto novo (403) |
| **Discovery** | ✅ | Catálogo autenticado `/catalogo` + público `/performers`, 4 mundos, follows |
| **Monetização** | 🟡 | Gorjeta ✅ (inclusive do perfil público), chat pago ✅, Círculos Fase A ✅; PPV/lives/videochamada 🔴 |
| **Chat / mensagens** | ✅ | **Interest-gated, tempo real (Reverb), no ar em staging** — Sprint 4 |
| **Interesse Controlado** | ✅ | Sprint 3 fechado; o desbloqueio abre o chat |
| **KYC** | 🟡 | Fluxo Didit completo, docs cifrados em repouso; **driver Fake** — sair do Fake é go-live |
| **Payouts** | ✅ | Saque PIX `/performer/payouts`; hardening anti-double-pay na main; falta alerta/requeue `needs_review` |
| **Círculos Fase B (Black/FC)** | 🔴 | `seat_limit`, `fc_numbers`, Halls, número BLACK **não existem** — só após 5–10 Exclusive |
| **Mementos / FC Sessions vault** | 🔴 | Mementos = documental (sem hold no ledger); vault ⛔ **travado por jurídico** |
| **Feed / PPV / LiveKit / tiers de performer** | 🔴 | Não construídos |

---

## 3. ARQUITETURA TÉCNICA

### Stack real (verificada, não a do CLAUDE.md)

| Item | Real | Observação |
|---|---|---|
| PHP | **8.4.22** | `composer.json` exige `^8.3`. CLAUDE.md diz "PHP 8.5" — **errado** |
| Laravel | **13** (`^13.8`) | |
| Front | Inertia (`^3.1`) + Vue 3 (`^3.5`) + Tailwind v4 + Ziggy (`^2.6`) | rotas **web** (sessão/CSRF) |
| Tempo real | **Reverb `^1.10`** + `laravel-echo ^2.1` + `pusher-js ^8.4` | WebSocket do chat |
| Banco | MySQL 8.4 (Docker) | Redis p/ cache/**filas/sessão/broadcast** |
| Mail | Resend (`resend/resend-laravel`) | webhook em `/resend/webhook` |
| Auth | Sanctum (API v1) **+ sessão/CSRF (web)** | as duas superfícies coexistem |
| Testes | Pest 4 | 440 verdes / 2066 asserts |

### Banco de dados (48 migrations)
Núcleo: `users` (+ role `consumer|performer|admin`, `interests_opt_out`, `preferred_world`,
`asaas_customer_id`), `performer_profiles` (slug, stage_name único, `category` = o mundo),
`identity_verifications` (KYC, campos nullable), `token_wallets`, **`token_ledger`**,
`token_packages` (+bonus), `payments`, `payment_events`, `audit_logs`, `follows`, `tips`,
`payouts` (+`needs_review`), `waitlist_entries` (+FM v3), `waitlist_referrals`,
`waitlist_email_log`, `performer_interests` (+`suppressed`), **`circles`** (5 tiers),
**`subscriptions`** (índice único `active_lock`), **`subscription_charges`**,
**`conversations`**, **`messages`** (soft-delete), **`chat_access`**.

### Wallet + Ledger append-only (INVARIANTE Nº 1 do projeto)
- **Saldo = soma das linhas de `token_ledger`.** NUNCA `UPDATE ... saldo = saldo + x`.
  update/delete estão **bloqueados e testados**.
- `entry_type` hoje: `purchase, bonus, refund, adjustment, spend_tip, tip_credit, spend_private,
  spend_camera, spend_interest_unlock, payout_reserve, payout_reversal, staging_seed_backfill,
  subscription_grant, spend_chat_access, chat_access_credit`.
- Cada movimento persiste split/retenção **no momento do gasto**. Tokens são **inteiros** (nunca float).
- `TokenService` centraliza débito/crédito atômico (transação + lock); ver `token-ledger-rules` skill.

### Tokens (moeda)
Pacotes a preço cheio (Básico 100/R$9,90 · Médio 300/R$27,90 · Grande 600/R$52,90 · Baleia
1500/R$119,90). O **desconto do Círculo ativo** incide **sobre o preço**, nunca sobre a quantidade
de tokens. Assinante compra os mesmos tokens mais barato.

### Asaas (pagamento)
- **PIX** (compra de tokens): `createPayment` → webhook `PAYMENT_RECEIVED` **idempotente por id de
  evento** → crédito no ledger. Reconciliação agendada (`payments:reconcile`).
- **Cartão** (Círculos Fase A): `createSubscription` (CREDIT_CARD), **PAN nunca armazenado** — só
  `card_token` cifrado + last4 + brand. ⚠️ tokenização hoje é **server-side** → **issue #47** (P0).
- `subscription_grant` credita a franquia mensal pelo ledger, ancorado no id da 1ª cobrança
  (`subscription_charges` = idempotência do grant).
- ⚠️ `ASAAS_API_KEY` começa com `$` — **aspas simples no `.env`**, senão o shell expande e dá 401.
- Ver skill `asaas-pix-integration`.

### Scheduler / Workers
- Jobs agendados: `payments:reconcile`, `payouts:reconcile`, drip de waitlist,
  `chat:purge-expired-access` (soft-delete de mensagens após 45 dias sem renovar).
- Filas em **Redis**. `BROADCAST_CONNECTION=reverb`.
- **Gotcha permanente:** um worker que sobe **antes** do `config:cache` com o driver de broadcast
  novo fica com o driver antigo em memória e **descarta eventos em silêncio** (mensagem persiste,
  mas não chega). Sempre `queue:restart` **depois** do `config:cache`.

### GitHub Actions (CI/CD — `.github/workflows/deploy.yml`)
- Dispara em push/PR na `main`. **Testes:** `composer install` → `npm ci` → `npm run build` →
  `key:generate` → `php artisan test`. Não roda lint.
- **Deploy (SSH):** `reset --hard origin/main` → `composer install --no-dev` → `npm ci && build` →
  `migrate --force` → `config/route/view:cache` → restart worker. **Rodar `artisan` como `deploy`**
  (senão os caches ficam com dono `root` e o próximo deploy quebra).
- **Armadilhas:** `composer install --no-dev` morre se `vendor/` tiver dono ≠ `deploy`; o sudoers
  só libera **chown / supervisorctl / nginx** sem senha — **`sudo mkdir` NÃO é permitido**.

### Deploy / Hetzner
- **`limen-dev-01`**, IP **62.238.46.212**, Ubuntu 24.04. Projeto em `/var/www/limen`;
  nginx + `php8.4-fpm`; SSL Let's Encrypt (ECDSA) via Certbot.
- SSH: `deploy` e `root`. **Não rodar git como root lá** (deploys usam `sudo -u deploy`).
- **Reverb:** supervisor `limen-reverb` (`reverb:start --host=0.0.0.0 --port=8080`); nginx faz proxy
  `location /app/` no server 443 → `127.0.0.1:8080` com upgrade WebSocket (handshake `101` +
  `POST /broadcasting/auth 200`). `scripts/setup-reverb-server.sh` (idempotente) provisiona tudo
  pós-deploy e **aborta** se `reverb:start` não existir.

---

## 4. DNS E DOMÍNIOS

| Domínio | Papel | Detalhe |
|---|---|---|
| **`limen.dev.br`** | **Staging — app completo** | Chat em tempo real inclusive. Ativo. |
| **`thelimen.com.br`** | **Produção — portão de MARKETING, NÃO o app** | Mesmo host, mesmo banco, mesmo `/var/www/limen` |

**Decisão-chave:** `thelimen.com.br` e o staging são o **mesmo servidor e o mesmo banco**. O vhost
público só passa `/`, `/links`, `/interesse`, `/convite/`, `/f/`, `/waitlist/`; o resto redireciona.
`/chat` e `/performers` **não** estão no allowlist do portão — o app logado existe, mas **não é
servido** no domínio público. Consequência prática (memória do PO): se `thelimen.com.br` parecer
"desatualizado", suspeite de **opcache/CDN**, não de código — é o mesmo box.

- `limen.dev.br` NÃO é o mesmo que o antigo "limen.com.br" de handoffs velhos (produção é
  **thelimen.com.br**). Ignore qualquer doc que diga "limen.com.br".
- **VM de trabalho:** VirtualBox na rede Verallia; **Zscaler bloqueia `limen.dev.br`** (não é bug).
  Túnel `:8443` — origem `:8443` ≠ `APP_URL :443` quebra POST do Inertia (logout).

---

## 5. DECISÕES DE PRODUTO (travadas — com motivo e impacto)

Todas abaixo estão **aprovadas** salvo marcação em contrário.

- **Age Gate (18+).** Gate de idade obrigatório antes do conteúdo; verificação KYC dos **dois
  lados** (performer por Didit, membro por CPF onde a interação exige). *Motivo:* fundação legal
  (prevenção de conteúdo ilegal), não feature. *Impacto:* PII isolada e cifrada em repouso.
- **Waitlist antes do go-live.** Captação em 2 passos + double opt-in rodando **agora** no portão
  público. *Motivo:* construir base antes de abrir. *Impacto:* drip de 7 emails de nurturing.
- **Founding Members V3.** Referrals com invite code (`/f/{invite_code}`, `/convite/{code}`) no ar.
  *Motivo:* crescimento viral pré-launch. *Impacto:* filas de fundadores (§6, §7).
- **Discovery = 4 mundos.** Mulheres, Homens, Trans, Casais (SSOT `PerformerProfile::WORLDS`).
  GLS→Homens, Swing→Casais. *Motivo:* segmentação de público sem multiplicar catálogos. *Impacto:*
  `category` **é** o mundo — **nunca criar coluna `world`**.
- **Messaging = interest-gated.** A conversa **nasce no desbloqueio do Interesse**; não há endpoint
  de membro iniciando chat frio. Performer manda a 1ª grátis. *Motivo:* eliminar spam/assédio.
  *Impacto:* toda a economia do chat (§8, §9).
- **Chat: cobrança por JANELA, não por mensagem.** 50 tokens = 30 dias + 15 de carência.
  *Motivo:* previsibilidade e menos atrito. *Impacto:* assinante ativo tem chat livre; a máscara de
  opt-out vale no chat.
- **Paywall de leitura server-side.** Em carência o **corpo é retido no servidor** (não é só UI); o
  broadcast **nunca** carrega o corpo. *Motivo:* o gate tem que ser real. *Impacto:* `MessageSent`
  só transmite metadados; o `Show.vue` busca o corpo pelo `show()`.
- **Wallet/Ledger append-only.** Saldo derivado; nunca update de saldo; idempotência de pagamento
  por id de evento. *Motivo:* erro recorrente do projeto anterior (double credit). *Impacto:*
  invariante nº 2 e nº 3 do CLAUDE.md — inviolável.
- **Tokens = moeda universal.** Assinatura reduz atrito/custo, **não substitui** tokens. *Motivo:*
  proteger a receita transacional. *Impacto:* PPV, lives, gorjetas, mensagens de não-assinante =
  sempre token.
- **Performer Journey.** Cadastro → onboarding (foto+perfil) → KYC (Didit) → catálogo → recebe
  gorjeta/chat/payout. Hierarquia Verificada → Select → Maison (§11).
- **Member Journey.** Waitlist → cadastro → verificar email → catálogo/4 mundos → follow → recebe
  Interesse → desbloqueia (15 tokens) → chat. Compra tokens por PIX; opcionalmente assina um Círculo.
- **"Círculos", nunca "Planos".** Nomenclatura oficial (§10). `SUBSCRIPTION_TIERS.md` (nomes
  SELECT/BLACK/PRESTIGE) está **superseded** desde 15/07.
- **Semana grátis de lançamento (trial 7 dias):** aprovada em doc, **sem código** (P2, §17).
- **Tier abaixo do Explorador (R$ 39,90): REJEITADO.** *Motivo:* preservar posicionamento premium.
- **Black e FC não abrem no Dia 1.** Só após **5–10 performers Exclusive** — sem elas o benefício
  central é vazio. *Impacto:* Fase B fica gateada (§10, §17).
- **Mimo Real (phygital):** adiado para depois do lançamento, quando houver base Black.
- **Cofre das FC Sessions:** ⛔ **TRAVADO por decisão jurídica** (§18) — nenhum código.

---

## 6. FOUNDING MEMBERS (V1 / V2 / V3)

O programa de fundadores evoluiu em três versões (migrations `2026_07_10/11`):

- **V1 — captura simples.** Waitlist recebe uma flag de "founding member". Sem viralização. Servia
  só para marcar quem entrou cedo.
- **V2 — filas e benefícios.** Introduz a ideia de **fila de fundadores** com benefícios por
  posição, mas ainda sem mecânica de convite/indicação (referrals).
- **V3 — referrals (APROVADO E NO AR).** Cada inscrito ganha um **invite code**; indicações sobem a
  posição na fila. Rotas: **`/f/{invite_code}`** (painel do fundador) e **`/convite/{code}`** (landing
  de convite). Tabelas `waitlist_referrals` + colunas FM v3 em `waitlist_entries`. Admin em
  `/admin/waitlist`.

**Rejeitado/aprovado.** Rejeitado: dar tokens reais antes do go-live (não há produto para gastar).
Aprovado: posição na fila + selo de fundador + prioridade de acesso; o **benefício monetário**
(semana grátis ao assinar qualquer Círculo) fica para o lançamento (§17, P2). O detalhe fino do
comportamento da waitlist (estados, double opt-in, drip) está em **`WAITLIST_SPEC.md`**.

---

## 7. FILAS DE FUNDADORES (membros e performers)

Duas filas separadas, ambas gerenciadas pela waitlist em 2 passos (membro vs. performer —
migration `2026_07_13_000001_waitlist_two_step_member_performer`):

**Fila de membros fundadores.**
- Ordenada por posição (indicações do V3 sobem posição).
- Benefício de lançamento: **semana grátis** ao assinar **qualquer Círculo** (trial de 7 dias — P2,
  ainda sem código).
- Selo "Coleção Fundadores 2027" para membros ativos em 2027 (colecionável digital — some ao
  cancelar, §10).

**Fila de performers fundadoras.**
- Alimenta a captação (§14): performers que se inscrevem cedo entram numa fila de convite/onboarding.
- Ligada à hierarquia Maison (§11): a Banca observa candidatas; as primeiras Exclusive destravam a
  **Fase B (Black/FC)** dos Círculos.

**Tiers/benefícios da Fase B (spec, não implementado):** número BLACK (sequencial, permanente,
membro não vê o número), número FC (1–9999, o membro **escolhe**, aposenta aos 6 meses), Halls
opt-in (Hall of Black / Hall FC), marcos físicos progressivos (Carta 1 mês / Placa 6 meses / Chave
12 meses). Detalhe completo em §10 e `CIRCLES_SYSTEM_V4.md`.

---

## 8. INTEREST CONTROLLED SYSTEM

**Motivação.** Inverter o fluxo tradicional (membro aborda performer) para **performer sinaliza,
membro paga para revelar**. Cria escassez, protege a performer de spam e **monetiza a descoberta**.

**Alternativas descartadas (`INTEREST_SYSTEM_SPEC.md` §2):**

| Modelo | Descartado porque |
|---|---|
| Performer manda **mensagem livre** | Vetor de spam/assédio; alto custo de moderação |
| Membro paga para **enviar** interesse | Inverte o incentivo; performer vira alvo de investida paga |
| **Match mútuo grátis** (tipo dating) | Sem monetização; enche a caixa da performer |

**Modelo aprovado (no ar, Sprint 3).** A performer envia um sinal **binário** (sem texto/foto). O
membro vê "alguém demonstrou interesse" e, ao **desbloquear pagando 15 tokens** (100% plataforma,
performer **não** creditada), descobre **quem** — e o desbloqueio **abre o chat**. Parâmetros:
custo 15 tokens (1×/performer), limite 5 envios/dia por performer (sobe por tier), cooldown 30
dias/par, **opt-out silencioso** do membro. Débito **sempre** via ledger, idempotente.

**Comparação — por que não mensagem direta nem match mútuo.** Mensagem direta reintroduz o spam
que o modelo existe para matar; match mútuo remove a monetização e devolve o ruído à caixa da
performer. O interesse binário + paywall de reveal é o ponto que preserva escassez **e** receita.

**Piso de anonimato (DECISÃO EM ABERTO — `INTEREST_ANONYMITY_FLOOR.md`).** Para fechar um oráculo
de enumeração de membros, o envio ficou restrito a **quem já segue** a performer. Efeito colateral:
todo interesse vem de alguém que o membro já segue, então com poucos follows ele adivinha o
remetente **sem pagar**:

| Follows | Chance de acertar sem pagar |
|---|---|
| 1 | 100% |
| 3 | 33% |
| 10 | 10% |

Morde o membro novo (poucos follows) — justamente quem se quer converter. **Opções:** (1) piso por
tamanho do conjunto (só entregar a membros com ≥N follows); (2) reabrir a não-seguidores com
resposta uniforme; (3) ruído na contagem; (4) aceitar e documentar. **Sugestão: decidir com dado**
— medir a distribuição de follows/membro antes de escolher (SQL no doc). É a tarefa **P2** "piso de
anonimato" do Sprint 5 (§17). **Máscara de opt-out:** enviar para linha mascarada **parece sucesso
e não entrega nada** — vale no Interesse e no chat (testado); quebrar isso **vaza o opt-out**.

---

## 9. MONETIZAÇÃO

Fonte canônica: `COMMUNICATION_ECONOMY.md`. Estado: ✅ implementado · 🟡 spec.

| Mecanismo | Custo (membro sem Círculo) | Retenção plataforma | Estado |
|---|---|---|---|
| **Gorjeta (tip)** | livre, mín. 5 tokens | **20%** | ✅ (rate limit 10/min; enviável do perfil público) |
| **Acesso ao chat** | **50 tokens / janela de 30 dias** (+15 grace) | split por nível da performer (como gorjeta) | ✅ (assinante = grátis) |
| **Desbloqueio de Interesse** | **15 tokens** | **100% plataforma** | ✅ (gratuito p/ Círculo ativo) |
| **Assinatura (Círculo)** | R$ 89,90 → 1.490 | recorrente | ✅ Fase A (Explorador→Prestige) / 🟡 Black,FC |
| **Conteúdo pago (PPV)** | preço da performer | 20% | 🔴 não construído |
| **Live pública** | grátis (gorjeta + goals + PPV ao vivo) | 20% | 🔴 (LiveKit não integrado) |
| **Live privada** | por duração (10/20/30 min), pré-paga | **15%** (reduzida) | 🔴 |
| **Videochamada 1:1** | live privada +30%, **CPF dos dois lados** | 20% | 🔴 |
| **Assinatura de performer individual** | preço da performer | 20% | 🔴 |
| **Mimo recorrente** (gorjeta mensal) | valor do membro | 20% | 🔴 |

**Regras transversais.** 20% é a retenção padrão; **live privada 15%** (incentivar o formato de
maior valor); Maison 12%, Select 17% (§11). Split calculado **no gasto** e persistido no ledger.
Estornos explícitos e idempotentes (ex.: live privada não aceita em 2 min devolve integral).

**Aprovado vs. pendente.** *Aprovado e no ar:* gorjeta, chat pago, Interesse, Círculos Fase A.
*Aprovado, sem código:* PPV, lives, videochamada, assinatura individual, mimo recorrente, Fase B.

---

## 10. CÍRCULOS (ASSINATURAS) — V4

Fonte canônica: `CIRCLES_SYSTEM_V4.md`. **"Círculos", nunca "Planos".** Fase A (Explorador→Prestige +
tabelas) é **código** (backend PR #44 + frontend PR #46); Black/FC/Mementos/Trial ainda são **spec**.

### Os 5 tiers pagos

| Círculo | Preço/mês | Vagas | Tokens/mês | Desconto tokens | Marca |
|---|---|---|---|---|---|
| **Explorador** | R$ 89,90 | ilimitadas | 75 | 10% | chat livre, badge prata |
| **Insider** | R$ 189,90 | ilimitadas | 200 | 20% | prioridade no Interesse, badge dourado, Bastidores |
| **Prestige** | R$ 389,90 | ilimitadas | 500 | 30% | 1 live privada 20min/mês, Discrição básico, Sala Prestige |
| **Black** | R$ 749,90 | **máx. 500 globais** | 1.200 | 40% | Número BLACK, Discrição Absoluto, Exclusive/Maison |
| **Founders Circle** | R$ 1.490,00 | **convite, máx. 100** | 2.500 | 50% | escolhe número FC 1–9999, voto no roadmap, marcos físicos |

**Modalidades:** mensal (cheio) · trimestral −15% · semestral −25% · anual −35% · **PIX −5% extra**.

**Descontos em tokens** (sobre o preço do pacote, nunca sobre a quantidade): Explorador 10% ·
Insider 20% · Prestige 30% · Black 40% · FC 50%. Ex.: pacote Baleia R$ 119,90 → R$ 71,94 (Black) →
R$ 59,95 (FC).

### Founders Circle (categoria à parte — não é o 5º Círculo)
- **Número FC:** o membro **escolhe** (1–9999) ao entrar; permanente enquanto ativo, sem troca.
  Indisponível = em uso por FC ativo **ou aposentado**.
- **Marcos físicos progressivos** (por tempo de FC ativo):

  | Marco | Quando | O que é |
  |---|---|---|
  | Kit digital | Dia 1 | Wallpaper + card FC numerado (PDF) |
  | **Carta dos Fundadores** | 1 mês | Carta física assinada (Robson+Bruno), com o número FC — via Locker |
  | **Placa Limen** | 6 meses | Placa acrílico/metal preta+dourada — via Locker |
  | **A Chave do Limen** | 12 meses | Chave em metal, caixa de veludo, numerada — o troféu máximo |

- **Aposentadoria do número:** o divisor é **6 meses (a Placa)**. Cancelou **antes** de 6 meses → o
  número **volta ao pool**. Cancelou **a partir** de 6 meses → número **aposentado para sempre**,
  volta com ele se retornar. FC Histórico (6+ meses): número/badge/"Fundador desde 2027"
  **permanentes**; Sala/voto/Exclusive/Mimo Real **suspensos**.
- **Endereço de entrega = PII** (Locker/Caixa Postal, **nunca residencial**): mesma regra do CPF/KYC
  — tabela isolada, cifrado em repouso, nunca em log/URL.

### FC — benefícios recorrentes
Voto real no roadmap, canal direto com fundadores, Sala dos Fundadores (FC + top 10 Maison), FC
Sessions (live mensal, 20 vagas — vault ⛔ travado), FC Collection, FC First Look (30d FC → 30d
Black → Prestige), **Limen Mementos** (§11).

### Performers Exclusive
Atendem **só Black e FC**; **taxa 12%** (vs. 20% padrão). Pré-requisito da Fase B: 5–10 Exclusive
cadastradas antes de abrir Black/FC.

### Mimo Real (phygital)
Presente físico espontâneo — **adiado** para depois do lançamento (base Black estabelecida).

### Sistema de antiguidade (automático, todos os Círculos)
3m "Membro" · 6m "Membro Fiel" · 1a dourado + "Desde [ano]" · 2a "Veterano" · 3a "Lendário" · 5a
"Eterno" + Hall da Eternidade. **Badges de antiguidade são permanentes**, mesmo cancelando e
voltando.

### O que some ao cancelar (decidido 15/07)
**Todo colecionável digital some do perfil** ao cancelar (Fundadores 2027, temporada, Ano N, Black
Edition — sem exceção). **Sobrevivem:** badges de antiguidade, número FC aposentado + badge FC
histórico (6+ meses), e **objetos físicos já entregues** (carta/placa/chave).

### Modo Discrição por Círculo
Explorador/Insider: nenhum · **Mundo Trans: a partir do Explorador** (exceção) · Prestige: básico
(invisível em busca) · Black/FC: Absoluto (pseudônimo, invisível, histórico privado).

---

## 11. MAISON PROGRAM

Fonte canônica: `MAISON_PROGRAM.md`. Define a **hierarquia de performers**.

### Hierarquia
| Nível | Entrada | Taxa | Marca |
|---|---|---|---|
| **Verificada** | KYC aprovado | **20%** | visível a todos |
| **Select** | candidatura/convite + score 70–84 | **17%** | badge Select, destaque no catálogo |
| **Maison** | **só por convite de Robson e Bruno** + score 85+ + Banca | **12%** | badge animado, Sala Maison, Exclusive Circle |

Score Select/Maison: conteúdo 25% · comunicação 20% · consistência 20% · profissionalismo 15% ·
engajamento 20%. Maison: **máx. 50 simultâneas** (nunca divulgado).

### A Banca (processo Maison, 5 etapas)
(1) score automático 85+ · (2) observação 30 dias (a performer não sabe) · (3) entrevista em vídeo
30 min (0–20 pts) · (4) prova 60 dias (badge "Em avaliação Maison" p/ Black/FC) · (5) convite formal
(carta digital assinada). A partir do 6º mês de operação, um **Conselho Maison** (Robson + Bruno +
Curador) pode conduzir; o convite formal segue assinado pelos fundadores.

### Exclusive Circle
Exclusividade de **experiência**, não de performer. Maison cria para Black/FC: lives exclusivas
mensais, álbum exclusivo, chat direto sem desbloqueio, sessão mensal reservada.

### FC Sessions
Live mensal, 20 vagas, só FC, performer Maison escolhida pela plataforma, **efêmera** para membros
(sem replay). **Cofre legal (⛔ TRAVADO):** gravação backend por 90 dias só para investigação
policial/denúncia — **bloqueado por decisão jurídica** (dado de vida sexual, art. 11 LGPD). Falta
infra que não existe (cripto de vídeo em streaming, role de Curador, expurgo verificável, audit de
leitura). **Nenhum código** até aprovação jurídica (§18).

### Limen Mementos (só Maison → membros FC)
Presente físico **espontâneo** (nunca solicitado, nunca transação). Membro FC ativa "Aceito receber
Mementos" + Locker. Plataforma intermedeia (hub → foto/aprovação → reenvio), curadoria obrigatória,
máx. 1/mês por performer. **Custo logístico: 800 tokens fixos** retidos 100% pela plataforma (frete
+ operação) — **hold no ledger** na aprovação da foto (não na chegada), liberado se reprovado,
débito definitivo na confirmação. **Verificação de saldo antes de a performer submeter a foto** —
mensagem genérica idêntica para qualquer bloqueio (saldo/toggle/limite), para **não vazar** o estado
da carteira nem o toggle (mesma doutrina da máscara de opt-out). **Status atual:** documental — não
há `entry_type` de reserva/hold no ledger; o hold de 800 tokens é **spec, sem código**.

### Casais
Os dois KYC + os dois assinam contrato; split definido no cadastro (cada um saca p/ sua chave PIX);
Banca avalia a dinâmica dos dois juntos; badge "Casal Maison".

---

## 12. MARKETING

Fonte de referência: `GROWTH_STRATEGY.md` (análise CMO/Growth/Product/UX/CRO, 02/07).

**Decisão central: o marketing começa AGORA, sem esperar o go-live.** O portão
`thelimen.com.br` já capta waitlist com double opt-in + drip de nurturing. Construir base antes de
abrir o produto.

- **Branding.** Marca entregue: logo **arco 3D dourado metálico** + wordmark, imagens de OG,
  `limen-icon.png` otimizado (745KB→49KB). Tom: premium, discreto, dark.
- **Growth.** As **50 melhorias priorizadas por ROI** estão em `GROWTH_STRATEGY.md`. O maior
  vazamento diagnosticado **não é aquisição — é ativação de gasto**: quando o relatório foi escrito,
  a gorjeta (único spend) estava desligada na UI. **Hoje a gorjeta está no ar** (inclusive do perfil
  público), então esse gate específico caiu; os quick wins de conversão (quick tips 25/50/100,
  pacote "mais popular", saldo sempre visível, primeira compra com bônus 2×) seguem valendo.
- **Redes sociais.** Captação manual; o Growth OS (§13) organiza leads de performers a partir de
  fontes públicas — nunca automatiza contato.
- **SEO.** OG server-side no catálogo público e nas páginas de portão; títulos de SEO vivem em
  branch própria (`feat/seo-titles`). `seo_backlog` existe no schema do Growth OS.
- **Waitlist.** 2 passos, double opt-in, drip de 7 emails. ⚠️ **`WAITLIST_NURTURE_START_AT`
  precisa ser setado na ativação** — senão o drip dispara em **blast** para todo mundo de uma vez.
  Detalhe em `WAITLIST_SPEC.md`.

---

## 13. GROWTH OS

Diretório `growth_os/` (Python 3.11+, cron no VPS, grava em MySQL `limen_growth` — schema em
`growth_os/schema.sql`). **Regra de ouro:** o agente **só LÊ dados públicos e ORGANIZA; quem age é o
humano.** Não envia mensagem, não segue, não curte, não cria conta em massa, não raspa atrás de
login, respeita `robots.txt`/rate limits.

Tabelas do schema (uma por sub-agente): `leads` (Lead Scout), `agencies` (Agency Scout),
`competitors` + `competitor_events` (Competitor Watch), `crm_pipeline` (CRM), `content_ideas`,
`seo_backlog`, `waitlist`.

- **Lead Scout** (`lead_scout.py`, `LEAD_SCOUT_SPEC.md`): varre fontes públicas (Google Custom
  Search, Linktree/Beacons, X, Reddit, Instagram via busca), extrai handle/bio/link, classifica
  A/B/C, grava `status=novo`. **BLOQUEIO ATUAL:** a Google Custom Search API dá **403
  PERMISSION_DENIED** mesmo ativa — o Google barra projetos **novos**. Trocar de fonte ou usar um
  **projeto Google antigo**.
- **Agency Scout / Competitor Watch / CRM:** tabelas prontas no schema; automação ainda em spec.
- **Daily briefing** (`daily_briefing.py`): consolida o que os scouts acharam para revisão humana.

---

## 14. CAPTAÇÃO DE PERFORMERS

**Concorrentes analisados (inspiração, não cópia):** meupatrocinio.com.br, cameraprive.com,
chaturbate.com. O Limen se diferencia por curadoria (Maison), verificação dos dois lados e economia
auditável — não compete em volume/preço.

**Canais.** Fontes públicas via Growth OS (Linktree/Beacons, X, Reddit, resultado público de
Instagram), fila de performers fundadoras (§7), e referral de performer por performer (item #46 do
GROWTH_STRATEGY — bônus de split; ainda não implementado).

**Abordagem aprovada.** Coleta **passiva e pública** → curadoria humana → convite. Nunca automação
de contato/DM. O funil de performer termina em: cadastro → onboarding → KYC (Didit) → catálogo. As
primeiras **Exclusive** destravam a Fase B dos Círculos (Black/FC).

---

## 15. EMAILS

Transporte: **Resend** (`resend/resend-laravel`), webhook em `/resend/webhook`. Padrão de link de
ação (memória): **GET confirma, POST executa** — prefetch de mailbox dispara GET; token opaco cifrado
(`Crypt`), **sem PII na URL/log**.

**Spec aprovada — membro fundador:** email de confirmação (double opt-in) → sequência de nurturing
de **7 emails** (drip), gateada por `WAITLIST_NURTURE_START_AT` (§12). Copy final e halt pós-launch
são follow-ups. Ao virar fundador: confirmação de posição na fila + invite code (V3).

**Spec aprovada — performer fundadora:** email de entrada na fila de performers → onboarding
(convite para completar cadastro + KYC). Ao ser observada/convidada para Maison, o convite formal é
**carta digital assinada por Robson e Bruno** (Etapa 5 da Banca, §11) — não é email transacional
comum.

**Recomendação aberta (GROWTH_STRATEGY):** emails transacionais hoje são texto; padronizar no dark
premium da marca; boas-vindas com 3 performers do mundo preferido; notificação ao performer a cada
gorjeta; resumo semanal de ganhos.

---

## 16. ROADMAP (Sprint 1 → 5)

| Fase/Sprint | Entrega | Estado |
|---|---|---|
| **Fase 0** | Repo + ambiente (MySQL/Redis Docker) | ✅ |
| **Fase 1** | Modelo de dados + segurança base (ledger, TokenService, seeder) | ✅ |
| **Fase 2** | Auth + cadastro (Sanctum, email verify, reset, roles, policies, audit) | ✅ |
| **Fase 3** | Compra de tokens + Asaas/PIX (webhook idempotente, reconcile) | ✅ (driver Fake) |
| **Fase 4** | Perfis de performer, catálogo público, follows | ✅ |
| **Fase 5 (KYC)** | Verificação KYC (Didit, resubmissão, docs cifrados) | ✅ (driver Fake) |
| **Fase 6** | Gorjetas (TipService, split, rate limit 10/min) | ✅ |
| **Fase 7** | Frontend Inertia/Vue/Tailwind (design system, age gate, auth sessão) | ✅ |
| **Fase 8** | Catálogo visual de performers no frontend | ✅ |
| **Sprint 1** | Fechamento de servidor (ASAAS Fake staging, backfill-avatars, sudoers) | ✅ |
| **Sprint 3** | **Interesse Controlado** (15 tokens, opt-out silencioso, piso de anonimato em aberto) | ✅ |
| **Sprint (Círculos A)** | Assinaturas Fase A: backend #44 + frontend #46 (Explorador→Prestige) | ✅ |
| **Sprint 4 (Chat)** | **Chat interest-gated tempo real (Reverb)** — PRs #55/#56/#58/#59 | ✅ |
| **Sprint 5** | Asaas/KYC fora do Fake, PCI #47, Fase B Black/FC, payout requeue, trial, piso anonimato | 🟡 **PRÓXIMO (§17)** |
| **Bloqueado** | Cofre FC Sessions (jurídico), Mimo Real (pós-launch), LiveKit/PPV/feed | 🔴 |

**PRs mergeados desde a RETOMADA anterior (#44):** #46 (frontend assinaturas), #48–#52 (marca/logo),
#51/#53/#54 (nginx do portão serve imagens/OG), #55 (backend chat), #56 (frontend chat), #58
(`laravel/reverb` como dependência de produção), #59 (lista de conversas em tempo real + fix de
scroll). **#47 é uma ISSUE** (PCI client-side), não PR. Sem `gh` CLI — PRs abertos pela URL do push.

---

## 17. SPRINT 5 — PLANO DETALHADO (o próximo Claude começa AQUI)

**Ação zero:** rodar **`scripts/create-sprint5-issues.sh`** (cria a milestone "Sprint 5" + as 4
issues de follow-up do chat). ⚠️ o script usa **`gh`**, que **não existe** neste ambiente — ou
instale/autentique o `gh`, ou crie a milestone/issues pela URL do push manualmente (o corpo das 4
issues está no próprio script, pronto para copiar).

### P0 — bloqueiam o go-live
1. **Asaas/KYC fora do modo Fake.** Hoje `ASAAS_DRIVER=fake` e KYC (Didit) Fake. Validar em
   sandbox real, conferir webhooks idempotentes em produção, cuidar do `ASAAS_API_KEY` com `$`
   (**aspas simples no `.env`**). **Passa pelo `security-reviewer`.**
2. **Issue #47 — tokenização de cartão client-side (PCI).** Hoje o PAN passa pelo servidor
   (server-side). Mover a tokenização para o **cliente** (Asaas) e tirar o cartão do escopo PCI.
   **Obrigatório `security-reviewer` antes de mergear.**

### P1
3. **Fase B — Black/FC.** Enforcement de **`seat_limit`** (Black máx. 500, FC máx. 100),
   **`fc_numbers`** (pool 1–9999 + aposentadoria aos 6 meses), **Halls** (opt-in), **número BLACK**
   (sequencial, invisível ao membro). **Gate:** só abrir após **5–10 performers Exclusive**.
4. **Payout — alerta/requeue de `needs_review`.** O reconcile para de tentar após 2h de buscas
   vazias; o prazo conta de `unresolved_since`, não de `requested_at`. Falta **alerta + requeue**;
   revisar 429/408 no `createTransfer`.
5. **Rodar `scripts/create-sprint5-issues.sh`** (as 4 issues do chat, §17 abaixo).

### P2
6. **Trial de 7 dias dos Founding Members.** Semana grátis ao assinar **qualquer Círculo** no
   lançamento (aprovado em doc, sem código).
7. **Piso de anonimato do Interesse — decidir com SQL.** Medir a distribuição de follows/membro
   (`SELECT follows_por_membro, COUNT(*) ...` em `INTEREST_ANONYMITY_FLOOR.md`) e escolher entre as
   4 opções. Mediana 1–2 → piso por conjunto vira necessário; mediana alta → aceitar e documentar.

### ⛔ NÃO fazer
8. **Cofre das FC Sessions** — travado por jurídico (Opiceblum). **Nenhuma linha de código** até
   aprovação (§18).

### As 4 issues de follow-up do chat (do script)
1. **Idempotência não-permanente no `chat_access`** — replay de chave antiga após renovação
   recobra 50 tokens (`ChatAccessService` guarda só a *última* chave). Tornar o dedup permanente
   (persistir `idempotency_key` no ledger ou tabela dedicada). *Contraria o princípio nº 3.*
2. **Opt-out de Interesse não congela conversa já aberta** — a máscara só age no gatilho do
   Interesse; `sendMessage` não consulta supressão. **Decisão de produto** (não vaza opt-out).
3. **Broadcast entrega metadados a membro em grace/expired** — `conversation.{id}` só checa
   `hasParticipant`; reusar `accessState()["can_read"]` na autorização do canal.
4. **Ledger não referencia o `ChatAccess` no primeiro open** — no 1º open `$access?->id` é `null`,
   então `reference_id` fica `null` (trilha ledger→access quebrada só na compra inicial).

---

## 18. RISCOS

**Técnicos.**
- **Broadcast silencioso:** worker que sobe antes do `config:cache` descarta eventos Reverb sem
  erro. Mitigação: `queue:restart` após `config:cache` (no `setup-reverb-server.sh`).
- **Deploy frágil:** `composer install --no-dev` morre se `vendor/` tiver dono ≠ `deploy`; sudoers
  não cobre `sudo mkdir`. Rodar `artisan` como `deploy`.
- **DB de dev atrasada:** `migrate:status` local pode mostrar pendentes (a `main` tem todas `Ran`).
  Rodar `migrate` antes de assumir bug de schema.
- **Rotação de `APP_KEY` quebra KYC** (`.enc`) e `card_token` cifrado — nunca rotacionar sem plano
  de re-cifragem.
- **Load:** o LOAD_REPORT estourou thresholds em VM de dev não representativa — validar no VPS
  (nginx+fpm+opcache) antes de tráfego real.

**Jurídicos.**
- **Cofre FC Sessions (art. 11 LGPD — vida sexual):** consentimento provavelmente **não** é base
  suficiente (o titular revoga; o cofre precisa sobreviver à revogação). **Travado até aprovação
  jurídica** (Opiceblum). Membro/performer hoje entram na sessão achando que nada é gravado — a
  ressalva vive só em doc interno, não nos termos.
- **CSAM / conteúdo ilegal:** scan CSAM e IP allowlist de webhooks são must-fix de produção ainda
  abertos (GO_LIVE_READINESS #7).
- **PII de endereço físico (FC marcos/Mementos):** Locker apenas, cifrado, nunca residencial.

**Financeiros.**
- **Double-pay em payout:** hardening na main, mas restam furos (429/408 no `createTransfer` ainda
  pode estornar; falta alerta/requeue de `needs_review`).
- **Idempotência do chat_access não-permanente:** replay tardio recobra 50 tokens (issue #1).
- **Drip de waitlist em blast** se `WAITLIST_NURTURE_START_AT` não for setado — risco de reputação
  de envio (spam) além de custo.

**Produto.**
- **Piso de anonimato do Interesse:** membro novo adivinha o remetente sem pagar → mata a
  monetização da descoberta para quem mais importa converter. Decidir com dado.
- **Fase B vazia:** abrir Black/FC sem performers Exclusive entrega promessa oca — gate de 5–10
  Exclusive é intencional.
- **Pseudônimos correlacionáveis** entre painel (`Fã #0042`) e seguidores (`Membro #42`) anulam o
  mascaramento das gorjetas — unificar.

---

## 19. DÍVIDAS TÉCNICAS

- **Asaas/KYC em Fake** — pré-requisito de go-live (P0).
- **Tokenização de cartão server-side** — issue #47, tirar cartão do escopo PCI (P0).
- **`chat_access` idempotência não-permanente** — issue #1 do chat.
- **Ledger→`ChatAccess` sem `reference_id` no 1º open** — issue #4 do chat (trilha unidirecional).
- **Broadcast metadados em grace/expired** — issue #3 do chat.
- **Opt-out não congela chat aberto** — issue #2 (decisão de produto).
- **`WAITLIST_NURTURE_START_AT`** — sem ele o drip vira blast.
- **Payout `needs_review`** — sem alerta/requeue; prazo conta de `unresolved_since`.
- **Retenção/expurgo de KYC** — nunca implementado; rotacionar `APP_KEY` quebra os `.enc`.
- **Pseudônimos correlacionáveis** (`Fã #0042` vs `Membro #42`) — unificar mascaramento.
- **`unlock()` não revalida se a performer segue ativa** — decisão de produto pendente.
- **`.env.example` induz a SQLite**, mas o projeto é **MySQL** — corrige a expectativa de quem chega.
- **`phpunit.xml` força `DB_CONNECTION=sqlite`** — as vars de CLI vencem (ver §20); não há SQLite local.
- **CI não roda lint** (Pint existe em dev, não no pipeline).
- **Hold de tokens (Mementos)** — sem `entry_type` de reserva; os 800 tokens são documentais.
- **Growth OS Lead Scout** — Google Custom Search API bloqueada p/ projeto novo (403); trocar fonte.
- **`seat_limit` em `circles`** — coluna existe, **não é aplicada** (Fase B).

---

## 20. CHECKLIST OPERACIONAL (comece sem perguntar nada)

### Comandos de arranque
```bash
cd /home/robson/teste
git fetch origin && git checkout main && git reset --hard origin/main
docker ps    # esperar: limen-mysql (3306) / limen-redis (6379) / limen-adminer (8080)

# Migrar a DB de dev (pode estar atrás da main):
DB_CONNECTION=mysql DB_DATABASE=limen DB_HOST=127.0.0.1 DB_PORT=3306 \
  DB_USERNAME=limen DB_PASSWORD=limen_dev_pw php artisan migrate

# Rodar os testes (limen_test; as vars de CLI vencem o phpunit.xml):
DB_CONNECTION=mysql DB_DATABASE=limen_test DB_HOST=127.0.0.1 DB_PORT=3306 \
  DB_USERNAME=limen DB_PASSWORD=limen_dev_pw php artisan test    # esperado: 440 verdes

# npm local (o proxy TLS quebra a validação do cert do registry):
NODE_EXTRA_CA_CERTS=/etc/ssl/certs/ca-certificates.crt npm ci    # nunca desligar strict-ssl
```

### Onde está cada coisa
- **Princípios do projeto:** `CLAUDE.md` (mas ignore o "Estado atual" — está velho; use este doc).
- **Estado vivo detalhado:** `docs/RETOMADA-CHAT-NOVO.md` (17/07).
- **Monetização/assinatura:** `docs/CIRCLES_SYSTEM_V4.md` + `docs/MAISON_PROGRAM.md` +
  `docs/COMMUNICATION_ECONOMY.md` + `docs/SUBSCRIPTION_TIERS.md` (**superseded**, só histórico).
- **Interesse + chat:** `docs/INTEREST_SYSTEM_SPEC.md` + `docs/INTEREST_ANONYMITY_FLOOR.md`.
- **Mundos:** `docs/WORLDS_ARCHITECTURE.md`. **Waitlist:** `docs/WAITLIST_SPEC.md`.
- **QA (retrato 02/07, arquivado):** `docs/qa/` (GO_LIVE_READINESS, GROWTH_STRATEGY, TEST_RESULTS,
  SECURITY_REPORT, UX_REPORT, LOAD_REPORT, TEST_ACCOUNTS).
- **Growth OS:** `growth_os/` (Python + `schema.sql` + `LEAD_SCOUT_SPEC.md`).
- **Sprint 5 issues:** `scripts/create-sprint5-issues.sh`. **Reverb:** `scripts/setup-reverb-server.sh`.
- **Skills do repo** (invocáveis): `token-ledger-rules`, `asaas-pix-integration`,
  `laravel-api-conventions`, `catalog-ux`.
- **Subagentes de QA/segurança:** `.claude/agents/` (16 agentes; `security-reviewer` obrigatório
  antes de qualquer coisa sensível).

### Armadilhas conhecidas
- **`ASAAS_API_KEY` começa com `$`** → aspas simples no `.env`, senão 401.
- **Toda rota nova usada no front** entra em `config/ziggy.php` (allowlist `only`) — senão o Ziggy
  lança na montagem do Vue e o site fica **preto**.
- **`queue:restart` sempre depois do `config:cache`** — senão o broadcast é descartado em silêncio.
- **Não rodar git como root** no servidor; deploys usam `sudo -u deploy`; sudoers **não** cobre `mkdir`.
- **`limen.dev.br` bloqueado pelo Zscaler** na VM (não é bug); túnel `:8443` ≠ `APP_URL :443` quebra
  POST do Inertia.
- **`thelimen.com.br` "desatualizado"** = suspeitar de **opcache/CDN**, não de código (mesmo box do staging).
- **Sem `gh` CLI** → PRs e issues abertos manualmente pela URL do push.
- **DB de dev pode mostrar migrations `Pending`** — rode `migrate`; a `main` tem todas `Ran`.

### Decisões que NÃO podem ser revertidas
- **Ledger append-only:** NUNCA `UPDATE saldo`. Todo movimento é linha nova; saldo é a soma.
- **Idempotência de pagamento por id de evento** — reprocessar nunca duplica saldo.
- **PII (CPF, KYC, endereço de entrega) isolada e cifrada em repouso**, storage privado, nunca em
  log/URL. CPF só no checkout.
- **`category` é o mundo** — nunca criar coluna `world`; SSOT `PerformerProfile::WORLDS`.
- **Tokens são inteiros** (centavos/tokens), nunca float.
- **"Círculos", nunca "Planos".** Chat **interest-gated** (sem contato frio do membro). Máscara de
  opt-out = sucesso vazio (quebrar vaza o opt-out). Paywall de leitura do chat **server-side** (o
  broadcast nunca leva o corpo).
- **Cofre FC Sessions travado por jurídico** — não implementar.

### Primeira ação sugerida
Rodar `scripts/create-sprint5-issues.sh` (ou criar as issues manualmente) e atacar **Asaas/KYC fora
do Fake** (P0, go-live), depois **issue #47 (PCI client-side)**. **Nada de cartão/PCI/KYC/pagamento
sem passar pelo `security-reviewer`.**
