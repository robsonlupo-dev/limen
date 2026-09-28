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

## 1b.1 — Enquetes e "pergunte-me" no story

Elemento interativo preso a um story. Enquete = tocar uma opção (vê % depois);
pergunta = texto livre que **só a performer** lê.

**Dados**
```
story_interactions
  id
  performer_story_id     FK cascade
  type                   enum('poll','question')
  prompt                 string
  options                JSON (poll: 2–N opções; question: null)
  timestamps

story_interaction_responses
  id
  story_interaction_id   FK cascade
  member_id              FK cascade
  option_index           nullable int (poll)
  answer_text            nullable string (question; filtros anti-contato)
  created_at
  UNIQUE(story_interaction_id, member_id)   -- 1 resposta por membro
```

- **Privacidade:** a performer vê as respostas por **FanAlias/apelido**
  (`MemberDisplayName`), nunca dado real. O agregado da enquete é anônimo (só %).
- **Anti-abuso:** UNIQUE por (interação, membro); `answer_text` passa pelos filtros
  de conteúdo; rate-limit por membro.
- **UI:** overlay no `StoryViewer` (opção tocável / campo de texto). A performer vê
  resultados num painel do story (contagem + lista mascarada).

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

## 2.1 — Canal de transmissão da performer

Mensagem 1-para-muitos para os **seguidores** (`Follow`), sem resposta no canal.

- **Dados:** `broadcast_messages` (performer_profile_id, body/media, timestamps) +
  entrega via **Reverb** (fan-out para os seguidores online) + persistência para
  quem estava offline ver depois (uma aba "Canais" no lado do membro).
- **Sem resposta:** o membro que quiser falar abre o **chat pago** normal (o CTA do
  canal leva ao chat). Fecha a fuga de contato grátis.
- **Custo:** grátis para o seguidor; é motor de retenção que puxa para ações pagas.
  Rate-limit de broadcasts por performer (`config/broadcast.php`) — proteger o
  Reverb do servidor atual.
- **Privacidade:** o canal é da performer para muitos; o membro nunca é exposto a
  outros membros.

## 2.2 — Modo efêmero (vanish) no chat

Mensagens somem da tela depois de vistas; o original é **retido para moderação**
(mesma disciplina do "desfazer envio", `redacted_at`).

- **Ligar:** qualquer um dos dois liga para a conversa; o outro vê o aviso (um flag
  em `conversations` ou um par de flags por participante — decidir no build).
- **Sumiço:** de EXIBIÇÃO — a mensagem é redigida nas duas pontas após vista
  (reusa `Message.redacted_at` / `MessageRedacted`), nunca apagada de fato (prova
  para moderação; nunca some sob denúncia aberta).
- **Encaixa** no posicionamento de privacidade do Limen.

---

# ONDA 3 — Vitrine e dados (esboço)

## 3.1 — Fixar conteúdo + coleções do membro

- **Performer:** fixar itens no topo da vitrine (`is_pinned` + `pinned_at` no item
  de conteúdo, com teto de fixados).
- **Membro:** salvar CONTEÚDO em coleções privadas (hoje ele favorita performer e
  salva buscas, mas não salva conteúdo). Tabela `content_collections` +
  `content_collection_items`. Privado; a performer não sabe.

## 3.2 — Insights da performer

Painel só-leitura: quem viu o story (por FanAlias, via `story_views`), funil
**visita → chat → compra**, melhores horários, evolução de ganhos. Deriva do que já
existe (`story_views`, `ProfileVisit`, ledger). Retém performer — é o que o Insta
faz com criador. **Cuidado de custo:** agregar em query com cache, nunca varrer o
ledger inteiro a cada abertura (servidor de 2 vCPU).

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
- [ ] **1b — Enquetes e "pergunte-me"** (`story_interactions`,
      `story_interaction_responses`), overlay no `StoryViewer`, painel de resultados,
      privacidade por FanAlias, testes, revisão de segurança. **Próxima PR.**
- [ ] Registrar cada recurso em `docs/ARQUITETURA.md` e no mapa de features do
      `CLAUDE.md`.
