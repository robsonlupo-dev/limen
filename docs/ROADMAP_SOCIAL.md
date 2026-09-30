# Roadmap social ("estilo Insta") — desenho (proposta do CTO)

> **Status:** desenho em aprovação (27/09/2026). Traz para o Limen os recursos
> sociais que o Instagram tem e nós não — adaptados à economia de tokens, ao
> anonimato do membro e ao servidor atual (2 vCPU). Construção em **3 ondas**, em
> PRs pequenos, seguindo o rito do programa de indicação (desenho → travar → build
> → revisão de segurança → testes).
>
> **Decisões já travadas com o PO (27/09):**
> - Ordem: **Onda 1 primeiro** (stories/perfil), depois chat, depois vitrine/dados.
> - **Stories VIP:** gating por **tier mínimo** (Todos | Assinantes | a partir de
>   um tier). Os Círculos são **globais** (ver §0), então o gate é o tier global do
>   membro — não "assinar aquela performer".
> - **Canal de transmissão:** envia para os **seguidores** da performer (relação
>   `Follow`, que já existe), **sem resposta** no canal (quem quiser falar abre o
>   chat pago normal).
> - **Modo efêmero no chat:** **qualquer um dos dois** liga; vale para a conversa,
>   o outro lado vê o aviso.

## 0. Premissa que molda tudo: Círculo é GLOBAL, não por-performer

Confirmado no código: uma `Subscription` liga `user_id` + `circle_id` — **não há
`performer_id`**. Um Círculo é um tier da plataforma
(`explorador → insider → prestige → black → founders_circle`), não "assinar a
Fulana". Consequências de desenho:

- **"Assinantes de uma performer" não existe.** O público por-performer que existe
  é: quem **segue** (`Follow`), quem **favoritou** (`Favorite`), e quem já **pagou**
  algo a ela (`ChatAccess`, `Tip`, `ContentUnlock`, …).
- **Gating por tier** (stories VIP) usa o tier GLOBAL do membro
  (`User::activeCircle()->tierAtLeast($slug)`), reusando o que
  `PerformerStory::VISIBILITY_LEVELS` já faz (`subscribers` = qualquer tier,
  `exclusive` = black/FC).
- **Canal de transmissão** mira **seguidores** (`Follow`), não "assinantes".

## Visão geral das ondas

| Onda | Recursos | Infra que reaproveita |
|---|---|---|
| **1 — Perfil vivo** | Destaques · Stories VIP por tier · Status + contagem regressiva · Enquetes/"pergunte-me" · Reações rápidas | Pipeline de stories (`PerformerStory*`, `StoryViewer.vue`, `StoryVisibilityService`), FanAlias |
| **2 — Chat e retenção** | Canal de transmissão · Modo efêmero (vanish) | Reverb (já no ar), `Message.redacted_at`, `Follow` |
| **3 — Vitrine e dados** | Fixar conteúdo + coleções do membro · Insights da performer | Vitrine de conteúdo, `story_views`, ledger |

Cada onda vira 1–2 PRs pequenos. A Onda 1 está detalhada abaixo; Ondas 2 e 3 são
esboços que travamos quando chegar a vez.

---

# ONDA 1 — Perfil vivo

Dividida em dois PRs para manter cada entrega pequena:
- **1a — Perfil vivo:** Destaques + Stories VIP por tier + Status/contagem.
- **1b — Interatividade no story:** Enquetes/"pergunte-me" + Reações rápidas.

## 1a.1 — Destaques (Highlights)

Coleções permanentes de stories fixadas no perfil. O story morre em 24h e os bytes
são recolhidos pelo GC (`stories:purge`); um destaque **não pode** apenas apontar
para um story vivo. Como no Instagram, adicionar ao destaque **copia** a mídia para
um armazenamento permanente.

**Dados**
```
story_highlights
  id
  performer_profile_id   FK cascade
  title                  string (≤ config)
  cover_media_path       nullable (capa; default = 1ª mídia)
  sort_order             int
  timestamps

story_highlight_items
  id
  story_highlight_id     FK cascade
  media_path             CÓPIA permanente da mídia (disco 'highlights', fora do GC de story)
  content_hash           re-hash na cópia (moderação)
  visibility_level       herda do story (public | subscribers) + min_tier (§1a.2)
  min_tier               nullable slug
  source_story_id        nullable FK nullOnDelete (proveniência; o story pode expirar)
  sort_order             int
  timestamps
```

- **Serviço** `StoryHighlightService`: `createCollection`, `addStory` (copia a
  mídia via um `HighlightStore` espelhado no `PerformerStoryStore`, re-roda o
  anti-CSAM na cópia — barato e seguro), `reorder`, `removeItem`, `deleteCollection`.
- **Moderação:** a cópia mantém `content_hash`; se um story for removido por
  denúncia, um item de destaque derivado dele é sinalizado no mesmo fluxo.
- **UI performer:** gerência em `Performer/Stories` (criar coleção, capa, título,
  adicionar story atual/recente, reordenar, apagar).
- **UI pública:** fileira de capas circulares sob o cabeçalho em `Performers/Show.vue`
  (e `Catalog/Show.vue`); tocar abre o `StoryViewer` tocando a coleção. Respeita a
  visibilidade item a item (`StoryVisibilityService`).
- **Limites** (`config/stories.php`): máx. coleções por performer, máx. itens por
  coleção — proteger disco (2 vCPU / disco de 38 GB).

## 1a.2 — Stories VIP por tier mínimo ✅ (entregue, PR #285)

