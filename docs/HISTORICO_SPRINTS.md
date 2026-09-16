# Limen — Histórico de Sprints (auditoria)

> **Este arquivo é histórico.** Saiu do `CLAUDE.md` no PR `docs/slim-claude-md`
> para o guia caber no limite de contexto. É o registro cronológico do que foi
> entregue em cada sprint (Sprint 1 → 16), follow-ups, PRs mergeados e o estado
> de cada janela. **Valioso para auditoria; não precisa ser lido em toda tarefa.**
> Para "como o sistema funciona" (serviços/features/invariantes de feature) ver
> `docs/ARQUITETURA.md`; para regras vivas ver `CLAUDE.md`.

## Estado atual

> **Estado atual** (`main`, `ae389c2`): **~2017 testes, 16083 asserts** (todos passam
> local menos a antiga falha da view 451 do GeoBlock, que não recorre depois do
> `npm run build`, que compila a view — ver § "Ambiente de dev"). **130 migrations,
> ~205 rotas web + 42 rotas API.** A **fila de melhorias** (itens 2–5), o polimento
> premium e o **foco em lista de espera da landing (PR #187, `feat/landing-waitlist-focus`,
> mergeado)** foram **consolidados na `main`** — a sequência de PRs a partir do fecho
> do Agendamento de chamada (`db007b3` → docs #169 → catálogo-de-membros-home #173
> `67f88a0` → os sete abaixo → landing cinematográfica #184 → waitlist-focus #187). O
> detalhe completo vive
> em **`docs/MASTER_HANDOFF_FINAL.md`** — esse é o doc a ler antes de pegar tarefa (o
> `MASTER_HANDOFF_SPRINT6.md` é histórico). Este resumo só situa. **Base original**
> (PR #69, `229d852`): 556 testes, 2614.
>
> **Consolidado na `main` nesta sessão (todos MERGEADOS, `92ba2c7`):**
>
> - **Turnstile — driver de captcha switchável** (PR #174, `04ea58b`): o que era só
>   hCaptcha virou `CAPTCHA_PROVIDER=none|hcaptcha|turnstile` (`config/captcha.php`,
>   `App\Services\Captcha\*`, regra `CaptchaValid`, `Captcha.vue`). Sobe DESLIGADO
>   (`none`, no-op). Motivado pelo fim do trial Pro do hCaptcha (11/08/2026). Ver §
>   "Captcha".
> - **Sinais de atividade nos catálogos** (PR #175, `f3ec9b1`, +10 testes): selo
>   "Nova/Novo" (janela de 7 dias, `NewBadge`, BOOLEANO derivado) nos dois catálogos +
>   contadores de não-vistos na nav (`NavBadgeService`/`nav_counts` — mensagens não
>   lidas respeitando o paywall + corações recebidos via watermark `hearts_seen_at`).
>   **"Online agora" NÃO entrou** (decisão do PO — colide com a granularidade "hoje" do
>   `ActivitySlot` e a não-exposição de presença do membro). Ver § "Sinais de atividade
>   nos catálogos".
> - **Teaser da mensagem bloqueada** (PR #176, `17ba83e`, +11 testes): corte
>   **SERVER-SIDE** das primeiras ~3 palavras da mensagem paga (o membro sem acesso
>   nunca recebe o corpo completo — borrar via CSS vazaria no DevTools). Dona única
>   `App\Support\MessageTeaser` + `config/message_teaser.php`. A economia (gate M.13.1)
>   não muda; só o preview. Ver § "Teaser da mensagem bloqueada".
> - **Filtro de cidade CONSENTIDO no catálogo de performers** (PR #177, `191d384`,
>   +14 testes, item 4 da fila): autocomplete do IBGE (~5.570 municípios em
>   `public/data/ibge-municipios.json`, self-hosted, zero asset externo) + opt-in
>   `findable_by_city` (default OFF). A cidade da performer **continua interna** (só UF
>   é pública); ela só passa a FILTRAR a busca de quem ligou o opt-in, e **nunca é
>   exibida**. NÃO toca o catálogo de MEMBROS. Ver § "Filtro de cidade consentido".
> - **Visitas bidirecionais** (PR #178, `f6597d3`, +19 testes, item 5 da fila): o
>   sentido INVERSO das visitas (performer → membro) — a performer abre o "perfil" de
>   um membro e o membro vê **"Quem visitou seu perfil"** (`/quem-me-visitou`) com a
>   identidade PÚBLICA da performer (sem FanAlias, sem piso, sem paywall). Tabela nova
>   `member_profile_visits` (separada de `profile_visits`); `ProfileVisitService`
>   ESTENDIDO. Ghost Mode não se aplica ao inverso. v1 sem monetização. Ver § "Visitas
>   bidirecionais".
> - **Microinterações premium** (PR #179, `16ce24a`, +3 testes): camada puramente
>   VISUAL (CSS puro, zero biblioteca, zero asset externo) — lift dos cards, micro-pulso
>   dos botões, "pop" do coração, fade de página, barra dourada de loading, slide do
>   erro de formulário. Tudo desligado sob `prefers-reduced-motion`, sem tocar
>   mobile/lógica/privacidade. Dona única `resources/css/micro-interactions.css`. Ver §
>   "Microinterações premium".
> - **Intro de voz da performer** (PR #180, `92ba2c7`, +28 testes): PRIMEIRO áudio do
>   projeto (greenfield). Clipe ≤20s no perfil, GRÁTIS de ouvir, opt-in. Higienizado por
>   ffmpeg (MP3 mono, strip de TODO metadado — `VoiceProcessingService`, separado do
>   vídeo) e **NÃO vai ao ar sem MODERAÇÃO HUMANA** (`processing → pending →
>   approved/rejected`; só `approved` é servível). Motivo (PO): áudio dribla o filtro de
>   texto do chat — risco art. 228; anti-CSAM não se aplica a áudio, o humano é o gate.
>   Fila `/moderacao/apresentacoes-de-voz`, disco privado, serving por request. Ver §
>   "Intro de voz da performer".
>
> Todos com revisão de segurança rodada (sem 🔴/🟡, salvo os 🟡/🟢 já corrigidos
> registrados nas §§ respectivas). A **dependência dura** das visitas bidirecionais — o
> catálogo de membros como HOME + motor de engajamento coração/mensagem — mergeou antes,
> no #173 (`67f88a0`); ver §§ "Catálogo de membros como HOME" e "Motor de engajamento".
>
> **Landing CINEMATOGRÁFICA — MERGEADA (`feat/landing-cinematic`, PR #184):** a raiz
> pública `/` virou a "porta do clube": 5 cenas de tela cheia com scroll-storytelling
> (abertura em vídeo → portal → verificação → mistério → convite), dourado e mistério,
> substituindo o hero-maison do PR #153. Mídia 100% SELF-HOST em `public/landing/*`
> (WebP desktop+mobile <400KB cada + 1 MP4 mudo ~0,9MB, otimizados por ffmpeg a partir
> dos PNGs de 2–7MB) — vídeo só no desktop, `prefers-reduced-motion` e mobile caem na
> `porta.webp` estática, lazy-load abaixo da dobra. `ExternalAssetPolicyTest` verde
> (tudo relativo `/landing/…`; o Nginx ganhou um `^~ /landing/` para servir os assets —
> fix pós-merge). Só a raiz pública muda; nenhuma tela interna tocada.
>
> **Foco em lista de espera — MERGEADO (`feat/landing-waitlist-focus`, PR #187, +5
> testes → ~2017 testes / 16083 asserts):** ajuste de **PRÉ-LANÇAMENTO** da landing. **A
> landing não oferece mais cadastro** — o botão "Solicitar convite" → `/cadastro` saiu
> da cena do convite e o **único CTA passa a ser "Entre na lista de espera"** (o backend
> de `/cadastro` fica intacto; volta no lançamento). O **header da landing esconde
> Entrar / Criar conta** (fica só o logo) via flag `features.landing_prelaunch` (default
> TRUE) passada ao `GuestLayout` — escopo só na landing, reativa no lançamento só pelo
> `.env`. Além disso: cena 2 com **fade dirigido por scroll** e arco em brilho pleno
> (texto no terço inferior, abaixo do LIMEN); cena 5 (moldura) **full-bleed
> `object-cover`**; a seção da lista de espera ganha **fundo de mármore escurecido** +
> **aviso de SPAM** na tela de sucesso ("confira a caixa de spam · marque como não é
> spam"). Reduced-motion e mobile honrados. `ExternalAssetPolicyTest` verde. Ver §
> "Landing cinematográfica — foco em lista de espera".
>
> **Em branch (`feat/landing-motion` → `fix/landing-motion-v2`, a partir da `main`, PR
> pendente):** camada de **MOVIMENTO cinematográfico** sobre a landing (nenhum asset
> novo, nenhuma tela interna tocada) — Ken Burns nas fotos, parallax expressivo com o
> texto em derivada, reveal de texto em cascata (palavra a palavra), e um véu de luz
> dourado sobre o mármore. **`fix/landing-motion-v2` (build sobre a `feat/landing-motion`)
> corrigiu os bugs visíveis** (palavras coladas na cascata, copy da cena 3, borda
> descoberta no Ken Burns da cena 4) **e refez a transição entre cenas**: as 5 cenas
> deixaram de ser seções empilhadas (que davam uma **emenda reta**) e viraram uma
> **sequência empilhada com cross-dissolve** — palco sticky único, cenas sobrepostas,
> opacity+brightness dirigidos pela POSIÇÃO do scroll (a de cima escurece, a de baixo
> clareia, sem emenda, simétrico nos dois sentidos). Continua UM laço rAF ÚNICO e UM
> listener de scroll; `will-change` só na cena em cena; `prefers-reduced-motion` cai no
> fluxo vertical normal; mobile roda o cross-fade com parallax zerado. Ver § "Landing
> cinematográfica — foco em lista de espera" (subseções "Camada de movimento" e
> "Transição empilhada e correções de movimento").
>
> **Em branch (`feat/landing-scene5-zoom`, build sobre a `fix/landing-motion-v2`, PR
> pendente):** três ajustes na **cena 5** (o wordmark LIMEN) — (1) **fix do corte no
> retrato/mobile** (`moldura.webp` é larga; em tela alta o `cover` comia as letras e
> sobrava "MB") passando a **`object-fit:contain`** no retrato, letterbox invisível no
> fundo escuro, `cover` mantido no desktop; (2) **tagline + CTA para o terço inferior**,
> abaixo das letras, sobre véu de base reforçado; (3) **zoom de saída dirigido por
> scroll** — uma **cauda extra** de scroll (`stackVh = (N + ZOOM_TAIL) × 100`) no laço
> rAF ÚNICO onde a IMAGEM da cena 5 escala **1.0 → 1.6** e esmaece, dando lugar à
> waitlist; simétrico, **Ken Burns por tempo removido da cena 5** (os dois brigavam;
> cenas 2/3/4 mantêm). Só `transform`/`opacity`/`brightness`; texto/CTA não crescem e
> seguem clicáveis; `prefers-reduced-motion` desliga o zoom (cena estática/legível). Ver
> § "Landing cinematográfica — foco em lista de espera", subseção "Cena 5 (LIMEN): zoom
> de saída por scroll + contain no retrato".
>
> **Em branch (`feat/landing-marble-bg`, build sobre a `feat/landing-scene5-zoom`, PR
> pendente):** troca a **FOTO do wordmark** (`moldura.webp`, letras douradas) por
> **mármore LIMPO + o LOGO REAL da marca** na cena 5 e na banda de waitlist. `moldura.webp`
> era foto: cortava as letras no retrato ("MB" em vez de "LIMEN") e não deixava espaço
> para a tagline (ficava POR CIMA do wordmark). Agora o fundo é o mármore próprio do PO
> (`fundo.webp` paisagem / `fundo-mobile.webp` retrato vertical, otimizados por ffmpeg,
> 208KB/183KB) e o wordmark vem do componente **`PortalLogo.vue`** (o MESMO do header),
> no terço superior/central, com a tagline + CTA **ABAIXO, com folga real** — zero
> sobreposição (o objetivo do PR). O **zoom de saída passa a agir no LOGO** (escala
> 1.0→1.6, ele cresce e some dando lugar à waitlist); o mármore fica quieto (só respira) —
> a sensação é de ATRAVESSAR o portal, não a parede crescer. Retrato volta a `object-cover`
> (asset vertical próprio — fim do `contain` de emergência) com **véu de contraste
> reforçado** atrás do logo (o mármore de celular tem veios laranja vivos). `moldura.webp`
> CONTINUA no repo como **og:image** do cartão social (só saiu da tela). Só
> `transform`/`opacity`/`brightness`, UM laço rAF/listener, `prefers-reduced-motion`
> desliga o movimento; nenhuma tela interna tocada. Ver § "Landing cinematográfica — foco
> em lista de espera", subseção "Cena 5 (LIMEN): mármore limpo + logo real".

**Sprints 6, 7, 8, 9A, 9C, 10, 11, 12, 13, 14, 15 e 16 fechados** (tags `v1.0-sprint6`
a `v1.0-sprint9a`, **`v1.0-sprint9`** no fecho do 9C, **`v1.0-sprint9.1`** no fecho
dos bloqueadores da Foto Efêmera, **`v1.0-sprint10`** (`402d29e`) no fecho do
Sprint 10, **`v1.0-sprint11`** (`11354b4`) no fecho do Sprint 11,
**`v1.0-sprint12`** (`f23368a`) no fecho do Sprint 12, **`v1.0-sprint13`**
(`1d63371`) no fecho do Sprint 13, **`v1.0-sprint14`** (`0f6aefb`) no fecho do
Sprint 14, e **`v1.0-sprint15`** (`bf1c3dd`) no fecho do Sprint 15). **O Sprint 16
fechou em `37d8cec` (PRs #151–#166); os PRs #167/#168 mergearam logo depois (`main`
em `55de8cd`) e ainda não há tag** — o fecho é este doc.
**O Sprint 9B não tem tag própria** e não está fechado.

> **Sprint 15 fechou com 8 entregas** (tag `v1.0-sprint15`, `bf1c3dd`) — **vídeo
> em tempo real (LiveKit)**, planejado desde a fundação e nunca implementado até
> aqui. Cada gasto/crédito novo virou migration no enum de `entry_type` do ledger
> append-only (princípio nº 2, `spend_live`/`live_credit`/`spend_call`/`call_credit`),
> nunca `UPDATE` de saldo; a cobrança por minuto/bloco é pré-paga, saldo nunca
> negativo, split por evento congelado (`applied_rate`). PR #138 (**infra LiveKit +
> token service** — `LiveKitService` dona única de rooms/JWTs, `config/livekit.php`,
> feature flags de dark launch, identity opaca/FanAlias por par, room_name nunca em
> URL/log), PR #139 (**live pública grátis** com gorjeta/presente — serving por
> sessão, sem URL assinada, badge no catálogo, gorjeta 80/20 e presente 75/25 pelas
> rotas existentes), PR #140 (**chamada privada 1:1** com cobrança por minuto — split
> 70/30, request/accept/decline, heartbeat pré-pago idempotente por minuto,
> `MinuteBiller` como motor único, exclusividade sob lock, ban/kill-switch,
> `calls:reap-stale`), PR #141 (**group show 1:X** com upgrade para 1:1 — 1 sessão
> `type=group` + N `call_session_participants` com cobrança independente, upgrade
> com revoke de 10s por job, exclusividade bidirecional), PR #142 (**animação de
> gorjeta/presente na live** — evento broadcast `LiveReaction` no canal `live.{slug}`,
> `<LiveOverlay>` com fila, payload não-sensível FanAlias-only), PR #143 (**preview
> animado no catálogo** — frame JPEG por sessão capturado do canvas a cada 10s,
> disco privado `live_previews`, ServesPhotoBytes, `live-previews:purge`), PR #144
> (**toast global de mensagem** estilo Seeking — `<MessageToast>` no AppLayout,
> `NewMessage` com sender mascarado por destinatário, nunca o corpo), PR #145
> (**"Em breve"** em produção — flags compartilhadas como props Inertia,
> `<ComingSoon>`, todas as rotas de live/call/group gateadas por `feature:*`).
> **Resolução do § 2.5** (serving sem cifra em memória, que travou as FC Sessions):
> **não há serving HTTP de bytes de vídeo** — o LiveKit SFU faz o relay do vídeo
> via WebRTC (DTLS-SRTP fim-a-fim); o backend só emite tokens JWT curtos e controla
> permissão (quem entra na sala, por quanto tempo). O gargalo histórico deixou de
> existir por arquitetura, não por cifra em memória. **Deploy de staging pendente**;
> as features sobem com `FEATURE_LIVE_ENABLED`/`FEATURE_CALL_ENABLED` **off** (dark
> launch — liberação é decisão jurídica, muda só o `.env`). A tag é marco de código,
> não de go-live (ver abaixo). **Não iniciado (foi para Sprint 16):** feed de
> conteúdo permanente, sanitização de upload de vídeo, verificação de documento,
> animações elaboradas de presente, preview via WebRTC real, som de notificação.

> **Sprint 16 fechou com 16 PRs** (`main` em `37d8cec`, PRs #151–#166, **sem tag**
> — o fecho é este doc). Duas frentes: **redesign "maison"** do front (catálogo,
> landing, card, perfil, crop de avatar/capa) e **novas superfícies de produto**
> (feed de conteúdo, catálogo de membros, anti-CSAM, receita real no admin, som de
> notificação). PR #151 (**PanicButton visível ao membro** — bug de visibilidade),
> PR #152 (**card v2** do catálogo no design system `limen-*`), PR #153 (**landing
> redesenhada**), PR #154 (**grid v2 + trilha "Agora"** com lives/stories + slot de
> Destaque), PR #155 (**PanicButton vira link de texto no header** — o disco sozinho
> era lido como "fechar"; achado do UAT), PR #156 (**crop interativo** de avatar
> 1:1 e capa 3:1 com cropperjs, avatar agora passa pelo `ImageProcessingService`),
> PR #157 (**limpeza de ruído de log** — pula pagamentos `pay_fake_` na reconciliação
> e protege `LivePreviewService::purgeOrphans` contra disco ausente), PR #158
> (**perfil da performer redesenhado** na estética maison), PR #159 (**feed do
> membro** `/feed` — consome o backend de conteúdo permanente do PR #135; desbloqueio/
> serving reusam `content.*`), PR #160 (**dashboard admin de receita** `/admin/dashboard`
> — `AdminMetricsService`, só `role:admin`, **zero PII de membro**), PR #161
> (**anti-CSAM MVP** — § abaixo), PR #162 (**preview WebRTC real** no hover do card,
> v2 do snapshot JPEG), PR #163 (**animações de presente por partícula/sprite** no
> `<LiveOverlay>`, v2 da CSS animation simples), PR #164 (**receita real** — o
> dashboard passa a somar `payments` confirmados, não estimativa de ledger), PR #165
> (**catálogo de membros para a performer** — § abaixo, `MemberCatalogService`,
> Interesse Controlado invertido), PR #166 (**som de notificação + preferências** —
> § abaixo, `users.notification_preferences`). **Mergeados APÓS o fecho inicial (PRs
> #167/#168, `main` em `55de8cd`):** a **sanitização de upload de vídeo** (PR #167 —
> `performer_content.kind` ganhou `'video'` + coluna `status`
> `processing→ready/failed`; migration `2026_08_10_000001_add_video_support_to_performer_content`)
> e o **selo de curadoria "maison/select"** (PR #168 — `<CurationSeal>` no perfil e
> na `Catalog/Show`/`Performers/Show`). **Não iniciados:** verificação de documento
> (Didit), hCaptcha em produção, pin PHP 8.5→8.4 no `deploy.yml`.