Hoje `PerformerStory.visibility_level` é `public | subscribers | exclusive`, com
`subscribers` = qualquer Círculo e `exclusive` = black/FC. O VIP adiciona um
**tier mínimo** ao nível `subscribers`, sem tocar no `visibility_level`.

**Como ficou (mais conservador que o esboço original, por segurança):** em vez de
substituir a semântica de `visibility_level` por `min_tier` (com backfill
`public→null`, `subscribers→explorador`, `exclusive→black`), `min_tier` entrou como
um refinamento **ORTOGONAL** ao nível. Motivo: o esboço reescreveria a fonte da
regra de paywall inteira num passo — risco alto num gate crítico —, e um
`subscribers` com `min_tier=black` teria contador sobre público Black, recriando o
oráculo de identificabilidade que a decisão nº 3 do PO fechou (por isso o Nível 3
não tem contador). A forma ortogonal mantém `visibility_level` como está e não
precisa de backfill.

- **Migração:** `performer_stories.min_tier` (nullable, string). `null` = hoje (o
  nível decide sozinho); slug = refinamento. Sem backfill (linhas existentes ficam
  `null`). Sem índice (predicado sempre secundário).
- **Teto abaixo de Black — `PerformerStory::SUBSCRIBER_MIN_TIERS = ['insider',
  'prestige']`.** `explorador` é redundante com "qualquer Círculo"; `black`/`FC`
  ficam no nível `exclusive` (que não tem contador). Validado no Form Request
  (`Rule::in` + `prohibited_unless:visibility_level,subscribers`) E no service (a 2ª
  porta não passa pelo Vue). `min_tier` fora do `$fillable` e do `$hidden` do model.
- **`StoryVisibilityService`** ganhou `tierGateAllows(?minTier, ?member)` (E lógico
  por cima do nível) e `satisfiedTiers(member)` (a forma paginável, para o SQL do
  pontinho). Aplicado igual nos QUATRO consumidores: serving (`canView`), feed
  (`feedFor`), pontinho do catálogo (`profileIdsWithUnseenStories`, a cláusula
  `min_tier IS NULL OR IN (satisfiedTiers)` ANDada) e faixa do perfil
  (`profileStripFor`). Fail-closed por `Circle::tierAtLeast`.
- **UI performer:** no painel, quando o nível é "Assinantes", aparece um segundo
  seletor **A partir de: Qualquer Círculo | Insider+ | Prestige+**. O card mostra
  "Assinantes — Prestige+". `min_tier` NUNCA vai para superfície de membro (o
  strip/feed dele recebe só `locked`).
- **Sem custo novo de token:** VIP story é gate de visibilidade, não cobrança.

## 1a.3 — Status do dia + contagem regressiva (Notes)

Bolha curta sobre o avatar: "aceitando chamadas até 23h", "live às 22h", com
lembrete opcional.

**Dados**
```
performer_statuses
  id
  performer_profile_id   FK cascade, UNIQUE (um status ativo por performer)
  body                   string curta (≤ config; passa pelos filtros anti-contato)
  countdown_at           nullable timestamp (alvo da contagem)
  countdown_label        nullable string ("Live", "Chamadas abrem")
  expires_at             timestamp (auto-some; default now()+config horas)
  timestamps
```

- **Expiração na LEITURA** (como story/foto efêmera): status com `expires_at` no
  passado não aparece; um `statuses:purge` só faz faxina de linha.
- **Validação:** `body` passa pelos mesmos filtros do apelido/chat (barra telefone,
  contato, rede social) — nada de virar canal de contato grátis.
- **UI:** bolha no `PerformerCard.vue` (catálogo) e no cabeçalho do perfil; a
  contagem regressiva é um componente que conta até `countdown_at` e some/zera ao
  chegar. Sem realtime (vai junto na carga do catálogo).
- **Ícone é SVG** (regra do PO); o texto do status é conteúdo.

## 1b.1 — Enquete no story ✅ (entregue, PR #287) + "pergunte-me" (decisão do PO)

Elemento interativo preso a um story. **Enquete** = a performer escreve pergunta +
opções fixas; o membro **toca** uma e vê o % (anônimo). Zero texto livre.

**Decisão do PO sobre "pergunte-me" (texto livre): CORTADO como canal próprio.**
Um campo de texto livre membro→performer seria contato grátis (o membro começaria
conversa ou passaria o contato sem pagar) — fura o modelo (chat é pago; mensagem de
catálogo é modelo pré-pronto justamente por isso). Se algum dia entrar "membro
pergunta à performer", o caminho é o **story-reply que já existe**
(`ChatService::sendMessage`, chat economy v2): grátis se já há janela de chat
aberta, **paga para abrir** se não há. Não se constrói um inbox de texto grátis.

**Dados (entregue — só a enquete)**
```
story_interactions
  id
  performer_story_id     FK cascade, UNIQUE (uma interação por story)
  type                   string ('poll' hoje; genérico p/ o futuro)
  prompt                 string
  options                JSON (as alternativas do poll)
  timestamps

story_poll_votes
  id
  story_interaction_id   FK cascade
  member_id              FK cascade
  option_index           unsignedTinyInteger
  timestamps
  UNIQUE(story_interaction_id, member_id)   -- 1 voto por membro, IMUTÁVEL
```

- **Mesma porta do paywall:** votar passa pelo `StoryVisibilityService::denialFor`
  (`StoryInteractionService`) — votar num story fora de alcance é 403, como a
  imagem. **Exclusivo não tem enquete** (superfície de audiência → oráculo da
  decisão nº 3): recusado no `attach` e no `vote`.
- **Agregado, nunca "quem votou":** `ownerView`/`batchMemberView` devolvem só a
  contagem por opção. Resultados só aparecem para o membro **depois** de votar.
  Ghost Mode/Modo Discreto NÃO entram no agregado (write-time guard, § 2.7).
- **Texto da performer filtrado:** pergunta e cada opção passam por `SafeProfileText`
  (anti-contato), como a bio/destaque. Editar a enquete zera os votos (recria).
- **UI:** overlay no `StoryViewer` (opção tocável → barra de % após votar), editor
  no painel (`StoryPollEditor`). Voto imutável; sem token. Revisão de segurança
  dedicada passou (10 invariantes + 1 MEDIUM e 2 LOW corrigidos).

## 1b.2 — Reações rápidas a stories ✅ (entregue, PR #286)

Reação leve que chega à performer como sinal, sem abrir chat — complementa o
"responder ao story".

**Como ficou (decisões do PO nesta entrega):**
- **Agregado, nunca "quem reagiu".** A performer vê, por story, uma FAIXA de
  membros únicos + o conjunto de emojis usados (`StoryReactionService::
  summaryForOwner`), reusando `PerformerProfile::followersLabelFor`. Sem lista por
  FanAlias — o anonimato do membro é piso; a lista fica como decisão de produto
  adiada, não o default.
- **Símbolo é SVG** (`ReactionIcon.vue`), não emoji — a regra do PO (emoji vira
  quadrado/renderiza diferente). Conjunto fixo em `config/stories.php`
  (`love/fire/wow/celebrate`), fonte única da validação e da UI.
- **Mesma porta do paywall:** reagir passa pelo `StoryVisibilityService::
  denialFor` — reagir a um story fora de alcance é 403, como a imagem. **Exclusivo
  não tem reação** (sem superfície de audiência — decisão nº 3; seria oráculo de
  "quem é Black").
- **Ghost Mode / Modo Discreto não entram no agregado:** a reação NÃO é gravada
  para quem tem o perk (write-time guard, § 2.7 — não gravar > gravar-e-filtrar).
- **Toggle:** uma reação por par (índice único); tocar a mesma remove, outra troca.
  Morre com o story (junto das views, no `destroy`).

**Dados**
```
story_reactions
  id
  performer_story_id     FK cascade
  member_id              FK cascade
  reaction               enum(conjunto fixo do config)
  created_at / updated_at
  UNIQUE(performer_story_id, member_id)   -- 1 reação por membro (troca a própria)
```

- **Sinal à performer:** contador + lista por FanAlias (mesma pegada da feature de
  "corações"); pode reusar o watermark `hearts_seen_at`.
- **Emoji:** é **conteúdo** (permitido); a botoeira de reação é UI → SVG. Testar
  render cross-device (regra do PO sobre emoji).

---

# ONDA 2 — Chat e retenção (esboço)

## 2.1 — Canal de transmissão da performer ✅ (entregue, PR #288 — v1 texto)

Mensagem 1-para-muitos para os **seguidores** (`Follow`), sem resposta no canal.

**Como ficou (v1):**
- **Só texto** (decisão do PO): `broadcast_messages` (performer_profile_id, body,
  timestamps). Imagem fica para uma PR seguinte (exigiria CSAM+storage; servidor 2 vCPU).
- **Persistência + leitura por HTTP + badge de não-visto.** O push em tempo real
  via **Reverb ficou para follow-up**: o servidor atual não comporta fan-out largo,
  e a retenção vem do persistido + o badge (`users.broadcasts_seen_at`, watermark
  único como o `hearts_seen_at`). A aba "Canais" (`consumer.channels.index`) lista
  os broadcasts das performers seguidas e de pé; abrir zera o badge.
- **Anti-contato é o ponto crítico:** o `body` passa por `SafeProfileText` (o
  filtro público) — um broadcast é o vetor de fuga de contato grátis (mandar o zap
  a todos os seguidores). **Sem resposta no canal:** o CTA "Conversar" leva ao chat
  pago. Fecha a fuga de contato grátis nas duas pontas.
- **Custo:** grátis para o seguidor; motor de retenção. Teto diário
  (`config/broadcast.php`, `max_per_day`) + `throttle:10,1` de burst.
- **Privacidade:** direção performer→seguidor; nada de membro atravessa (a entrega é
  derivada de `follows` na leitura, não uma lista de destinatários). A performer não
  aprende quem seguiu nem quem leu. Revisão de segurança dedicada passou (7
  invariantes; 3 LOW de polimento corrigidos/aceitos).

## 2.2 — Modo efêmero (vanish) no chat ✅ (v1 "ver-uma-vez" #289 → TIMER "tocar para ver" #292)

Mensagens somem da tela depois de vistas; o original é **retido para moderação**
(mesma disciplina do "desfazer envio", `redacted_at`).

> ✅ **DECISÃO DO PO ATENDIDA (28/09/2026).** O modelo que o PO queria —
> **"visível por X segundos após aberta"** (timer, estilo Snapchat) — foi ENTREGUE
> no PR #292, depois do upgrade do servidor (CX23→CX33, 4 vCPU / 8 GB). O "ver-uma-vez"
> do #289 foi substituído. Detalhe na subseção **"Timer (Onda 3)"** no fim desta §.

- **Ligar:** flag `ephemeral` na CONVERSA; qualquer um dos dois liga/desliga. Vale
  para as mensagens enviadas ENQUANTO ligado (cada mensagem carimba o próprio flag
  `messages.ephemeral` no envio); desligar não ressuscita o que já sumiu.
- **Sumiço:** de EXIBIÇÃO — some nas duas pontas depois de vista, nunca apagada de
  fato (prova para moderação; nunca some sob denúncia aberta). Presente nunca é
  efêmero (é dinheiro).