> **Sprint 14 fechou com 8 entregas** (tag `v1.0-sprint14`, `0f6aefb`) — a
> **implementação do modelo de monetização fechado** (§ "Modelo de monetização —
> DECISÕES FECHADAS" + emenda M.13). Cada tipo novo de gasto/crédito virou
> migration no enum de `entry_type` do ledger append-only (princípio nº 2), nunca
> `UPDATE` de saldo. PR #130 (**invariantes M.13** — `config/monetization.php`
> como fonte canônica + `TokenCreditPolicy` dona única de teto/split/pendência/
> chat/payout, `applied_rate` congelado), PR #131 (**rewire tip/pacotes/desconto**
> para M.13 — gorjeta 80/20 por evento, desconto de compra pela config, pacotes
> achatados M.13.2), PR #132 (**chat M.13.1** — fim do chat grátis de assinante:
> todo tier paga abertura, performer +1 token fixo), PR #133 (**subscription
> grant com fila de pendência** M.13.4/M.13.8 — franquia mensal com teto
> escalonado, webhook primário + command de reconciliação), PR #134 (**payout
> mensal R$0,60/token** M.13.5/M.10 — sweep dia 1 idempotente + on-demand, só
> ganhos sacáveis), PR #135 (**conteúdo permanente com acesso por tier**
> M.13.13/M.4 — foto v1, níveis Aberto/Premium/Exclusivo/FC Only, desbloqueio
> permanente, split 80/20), PR #136 (**fix de copy dos founding members** —
> gênero-neutro, position counter removido), PR #137 (**catálogo de presentes
> virtuais** M.13.6 — 6 presentes fixos da Limen múltiplos de 4, split 75/25,
> idempotência por remetente). **Deploy de staging pendente** para as entregas. A
> tag é marco de código, não de go-live (ver abaixo). **Ainda no backlog de
> monetização (foi para Sprint 15):** live pública e chamada privada (LiveKit),
> gorjeta/presente durante a live com animação.