**Como ficou (v1 "ver-uma-vez", #289 — SUPERSEDIDO pelo timer abaixo):**
- **Schema:** `conversations.ephemeral` (bool) e `messages.ephemeral` (bool). A
  mensagem carimba o flag da conversa NO ENVIO (`ChatService::sendMessage`/
  `sendVoiceMessage`); presente (`deliverGift`) nunca carimba (dinheiro não some).
  Desligar o modo depois não altera mensagem já enviada (`ephemeral` é imutável por
  mensagem).
- **Toggle:** `POST /chat/{conversation}/efemero` (`chat.ephemeral.toggle`,
  throttle 30/min), `ChatService::setEphemeral` — confere participação, `Audit`
  `chat.ephemeral_set`. Botão no cabeçalho do chat (ícone olho-cortado, SVG), os
  dois participantes controlam.
- **Sumiço = derivado de `read_at`, sem job** (`ChatController::ephemeralVanished`):
  para o REMETENTE some quando o outro leu; para o DESTINATÁRIO some depois de vista
  (lida num request ANTERIOR — a de "agora" ainda aparece via `$justReadIds`). Só de
  EXIBIÇÃO: `body`/áudio ficam no banco; a moderação lê pelos endpoints de evidência.
- **Revisão de segurança dedicada (fechada antes do ship) — 3 vazamentos corrigidos:**
  - **CRÍTICO:** a URL direta do áudio (`chat.audio`) reexibia os bytes de uma
    efêmera já sumida. Fechado: `audio()` também barra por `ephemeralVanished`
    (`justReadIds` vazio — fetch de bytes nunca é a primeira leitura). Regressão em
    `EphemeralChatTest`.
  - **ALTO:** o preview de 60 chars da LISTA de conversas (`index()`) vazava o corpo
    de uma efêmera já vista (e de uma redigida). Fechado: `previewHidden()` zera o
    preview quando a última mensagem é redigida ou efêmera-já-lida.
  - **MÉDIO:** a marcação de lida varria TODAS as não-lidas, mas a tela só renderiza
    20 — uma efêmera de página seguinte ganhava `read_at` e sumia sem ser vista.
    Fechado: efêmera só é marcada quando REALMENTE renderizada na página
    (`ephemeral=false OR id IN <renderizadas>`); texto normal segue marcando tudo
    (o badge do index depende disso). Regressão em `EphemeralChatTest`.
- **Front:** `Chat/Show.vue` — balão "Mensagem efêmera" (ícone olho-cortado) para
  `m.vanished`, antes do balão "apagada"; toggle no cabeçalho.
- Não mexe em token. Feature sem flag própria (é comportamento do chat, ligável por
  conversa pelos próprios participantes). **Fecha a Onda 2.**

**Timer (Onda 3, #292 — "tocar para ver + X seg", substitui o "ver-uma-vez"):**
- **Modelo:** a efêmera chega **SELADA** para o destinatário ("Mensagem efêmera —
  toque para ver"); o **corpo/áudio NÃO trafega** até o toque (mais seguro que o v1,
  que mandava o corpo na 1ª abertura). Ao tocar, o servidor **consome** e devolve o
  conteúdo UMA vez; o cliente mostra por **X seg** (`config/chat.php`
  `ephemeral_reveal_seconds`, padrão 10) com contagem, e some. O **remetente** vê o
  próprio corpo até ser consumido; depois "expirada" nas duas pontas.
- **Revelar = consumir, sem job:** coluna `messages.revealed_at` (imutável) é a fonte
  única. Uma vez revelada, some da exibição nas duas pontas em qualquer load — a
  contagem de X seg é **client-side**; o servidor não precisa de relógio/reaper. Um
  cliente adulterado que pule a contagem ainda pega `vanished` no reload.
- **Endpoints/regra:** `POST /chat/{c}/mensagem/{m}/revelar` (`chat.ephemeral.reveal`)
  → `ChatService::revealEphemeral` (só o DESTINATÁRIO; não-revelável=404, já
  consumida=410; paywall no controller como no áudio). `show()` não marca efêmera como
  lida ao abrir (só o reveal marca); helpers `ephemeralSealed`/`ephemeralVanished`/
  `ephemeralAudioServable` (janela do áudio = max(seg, duração)+folga).
- **Revisão de segurança dedicada — 1 ALTO fechado:** o broadcast em tempo real de
  nova mensagem (`broadcastListUpdate`) mandava o preview de 60 chars do corpo efêmero
  ao destinatário, que leria no toast/lista **sem tocar para revelar** (nunca
  consumia). Fechado: efêmera → `preview=null` no broadcast (espelha o `previewHidden`
  do index). Regressão em `EphemeralChatTest`. Demais invariantes OK (corpo só via
  reveal; só o destinatário revela; imutável; paywall; retenção p/ moderação).
- **Front:** `Chat/Show.vue` — bolha selada com botão "toque para ver" → conteúdo +
  contagem "some em Ns" → "expirada"; remetente vê "· efêmera" na própria mensagem.
- **Polimento pós-validação (#293):** (a) **sumiço em tempo real para o remetente** —
  ao revelar, o servidor emite `MessageRevealed` (só metadado) no canal
  `conversation.{id}`; a bolha do remetente vira "expirada" na hora, sem precisar de
  reload (antes só sumia no próximo load). (b) **player de áudio** revelado em largura
  cheia + `controlsList="noplaybackrate nodownload"` — os controles nativos não se
  amontoam mais nem cobrem o play.
- Não mexe em token.

---

# ONDA 3 — Vitrine e dados (esboço)

## 3.1 — Fixar conteúdo + coleções do membro

- **Performer — fixar conteúdo ✅ (entregue, PR #290).** A performer prende peças no
  topo da vitrine.
  - **Fonte única:** coluna `performer_content.pinned_at` (nullable). Fixado =
    `pinned_at IS NOT NULL`; a ordem entre fixados é o próprio `pinned_at` desc (a
    última fixada sobe). Sem `is_pinned` separado — um bool derivado poderia divergir
    do timestamp.
  - **Ordenação ÚNICA** (`PerformerContent::scopeOrderedForShowcase`): fixadas
    primeiro, depois por id desc. A MESMA para a vitrine pública
    (`ContentVisibilityService::galleryFor`) e o painel da performer
    (`PerformerContentService::forOwner`) — se divergissem, "fixar" mostraria uma
    ordem para a dona e outra para o público.
  - **Teto** `config/content.php` `max_pinned` (3, estilo Insta). Soft-cap (conta-e-
    grava, como o teto do canal): é ação da própria performer sobre a própria vitrine,
    sem impacto de token nem de acesso.
  - **Só a DONA** fixa a própria peça (senão 404, máscara `offline`); **só peça
    PRONTA** (vídeo em processing/failed não vai ao topo); **sob denúncia aberta o
    "fixar" é congelado** (409, como a remoção) — desafixar segue liberado.
  - **Não muda paywall:** `setPinned` só escreve `pinned_at`; `canView` nunca olha
    `pinned_at`. Fixada que o espectador não alcança **fica no topo, BLOQUEADA**
    (decisão do PO) — vira gancho de conversão, sem regra de visibilidade nova.
    `ContentPresenter` expõe `pinned` (metadado público) para o selo "Destaque".
  - Não mexe em token.
- **Membro — Salvos ✅ (entregue, PR #291).** O membro salva PEÇAS de conteúdo numa
  lista privada. **v1 = uma lista "Salvos" única** (sem coleções nomeadas — fica para
  depois se houver demanda). A performer **nunca sabe**.
  - **Tabela `content_saves`** (`user_id` + `performer_content_id`, UNIQUE, só
    `created_at`) — irmã de `favorites`, MESMA disciplina de anonimato: **sem relação
    inversa** em `PerformerContent`/`PerformerProfile` (evita o vetor `withCount`),
    **sem contador** em superfície nenhuma, **nada em `audit_logs`** (aquela tabela
    sobrevive ao Hard Delete com o IP em claro). `ContentSave` + `ContentSaveService`
    (dona única).
  - **Salvar exige PODER VER a peça** (decisão do PO): `setSaved(on:true)` passa por
    `ContentVisibilityService::canView` → 403 se bloqueada. Não vira lista de desejos
    que revelaria intenção. Desalvar é sempre permitido; toggle idempotente (lock +
    UNIQUE, como o favorito).
  - **A performer não tem superfície nenhuma:** `galleryFor` só marca `saved` para o
    CONSUMIDOR dono das linhas (nunca para a performer/guest); o bookmark na vitrine
    só aparece em tile visível. Aba **"Salvos"** em Descobrir (`saved.index`), item do
    feed reresolvido por espectador (paywall intacto). Performer fora do ar some da
    lista; o salvo continua guardado.
  - **Hard Delete (LGPD):** `DeletionService::purgeContentSaves` apaga o mapa do
    titular (a FK não cascateia — `users` é anonimizado, não deletado); o lado da
    performer cascateia pelo DELETE real das peças. Não mexe em token.
  - **(Coleções NOMEADAS múltiplas ficam para um PR futuro, se houver demanda.)**

## 3.2 — Insights da performer ✅ (entregue, PR #294 — completo)

Painel só-leitura e AGREGADO: visão geral (visitas, seguidores, views de story,
ganhos, conversas, desbloqueios), funil **visita → chat → compra**, melhores horários
e evolução de ganhos. Deriva do que já existe (`profile_visits`, `follows`,
`story_views`, `token_ledger`, `chat_access`, `content_unlocks`) — **não cria tabela**.
`PerformerInsightsService` (dona única) + `Performer/Insights/Index.vue`, aba **Insights**
no Painel. **Fecha a Onda 3.**

- **Anonimato é piso (achado ALTO da revisão, fechado antes do ship):** o painel
  emitia contagens EXATAS de presença, o que reabria o canal lateral que banda/piso/
  k-anonimato do resto do produto fecham (o contador que sobe de 3→4 liga a visita à
  pessoa). Corrigido: **toda métrica de PRESENÇA sai em FAIXA** (`followersLabelFor`:
  "Menos de 5", "10+", …) — visitas, visitantes únicos, seguidores, views/viewers de
  story. Ações **PAGAS** (chat/compra) e **ganhos** são transações da própria performer
  → exatos. Story views **excluem `exclusive`** (audiência de exclusivo revelaria
  Black/FC, como o `viewCount()` que devolve null). "Melhores horários" vem em **4
  faixas de 6h** (nunca hora a hora), só participação (%), e **só com volume mínimo**;
  as **taxas** do funil só aparecem com base ≥ piso (senão "—"). Ghost/Discreto já
  ficam fora na ESCRITA de `profile_visits`/`story_views`, então o agregado não
  re-expõe presença.
- **Custo:** cada métrica é COUNT/SUM/GROUP indexado por (performer, janela); o payload
  inteiro é **cacheado** por (performer, janela) por ~15 min. Janela normalizada a 7/30
  ANTES da chave de cache (só 2 chaves por performer — um `window` lixo não fura o cache).
- Não mexe em token.

---

# Invariantes e cuidados transversais (valem para todas as ondas)

- **Anonimato do membro é piso, não feature** (CLAUDE.md princípio 1): toda
  atribuição visível à performer passa por `FanAlias`/`MemberDisplayName`; nunca
  nome real, e-mail ou CPF. Nada de comentário público, marcação ou corrente viral
  (quebram o k-anonimato — fora do escopo, de propósito).
- **Moderação e 18+:** toda mídia nova (highlight copiado, futura mídia de canal)
  passa pelo pipeline anti-CSAM + `content_hash`; nunca some sob denúncia aberta.
- **Rótulo/dinheiro vêm do servidor:** qualquer novo lançamento no extrato usa
  `LedgerEntryLabel` (nenhum recurso da Onda 1 credita/debita token — são sociais).
- **Ícone de UI é SVG, emoji só como conteúdo** (regra do PO).
- **Mobile-first 360–390px**, alvos ≥44px, `prefers-reduced-motion` honrado.
- **Servidor 2 vCPU / 3,7 GB / disco 38 GB:** limites de disco em tudo que copia
  mídia (destaques), rate-limit em fan-out de Reverb (canal), agregação com cache
  em insights. Nada de transcodificação pesada (por isso Reels fica fora).
- **Feature flags:** cada recurso atrás de um `config/*.php` (padrão do projeto),
  desligável, para ligar de forma controlada como o programa de indicação.
- **Ledger append-only e economia:** intocados — a Onda 1 não mexe em token.

# Checklist antes de construir a Onda 1

- [x] Ordem, gating de VIP, público do canal e efêmero travados com o PO.
- [x] **1a — Status do dia** (`performer_statuses`, `PerformerStatusService`,
      bolha + contagem, `config/stories.php`). PR mergeado.
- [x] **1a — Destaques** (`story_highlights`, `story_highlight_items`,
      `HighlightStore`, `StoryHighlightService`, gerência + fileira no perfil +
      viewer, testes Pest). MVP só de stories públicos.
- [x] **1a — Stories VIP por tier** (coluna `min_tier` ortogonal ao nível,
      `tierGateAllows`/`satisfiedTiers` nos 4 consumidores do paywall, teto abaixo
      de Black, seletor na publicação, testes Pest, revisão de segurança dedicada
      passou). PR #285.
- [x] **1b — Reações rápidas** (`story_reactions`, `StoryReactionService`,
      botoeira SVG no `StoryViewer`, agregado por faixa no painel). Reusa a porta do
      paywall (`canView`); sem reação no exclusivo; **agregado, nunca "quem reagiu"**;
      Ghost Mode/Discreto não entram no agregado (write-time guard, § 2.7). Testes
      Pest + revisão de segurança dedicada passaram. PR #286.
- [x] **1b — Enquete** (`story_interactions` + `story_poll_votes`,
      `StoryInteractionService`, overlay no `StoryViewer`, editor no painel).
      Mesma porta do paywall; sem enquete no exclusivo; agregado anônimo (nunca
      "quem votou"); voto imutável; Ghost/Discreto fora do agregado; texto por
      SafeProfileText. Testes Pest + revisão de segurança dedicada passaram. PR #287.
      **"Pergunte-me" (texto livre) CORTADO por decisão do PO** — vira contato grátis;
      o caminho de "membro pergunta" é o story-reply pago que já existe.
- [x] Registrar cada recurso em `docs/ARQUITETURA.md` e no mapa de features do
      `CLAUDE.md`.

**Onda 1 completa.** Próximas frentes: Onda 2 (canal de transmissão + modo efêmero)
e Onda 3 (fixar conteúdo + insights), ambas em esboço acima.

---

# ONDA 4 — Monetização direta (além do social)

Fechado o social (Ondas 1–3, engajamento/retenção), a Onda 4 CONVERTE a atenção em
caixa. Ver o doc de estratégia "Monetização Limen — Onda 4 & o fork da assinatura"
(comparação dos modelos global × fan-club × híbrido + as decisões abertas do fork).

## 4.1 — PPV no chat (conteúdo travado do cofre) ✅ (entregue, PR #296)

A **performer manda uma peça do cofre dela** (`performer_content`, já moderada)
TRAVADA na DM, com um **preço próprio**; o **membro paga tokens** para desbloquear.
Primeiro tijolo da Onda 4 e **model-agnostic** (serve global, fan-club ou híbrido) —
não mexe na Premissa 0.

Decisões travadas com o PO (29/09/2026):
- **Preço LIVRE com teto** (`monetization.ppv`: piso 5, passo 5, teto 5000;
  `TokenCreditPolicy::isValidPpvPrice`).
- **Só em chat ATIVO** — a performer só manda PPV dentro de uma conversa de pé.
- **Mantém o acesso pós-compra** — o desbloqueio é permanente (linha `content_unlocks`);
  o membro não é recobrado. (Ressalva de moderação herdada do conteúdo: peça de
  performer suspensa/banida para de ser servida mesmo a quem pagou — `canView` checa
  `performerIsReachable`; a linha do unlock permanece e volta a servir se ela reativar.)
- **Mídia do COFRE** (v1): foto/vídeo já moderados; upload novo no chat e voz-PPV
  ficam para uma próxima (evita um pipeline de mídia novo neste PR).

Arquitetura (reuso, superfície nova mínima):
- Colunas `messages.ppv_content_id` (FK `performer_content`, nullOnDelete) +
  `ppv_price_tokens` (a PRESENÇA do preço marca a mensagem como PPV — `isPpv()`, mesma
  convenção sem-coluna-de-tipo do áudio/presente).
- Desbloqueio (`ChatService::unlockPpvMessage` → `doUnlockPpv`) **espelha o
  `ContentUnlockService`**: trava as duas carteiras em ordem crescente de user_id,
  cobra o membro (`spend_ppv_message`), credita a performer 80/20
  (`ppv_message_credit`, rate `content`, `applied_rate` congelado), e grava a **MESMA
  linha `content_unlocks`** do cofre (UNIQUE peça×membro — nunca cobra duas vezes; e o
  membro passa a ver a peça também na galeria). `ppv_message_credit` é `*_credit`
  (nunca respeita teto; ENTRA no payout).
- **PPV é venda DIRIGIDA:** ignora o gate de TIER (a performer vende o exclusivo direto
  a este membro), mas mantém peça pronta + performer de pé + role consumer.
- Serving reusa o do conteúdo permanente: `content.image`/`content.video`
  (`canView`) para o membro destravado; `content.blur` (borrado irreversível) enquanto
  travado; a DONA vê pelo `performer.content.image` (as rotas de consumidor são
  `role:consumer`). A URL da mídia real **nunca** sai travada nem em broadcast.
- Rotas `chat.ppv.store` (performer) e `chat.ppv.unlock` (membro). Evento
  `MessagePpvUnlocked` no canal PRIVADO DO MEMBRO (`user.{id}`), nunca no da conversa
  (não vaza o id do membro à performer). Insights contam `ppv_message_credit`.

Revisão de segurança dedicada: nada CRÍTICO/ALTO/MÉDIO; 2 LOW corrigidos (bolha da
própria performer usava rota de consumidor → imagem quebrada; e o id do membro ia no
canal da conversa). Testes Pest (`PpvMessageTest`, prefixo `pv`): dinheiro 80/20,
idempotência, saldo insuficiente, autorização (só a dona envia, só peça dela, preço
válido, não-participante 404, dona não paga a própria), mídia não vaza travada, e o
override de tier.

## 4.2 — Metas de gorjeta (tip goals) ✅ (entregue, PR #298)

A performer define UMA meta ativa (título + alvo em tokens); uma **barra de progresso**
enche com as gorjetas recebidas, no **perfil** e no **overlay da live**. É leitura
AGREGADA por cima das gorjetas que já existem — **não cria tipo de ledger, não mexe em
token** (a gorjeta segue 80/20 pelo `TipService`).

Decisões travadas com o PO (29/09/2026):
- **Perfil + live** (as duas superfícies onde a gorjeta acontece).
- **Uma meta ativa por vez** (nova substitui a anterior; guarda histórico).
- **Toda gorjeta durante a meta conta** (progresso automático, sem o membro marcar nada).
- **Só tokens arrecadados + barra** — sem contagem de apoiadores (mais simples e sem
  canal lateral de anonimato).

Arquitetura:
- Tabela `tip_goals` (performer_profile_id, title, target, started_at, ended_at). Uma
  ativa garantida no `TipGoalService` (encerra a anterior antes de criar; sem UNIQUE dura
  — guarda histórico); `activeTipGoal` HasOne (`whereNull ended_at`, `latestOfMany`).
- **Progresso** = `SUM(tips.amount)` desde `started_at` (gross, por performer_profile_id)
  — número de mobilização; a gorjeta em si já é 80/20.
- Exposição: `PerformerPublicResource.tip_goal` (whenLoaded) no perfil; no live, o
  resource inicial + o evento `LiveTipGoalProgress` (`live.goal`, mesmo canal da
  `LiveReaction`) que o `LiveOverlayService` dispara a cada gorjeta durante a live.
- Editor no painel (`Performer/Profile/Edit`): título (SafeProfileText) + alvo
  (piso/teto de `monetization.tip_goal`), criar/encerrar (rotas `performer.tip-goal.*`).
- Componente `TipGoalBar.vue` (barra reutilizada no perfil e no overlay).

Anonimato (revisão dedicada — nada acima de LOW): o progresso é só **soma + alvo**,
nunca "quem" nem contagem de apoiadores (`raisedFor` é `SUM`, sem COUNT/DISTINCT);
`LiveTipGoalProgress` não leva nem o FanAlias. Testes Pest (`TipGoalTest`, prefixo `tg`).

## 4.3 — Encomenda sob medida com escrow ✅ (entregue, PR #299)

O membro **encomenda** conteúdo personalizado a uma performer por um preço que ele
**oferece**; a performer aceita ou recusa. O valor só sai da carteira do membro quando ela
**aceita** — e fica **retido (escrow)** até a entrega ser aprovada. É a primeira feature da
Onda 4 que movimenta um **fluxo de token em custódia**, então segue a mesma disciplina do
depósito da chamada agendada (`CallReservationService`): **sem saldo de "hold" genérico** —
a própria LINHA da encomenda é o registro do escrow.

Decisões travadas com o PO (29/09/2026):
- **Membro oferece, performer aceita/recusa** (não é a performer que publica um "cardápio").
- **Escrow no aceite da performer** (o débito do membro acontece aí; antes disso, zero token).
- **Liberação na entrega, com prazo de contestação** (o membro aprova, ou a janela vence e o
  cron libera; dentro da janela ele pode contestar → moderação).
- **A entrega é uma peça do cofre vinculada ao pedido** (`performer_content` com
  `custom_order_id`), moderada pelo MESMO pipeline (CSAM/ffmpeg) do resto do cofre.

Ciclo de vida (status em `custom_orders`):
`requested` → `accepted` (escrow) → `delivered` → `released` **ou** `refunded`;
ramos: `declined` (performer recusa), `cancelled` (membro cancela antes do aceite),
`expired` (ninguém aceitou a tempo), `disputed` (membro contesta → decisão humana).

Contabilidade do escrow (dona única: `CustomOrderService`):
- **HOLD** no aceite: débito `spend_custom_order` do membro (re-checa saldo sob lock do
  wallet; saldo < 0 é impossível). O `spend_ledger_id` fica na linha.
- **SETTLE** (uma vez só, guardado por `escrow_settled` re-lido sob `lockForUpdate`):
  - **release** → `custom_order_credit` 80/20 à performer (rate `content`);
  - **refund** → `custom_order_refund` 100% ao membro (fora do teto e fora do payout: é
    devolução, não ganho) + **revoga o `content_unlock`** do membro (reembolsado não fica
    com o conteúdo).
- Cada transição relê a linha sob lock e re-checa o estado; os broadcasts
  (`CustomOrderChanged`, canal `user.{id}` do destinatário, payload só `order_id`+`outcome`)
  saem **pós-commit**. Cada carteira é travada isoladamente (aceite trava a do membro;
  release trava a da performer; refund trava a do membro) — nunca as duas juntas, então não
  há deadlock cruzado.

Entrega e escopo da peça:
- `PerformerContentService::deliverCustomPiece` publica a peça como `EXCLUSIVE`, `price 0`,
  `custom_order_id` setado; foto fica pronta na hora (CSAM antes de gravar), vídeo vai para
  o job de sanitização.
- A peça personalizada **nunca vaza na vitrine**: `galleryFor` e `forOwner` filtram
  `whereNull('custom_order_id')`, e `denialForUnlock` recusa (OFFLINE) uma peça de encomenda
  — ninguém a desbloqueia pela porta normal. O membro recebe um `content_unlock` na entrega
  (precisa ver para aprovar/contestar); só ele a enxerga.

Cron (`custom-orders:process`, de 10 em 10 min, idempotente por `escrow_settled`):
expira pedidos não aceitos, estorna aceitos não entregues, e libera entregas passada a
janela de contestação — **estornando** (em vez de liberar) quando a mídia não ficou pronta,
para o membro nunca pagar por conteúdo que não pôde ver.

Disputa: o membro contesta dentro da janela → `disputed` (sai da varredura automática); um
**moderador** decide em `/moderacao/encomendas` (release à performer ou refund ao membro).
v1 é a fila + a decisão; UI rica de prova embutida é follow-up (a mídia é servida pelos
endpoints de conteúdo, que re-checam acesso).

Anonimato (M.13.10): a performer vê o membro **só por FanAlias** (`forPerformer`/
`forModeration`), nunca id/nome; o broadcast não leva member_id.

Revisão de segurança dedicada (escrow): **nada acima de MEDIUM**. O MEDIUM (membro podia
liberar escrow por vídeo ainda em processamento/falho) foi fechado — `approve` e
`can_approve` exigem mídia **pronta**, espelhando o estorno-se-não-pronta do cron. LOW
fechado: performer sumida entre aceite e liberação passa a **estornar o membro** em vez de
reter os tokens. Testes Pest (`CustomOrderTest`, prefixo `co`) cobrem dinheiro exato,
idempotência, estornos, cron, autorização de dono, anonimato e escopo da peça.

Preço: livre com piso/passo/teto (`monetization.custom_order`: 20 / passo 5 / 20000);
janelas e tetos anti-flood em `config/custom_order.php` (aceite 48h, entrega 168h,
contestação 72h; máx. 10 ativas/membro, 3 ativas/par).

### Marca d'água na mídia entregue (§4.3, PR próprio)

Proteção contra vazamento do conteúdo entregue: queima **"Fã #NNNN · dd/mm/aaaa"** (o
FanAlias por par + a data — nunca id/nome real) em **diagonal repetida e sutil** na foto e no
vídeo da entrega. Não impede ver, mas rastreia quem vazou e desencoraja — o único mecanismo
que de fato protege (borrar/prévia temporária é teatro: o byte já foi pro cliente).

Decisões travadas com o PO (29/09): **só encomendas sob medida** (não o cofre/PPV); **diagonal
repetida**; queima **na entrega** (permanente, sobrevive ao download); **depois** do scan CSAM;
texto = FanAlias + data; **fail-closed** (marca falhou → entrega falha / peça FAILED → estorno).

Arquitetura: `WatermarkService` (Intervention v3/GD) desenha o ladrilho diagonal; a **foto**
é marcada dentro do `ContentStore::store` (novo param opcional, aplicado após o scan); o
**vídeo** ganha um PNG transparente que o `ProcessVideoContent` gera e o ffmpeg sobrepõe com
`scale2ref`+`overlay` (cobre o frame real mesmo em vídeo retrato/rotacionado). Cofre e PPV
seguem **byte/comando-idênticos** (sem texto/overlay = caminho de sempre).

⚠️ **Kill-switch `custom_order.watermark.enabled` nasce DESLIGADO.** O caminho de vídeo
(overlay ffmpeg) não é coberto pela suíte e `limen.dev.br` == produção, então ligar só depois
de `php artisan custom-orders:watermark-selftest` (gera amostras para conferir) **e** de uma
entrega real de foto E de vídeo. Overlay limitado a lado ≤1920 (anti-OOM no worker de 2 vCPU).
A foto É coberta por `WatermarkTest` (roda no servidor, valida a API do Intervention).

**Próximos tijolos da Onda 4 (esboço):** extrato financeiro + previsão de saque · voz-PPV e
upload novo no chat. O **fork da assinatura** (global × fan-club × híbrido) é decisão à
parte, no doc de estratégia.