> **Sprint 13 fechou com 5 entregas** (tag `v1.0-sprint13`, `1d63371`): PR #125
> (**Refactor de roles** — `moderador` separado de `admin`, fila dedicada
> `/moderacao/*`), PR #126 (**Evidence viewer** — visualizador da prova retida na
> fila de moderação), PR #127 (**Múltiplas localizações** — até 3 por performer,
> com migração das linhas existentes), PR #128 (**Photo permissions** — foto da
> galeria pública/privada + sistema de grant por FanAlias — § abaixo), PR #129
> (**Stories feed carousel** — a UI que consome `stories.feed` no topo do catálogo
> — § abaixo). **Também nesta janela:** o **modelo de monetização** foi fechado e
> documentado como referência canônica (commit `f6aa9a3`, § acima). **Deploy de
> staging pendente** para as 5 entregas. A tag é marco de código, não de go-live
> (ver abaixo).

> **Sprint 12 fechou com 3 entregas** (tag `v1.0-sprint12`, `f23368a`): PR #122
> (fix da ordem de posse no `deploy.sh` manual), PR #123 (**Convite via Stories** —
> `is_invite`, teto de 2 convites ativos, selo no feed do membro sem chat — §
> abaixo), PR #124 (**Salvar busca** — combinações de filtros do catálogo, cap 10,
> § abaixo). **Deploy de staging pendente:** os PRs #123 e #124 **ainda NÃO foram
> para staging** (o #122 é script manual, não muda o que roda). A tag é marco de
> código, não de go-live (ver abaixo).

> **Sprint 11 fechou com 4 entregas** (tag `v1.0-sprint11`, `11354b4`): PR #118
> (Login OTP passwordless), PR #119 (badge "Disponível para conversa"), PR #120
> (Notas privadas de membros), PR #121 (Boost pago) — §§ abaixo. **Deploy de
> staging pendente:** só o PR #118 (OTP) foi deployado; **#119, #120 e #121 ainda
> NÃO foram para staging.** A tag é marco de código, não de go-live (ver abaixo).

> **Tag é marco, nunca carimbo de go-live.** `v1.0-sprint9` (`57aab21`) fecha o
> arco Sprint 9 inteiro (9A + 9B + 9C) e é **anterior** ao PR #110 — aponta para
> um estado em que os 4 🔴 da Foto Efêmera ainda estavam abertos.
> `v1.0-sprint9.1` (`49ef728`) é o mesmo arco com eles fechados. **Nenhuma das
> duas libera nada.**
>
> Sobre os nomes: `v1.0-sprint9` **não** é "a versão sem sufixo" da
> `v1.0-sprint9a` — é o fecho do arco, e a ordem real é 9a → 9 → 9.1.
> **Não existem `v1.0-sprint9b` nem `v1.0-sprint9c`, e não é para criar:** o 9B
> não fechou como sprint, e por isso o sufixo do fix é `.1` e não `b`.

> **A Foto Efêmera do Membro está implementada, SEM BLOQUEADOR, e NÃO liberada.**
> Existe ponta a ponta (PRs #101–#104) e os **4 bloqueadores 🔴 foram fechados no
> PR #110** — denúncia, retenção da prova, audit log e `canMemberSendTo` como
> fonte única, mais os achados da revisão de segurança rodada sobre ele.
> **Ligar para usuário real é decisão do PO**, e continua valendo tudo o que a
> § da feature diz sobre a natureza dela: é des-anonimização consentida, e o
> rosto é uma chave de join global que o TTL não protege.

**O Sprint 9C entregou Stories da Performer** (PRs #105–#108, § abaixo) e começou
pelos 🔴, como mandava a regra: os **7 bloqueadores** da pré-análise
(`SECURITY_ISSUES.md`, § 2.1–2.7) foram endereçados, e o **pipeline de moderação
subiu antes do primeiro upload** (denúncia + quarentena + `content_hash`).

**Histórico do que estava travado desde o Sprint 10 (hoje resolvido):**
1. ~~**O refactor de `role` NÃO foi feito**~~ — **feito no Sprint 13 (PR #125):**
   `moderador` foi separado de `admin` e a fila humana passou a ser `/moderacao/*`
   (dedicada), com o **evidence viewer** da prova retida no PR #126. Destrava o
   **Curador das FC Sessions**. **Ressalva:** trechos mais antigos deste arquivo
   ainda descrevem "moderador = admin, fila `/admin/reports`" — são históricos e
   valem até o Sprint 13; a fila viva é `/moderacao/*`.
2. ~~**Os 4 🔴 da Foto Efêmera**~~ — **fechados** no
   **PR #110** (denúncia, quarentena, audit e a extração de
   `canMemberSendTo`), reusando o caminho que o PR #108 abriu para o story. A
   feature deixou de ter bloqueador; **ligar para usuário real continua sendo
   decisão do PO**, e os 🟡 residuais estão na seção da Foto Efêmera.

> **Numeração — só existe UMA: Sprint.** O trabalho fundacional era numerado por
> "Fase", e as duas sequências colidiam (a antiga Fase 3 e o Sprint 3 são coisas
> diferentes). Os rótulos de Fase foram **removidos**: a fundação virou lista por
> nome, e "Sprint N" agora aponta para uma coisa só. Docs antigos em `docs/`
> (`fase2-auth-api.md`, `fase4-perfis-catalogo.md`, o roadmap do handoff do
> Sprint 5) ainda falam em Fase — são históricos, e "Fase N" ali **não** é
> "Sprint N".

### Entregue — fundação (anterior aos Sprints)
- Fundação do repo + ambiente (MySQL/Docker).
- Modelo de dados + segurança de base (migrations, models, TokenService, seeder).
- Autenticação + cadastro (Sanctum API, register/login/logout/me, email verification, password reset, role middleware, policies, audit log).
- Compra de tokens + Asaas/PIX (cliente mockável, pagamento, webhook idempotente, reconciliação agendada).
- Perfis de performer, catálogo público e sistema de follows.
- Verificação KYC de performers (webhook Didit, resubmissão, documentos criptografados).
- Gorjetas (TipService, split, ledger append-only, idempotência, rate limit 10/min).
- Frontend Inertia + Vue 3 + Tailwind v4 (design system Limen, páginas Landing/Cadastro/Login/VerifyEmail/Catálogo, gate de idade, auth por sessão, Ziggy).
- Catálogo de performers no frontend (público e autenticado).

### Entregue — Sprints
- **Sprint 1** — fechamento de servidor (ASAAS Fake em staging, `performers:backfill-avatars`, sudoers do vendor).
- **Sprint 3** — **Interesse Controlado**: performer sinaliza, membro paga 15 tokens (100% plataforma) para desbloquear. Opt-out mascarado. Ver `docs/INTEREST_SYSTEM_SPEC.md`.
- **Sprint 4** — **Chat** interest-gated em tempo real (Reverb): janela de acesso paga, soft-delete LGPD.
- **Sprint 5** — KYC Didit real (`x-api-key`, webhook v3 `X-Signature-V2`), PCI SAQ-D (`docs/PCI_SAQ_D.md`), payout com porta de saída `needs_review` (alerta + requeue), trial de 7 dias dos Founding Members, `ExpireSubscriptions` por `next_due_date`, **Piso de Anonimato + Modo Discreto + mitigação de sybil** (§ abaixo).
- **Sprint 6** — Age Verification (CPF+DOB), **FanAlias**, aceite de documentos, Panic Button, shared-IP flag, Report, Hard Delete LGPD, Ghost Mode / Read Receipts / painel de visitantes (k=3), **2FA TOTP**, geobloqueio, filtro de chat (§§ abaixo).
- **Sprint 7** — tier da performer + grant admin, KYC no onboarding web, painel admin de KYC, múltiplos mundos (`worlds`), **Git Flow obrigatório**.
- **Sprint 8** — status `banned` + sessão viva, lista negra antifraude (hash), **KYC Nível 2 do membro**, edição de `worlds`, revisão de segurança pré-Sprint 9.
- **Sprint 9A** — UX e descoberta: tags e campos da performer, interesses do membro, filtros do catálogo, badges, localização opt-in (só UF, e some com `is_live`), hCaptcha, e-mail do fundador, onboarding, camada reservada do PanicButton.
- **Sprint 9B** (SEM TAG, não fechado) — **Foto Efêmera do Membro** (§ abaixo): `ImageProcessingService`, storage cifrado, expiração, endpoints e UI de chat, GC. **Implementada, não liberada.**
- **Sprint 9C** — **Stories da Performer** (§ abaixo), tag `v1.0-sprint9`: publicação com TTL fixo de 24h e 3 níveis de visibilidade, feed e serving autenticados, ponto dourado no catálogo, e a moderação junto (denúncia, quarentena, `content_hash`, `DeletionService` nos dois sentidos).
- **Sprint 10** — descoberta e perfil, tag `v1.0-sprint10` (PRs #111–#117, deploy de staging): **Estilos de Vida** (6 faixas opt-in, sem filtro, Modo Discreto suprime, fora do painel de visitantes), **Favoritos** (bookmark privado — § abaixo), "Sobre mim" no perfil público, "visto por último" em faixa (Ghost Mode suprime a escrita), barra de progresso do perfil, **galeria de fotos** (carrossel 6, EXIF strip, pública).
- **Sprint 11** — FECHADO, tag `v1.0-sprint11` (`11354b4`), 4 entregas: **Login OTP passwordless** (§ abaixo, PR #118: código de 6 dígitos por e-mail, 5 min, uso único, 5 palpites, 3/hora; web + API, convive com o login por senha; 2FA da performer se aplica depois; `otp:purge` GC horário); **badge "Disponível para conversa"** (PR #119, `available_for_chat_at` no perfil, janela de 4h com auto-expiração na leitura); **Notas privadas da performer sobre membros** (§ abaixo, PR #120: nota por FanAlias, cifrada, o membro nunca vê); **Boost pago** (§ abaixo, PR #121: 50 tokens, 6h, ledger append-only `spend_boost`, destaca o perfil no topo do catálogo). **Deploy de staging: só o PR #118 subiu; #119–#121 pendentes.** O resto do backlog do Sprint 11 (convite via Stories, videochamada LiveKit) **não foi iniciado**.
- **Sprint 12** — FECHADO, tag `v1.0-sprint12` (`f23368a`), 3 entregas: **fix da ordem de posse no `deploy.sh` manual** (PR #122: chown de `storage/` antes do `git pull` e de `public/build/` antes do `npm run build`, espelhando a hardening que o workflow de CI já tinha); **Convite via Stories** (§ abaixo, PR #123: `is_invite` no story, teto de 2 convites ativos por performer sob leitura, selo "💌 Convite" no feed só para o seguidor SEM chat — `ChatAccessService::memberHasChatWith` como dona; sem lista de "quem recebeu"); **Salvar busca** (§ abaixo, PR #124: o membro guarda combinações de filtros do catálogo, cap 10 sob lock, allowlist derivado de `filterRules()`, privado do membro, varrido no Hard Delete). **Deploy de staging: #123 e #124 pendentes** (#122 é script manual). Não iniciados do backlog: refactor de roles, videochamada LiveKit, e a **tela de feed que consome `stories.feed`** (o endpoint existe e é testado, mas sem consumidor Vue — o selo do convite depende dela).
- **Sprint 13** — FECHADO, tag `v1.0-sprint13` (`1d63371`), 5 entregas: **Refactor de roles** (PR #125: `moderador` separado de `admin`, fila humana dedicada `/moderacao/*` sob `role:moderador`, em vez do antigo `/admin/reports` sob `role:admin` — pré-requisito da fila de moderação de verdade e do Curador das FC Sessions); **Evidence viewer** (PR #126: a fila de moderação passou a exibir a PROVA retida — bytes congelados de Story/Foto Efêmera denunciados —, fechando o achado da revisão de 30/07 "a fila não tem como VER a prova"); **Múltiplas localizações** (PR #127: até 3 por performer, com migração das linhas de UF única existentes; só UF é público, `city` segue interno — mesma regra da localização opt-in do Sprint 9A); **Photo permissions** (§ abaixo, PR #128: cada foto da galeria pode ser pública ou privada; foto privada aparece borrada no perfil e só é servida a quem tem `photo_grant` aprovado — ou à dona; o membro solicita, a performer aprova/revoga pelo FanAlias, `member_id` nunca vaza; Hard Delete nos dois sentidos); **Stories feed carousel** (§ abaixo, PR #129: a UI que consome `stories.feed` — carrossel tipo Instagram no topo do catálogo, buscado por fetch para não pagar o `canView` por story no caminho crítico; o selo do Convite via Stories do Sprint 12 finalmente tem tela). **Deploy de staging: as 5 pendentes.** Fora das PRs, nesta janela o **modelo de monetização** foi fechado e documentado (§ "Modelo de monetização — DECISÕES FECHADAS", commit `f6aa9a3`).
- **Sprint 14** — FECHADO, tag `v1.0-sprint14` (`0f6aefb`), 8 entregas — a **implementação do modelo de monetização M.13**: **PR #130** (invariantes M.13: `config/monetization.php` como fonte canônica dos números + `TokenCreditPolicy` dona única de teto por entry_type/M.13.9, fila de pendência/M.13.8, split round-half-up com `applied_rate` congelado/M.13.7, sinais de chat/gift/payout; migrations de `token_ledger.applied_rate` e `token_wallets.pending_grant_tokens`); **PR #131** (rewire de gorjeta/pacotes/desconto para M.13: `TipService` usa `policy.applyRate/creditWithSplit` 80/20 por evento e dropou `split_pct`, desconto de compra vem da config M.13.3, pacotes achatados M.13.2 no seeder); **PR #132** (chat M.13.1: fim do chat grátis de assinante — todo tier paga abertura via `policy.chatCost`, performer +1 token FIXO `chat_access_credit` never-cap, `memberHasChatWith` virou só `ChatAccess::exists`); **PR #133** (subscription grant com fila de pendência M.13.4/M.13.8: franquia mensal com teto escalonado, webhook de cobrança PRIMÁRIO + `subscriptions:grant-monthly` como rede de reconciliação, marca por-ciclo `last_grant_period_start` fecha o double-grant); **PR #134** (payout mensal R$0,60/token M.13.5/M.10: `calculatePayoutCentavos` = `tokens × 60`, sweep `payouts:process-monthly` dia 1 idempotente por (performer, ano, mês) + on-demand, **só ganhos sacáveis** via allowlist estrito, não paga banida); **PR #135** (conteúdo permanente com acesso por tier M.13.13/M.4 — § abaixo: foto v1, níveis Aberto/Premium/Exclusivo/FC Only, desbloqueio permanente via `ContentUnlock`, `ContentVisibilityService` dona única, split 80/20, denunciável, Hard Delete dois sentidos); **PR #136** (fix de copy dos founding members: gênero-neutro, position counter removido); **PR #137** (catálogo de presentes virtuais M.13.6 — § abaixo: 6 presentes fixos da Limen múltiplos de 4, `GiftService` espelha Tip/ContentUnlock, split 75/25 `applied_rate=75`, idempotência por remetente via UNIQUE composto, performer só vê FanAlias, `gift_credit` no allowlist de payout). **Deploy de staging: as 8 pendentes.** Não iniciado (foi para Sprint 15): live/chamada LiveKit, gorjeta/presente na live com animação, feed de conteúdo permanente, verificação de documento, sanitização de upload de vídeo.
- **Sprint 15** — FECHADO, tag `v1.0-sprint15` (`bf1c3dd`), 8 entregas — **vídeo em tempo real (LiveKit)**, planejado desde a fundação e finalmente implementado: **PR #138** (infra LiveKit + token service: `LiveKitService` dona única de rooms/JWTs HS256 assinados localmente, `config/livekit.php`, `config/features.php` com flags de dark launch, `feature:*` middleware, identity OPACA por live e FanAlias handle por par na chamada, room_name imprevisível nunca em URL/log/resposta, backstop interno da flag no createRoom/generateToken); **PR #139** (live pública GRÁTIS com gorjeta/presente: `LiveSession`/`LiveSessionService`, serving autorizado por sessão sem URL assinada, reconciliação na leitura da live abandonada, badge "AO VIVO" + ordenação no catálogo, gorjeta 80/20 e presente 75/25 pelas rotas existentes; sem `Crypt` de propósito — 1:N); **PR #140** (chamada privada 1:1 com cobrança por minuto: split 70/30 `applied_rate=70`, request→accept→active, heartbeat pré-pago idempotente por minuto via `minutes_billed`, `MinuteBiller` como motor único, saldo nunca negativo, exclusividade do membro sob lock-âncora, ban/kill-switch, `calls:expire-pending` + `calls:reap-stale`); **PR #141** (group show 1:X + upgrade para 1:1: 1 `call_sessions` `type=group` com `member_id` nullable + N `call_session_participants` de cobrança independente, `MinuteBiller` compartilhado com o 1:1, upgrade que vira `type=private` + revoke dos outros por job de 10s, exclusividade bidirecional 1:1↔group, `closeForMember`); **PR #142** (animação de gorjeta/presente na live: evento broadcast `LiveReaction` no canal privado `live.{slug}` disparado pós-commit pelo Tip/GiftService só durante live ativa, `<LiveOverlay>` com fila sequencial, payload não-sensível — FanAlias label, valor, tipo, nunca member_id/saldo); **PR #143** (preview animado no catálogo: frame JPEG por sessão capturado do canvas do `<LiveRoom>` a cada 10s, validação sem decode server-side, disco privado `live_previews` fora do backup, serving por ServesPhotoBytes autenticado, delete no fim da live + `live-previews:purge`); **PR #144** (toast global estilo Seeking: `<MessageToast>` no AppLayout escuta `user.{id}`, `NewMessage` ganhou `sender_name`/`sender_avatar_url` mascarados por destinatário — FanAlias à performer, stage_name+avatar ao membro —, nunca o corpo, máx. 3 empilhados, auto-dismiss 8s); **PR #145** (**"Em breve"** em produção: flags `features.live_enabled`/`features.call_enabled` compartilhadas como props Inertia globais, `<ComingSoon>`, badge/hover do card gateados na flag, varredura de teste garantindo que TODA rota de live/call/group carrega `feature:*`). **Resolução do § 2.5:** o serving sem cifra em memória que travou as FC Sessions **deixou de existir por arquitetura** — não há serving HTTP de bytes de vídeo; o LiveKit SFU faz o relay via WebRTC (DTLS-SRTP), o backend só emite tokens JWT e controla permissão. **Deploy de staging pendente**; sobe com as flags **off** (liberação é jurídica, muda só o `.env`). Não iniciado (foi para Sprint 16): feed de conteúdo permanente, sanitização de upload de vídeo, verificação de documento, animações elaboradas de presente, preview via WebRTC real, som de notificação.
- **Sprint 16** — FECHADO, `main` em `37d8cec`, **sem tag** (16 PRs, #151–#166) — duas frentes: **redesign maison** do front (design system `limen-*`) e **superfícies novas de produto**. **PR #151** (fix: PanicButton estava invisível para o membro em certas telas); **PR #152** (card de catálogo v2 nos tokens `limen-*`); **PR #153** (landing redesenhada); **PR #154** (grid v2 + trilha "Agora" agregando lives/stories no topo + slot de Destaque do Boost); **PR #155** (PanicButton vira **link de texto no header** — o disco flutuante sozinho era lido como "fechar" e o membro não achava a saída; achado do UAT — § "PanicButton"); **PR #156** (**crop interativo** de avatar 1:1 e capa 3:1 com cropperjs; o avatar passou a ser higienizado pelo `ImageProcessingService` como o resto); **PR #157** (fix de ruído de log: reconciliação pula pagamentos `pay_fake_` que davam 404 no Asaas, e `LivePreviewService::purgeOrphans` protege contra disco ausente); **PR #158** (perfil da performer redesenhado na estética maison); **PR #159** (**feed do membro** `/feed` — consome o conteúdo permanente do PR #135; `FeedController`, desbloqueio/serving reusam `content.*`); **PR #160** (**dashboard admin de receita** `/admin/dashboard` — `AdminMetricsService`, agregados do ledger + contadores + payouts em `needs_review`, `role:admin` (moderador não vê receita), **zero PII de membro**); **PR #161** (**anti-CSAM MVP** — `CsamScanService`/`PerceptualHashService`, dHash em toda imagem no upload nos 6 caminhos, § abaixo); **PR #162** (preview WebRTC real no hover do card, v2 do snapshot JPEG); **PR #163** (animações de presente por partícula/sprite no `<LiveOverlay>`, v2 da CSS simples); **PR #164** (**receita real**: o dashboard soma `payments` confirmados por `confirmed_at`, não estimativa do ledger); **PR #165** (**catálogo de membros para a performer** — `MemberCatalogService`, Interesse Controlado INVERTIDO, § abaixo); **PR #166** (**som de notificação + preferências** — `users.notification_preferences` JSON, § abaixo). **Mergeados após o fecho inicial (PRs #167/#168, `main` em `55de8cd`):** sanitização de upload de vídeo (PR #167 — `performer_content.kind` ganhou `'video'` + coluna `status`; ffmpeg re-encode H.264/AAC) e o selo de curadoria (PR #168). **Não iniciados:** verificação de documento (Didit), hCaptcha em produção, pin PHP 8.5→8.4 no `deploy.yml`. Deploy de staging pendente para tudo.
- Fora da trilha numerada: **Waitlist** (double opt-in, drip, painel admin) e **Círculos** (assinaturas por tier — Fase A Explorador→Prestige, Fase B Black/FC).

> **Sprint 2 não tem registro** nos docs; a numeração pula de 1 para 3 de propósito.
> Não é lacuna de documentação a preencher — é como o histórico ficou.

> **Sprint 13 (registrado como backlog) foi ENTREGUE** — o refactor de roles, o
> evidence viewer, as múltiplas localizações, as permissões de foto e o feed UI
> viraram os PRs #125–#129 (ver a lista de Sprints acima). O que ficou de fora
> daquele backlog e ainda vale carregou para o Sprint 14.

> **Sprint 14 (registrado como backlog) foi ENTREGUE** — a implementação do
> modelo de monetização M.13 (invariantes, rewire de tip/pacotes/chat, grant com
> pendência, payout mensal, conteúdo permanente, presentes) virou os PRs #130–#137
> (ver a lista de Sprints acima). O que ficou de fora daquele backlog — tudo que
> depende de LiveKit (live, chamada, gorjeta/presente na live) mais a verificação
> de documento e a sanitização de vídeo — carregou para o Sprint 15 abaixo.

### Sprint 15 — FECHADO
Todo o bloco de **vídeo em tempo real (LiveKit)** — live pública, chamada 1:1,
group show 1:X, gorjeta/presente na live com animação — foi entregue nos PRs
#138–#145 (ver "Entregue — Sprints" acima e o § "Estado atual"). O **§ 2.5 está
resolvido** (não há serving HTTP de bytes de vídeo; o LiveKit SFU faz o relay via
WebRTC/DTLS-SRTP e o backend só emite tokens JWT + controla permissão). O que ficou
de fora daquele backlog carregou para o Sprint 16 abaixo.

### Sprint 16 — FECHADO (PRs #151–#166 + #167/#168, `main` em `55de8cd`, sem tag)
O que era backlog do Sprint 16 foi entregue. Os dois itens que fecharam em branch
não-mergeada (vídeo e selo de curadoria) foram mergeados APÓS o fecho inicial —
PRs #167 (`feat/video-sanitization`) e #168 (`feat/profile-curation-seal`), `main`
em `55de8cd`. Estado item a item:

- ✅ **Feed/timeline de conteúdo permanente** — entregue (PR #159): rota `/feed`
  (`FeedController`, `throttle:60,1`, grupo de membro verificado), consome o backend
  do PR #135; desbloqueio/serving reusam `content.*`.
- ✅ **Sanitização de upload de vídeo** — **entregue (PR #167, `main` em `55de8cd`).**
  Pipeline ffmpeg: job assíncrono `ProcessVideoContent` re-encoda para H.264/AAC a
  partir dos streams DECODIFICADOS (nunca stream copy), mapeia só o 1º vídeo + 1º
  áudio e derruba data/subtitle/attachment (`-dn -sn`) e toda metadata
  (`-map_metadata -1`, mata GPS/device); thumbnail auto ~1s (640px JPEG, fallback
  frame-0). Limites 500MB (Form Request) e 10min (ffprobe no upload → 422); não-vídeo
  → 422. Upload volta na hora com `status=processing`; só é servível em `status=ready`
  (o `ContentVisibilityService` e o feed exigem `ready`, então processing/failed
  nunca é visto/listado/desbloqueável — nem pela dona). ffmpeg ausente → fail-closed.
  `performer_content.kind` ganhou `'video'` + coluna `status` (migration
  `2026_08_10_000001`); `content.video` faz streaming (BinaryFile, Range/seek,
  `video/mp4`+nosniff). GC `content:purge-orphan-raw` (horário). Foto segue no
  pipeline GD síncrono. (NÃO se aplica ao vídeo ao vivo do LiveKit, que é relay
  WebRTC, não upload — § 2.5.)
- ❌ **Verificação de documento como produto** (R$ 9,90) — **não iniciada.** Depende
  da Didit (a mesma integração do KYC da performer).
- ✅ **Animações elaboradas de presente** — entregue (PR #163): sprites/partículas
  por presente no `<LiveOverlay>`, v2 da CSS animation simples do PR #142.
- ✅ **Preview via WebRTC real** — entregue (PR #162): stream real no hover do card,
  v2 do snapshot JPEG a cada 10s do PR #143.
- ✅ **Som de notificação + preferências** — entregue (PR #166): § abaixo,
  `users.notification_preferences` (JSON por-usuário).
- ✅ **Selo de curadoria "maison/select"** — **entregue (PR #168, `main` em `55de8cd`).**
  `<CurationSeal>` (fonte única) mostra o tier de curadoria como pílula dourada
  discreta ao lado do nome na `Catalog/Show` (membro) e na `Performers/Show` (guest),
  espelhando o card: Maison = pílula com borda, Select = pílula sutil preenchida.
  Renderiza só para tiers com selo (nada para os demais); usa a prop `performer.tier`
  já existente. **É a curadoria DA PERFORMER — nenhum tier de membro é exposto.**
- ❌ **hCaptcha habilitado em produção** — não feito; `HCAPTCHA_ENABLED` segue off.
- ❌ **Pin PHP 8.5→8.4 no `deploy.yml`** — não feito (exige token com escopo
  `workflow`; o servidor de dev não tem). Alvo de produção é 8.4.24.

Além do backlog, o Sprint 16 trouxe **superfícies novas não previstas ali**:
**catálogo de membros para a performer** (PR #165 — § abaixo), **anti-CSAM MVP**
(PR #161 — § abaixo), **dashboard admin de receita** (PRs #160/#164), e o **redesign
maison** do front com o design system `limen-*` (PRs #152–#158 — § "Convenções").

> **O "Toast notification estilo Seeking" já foi entregue** (PR #144, Sprint 15) —
> se aparecer em lista antiga de backlog, está feito.
