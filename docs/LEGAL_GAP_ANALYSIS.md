# Legal Gap Analysis — Limen
Data: 09/10/2026 | Auditor: Claude Code (varredura de código) | HEAD auditado: `main` (pós-PR #326)

> **Esta versão SUBSTITUI a auditoria de 20/07/2026.** A de julho foi feita quando a
> plataforma **ainda não tinha módulo de publicação de conteúdo** — e por isso marcava
> como 🔴 FALTA superfícies inteiras (conteúdo, denúncia, moderação, CSAM, 2FA, hard
> delete) que **hoje existem**. Esta passagem re-percorreu `app/Http/Controllers/`,
> `app/Services/`, `app/Models/`, `app/Support/`, `app/Console/`, `database/migrations/`,
> `routes/`, `config/` e `resources/js/Pages/` para registrar o estado **atual**.
>
> **Nada foi implementado nesta passagem** — só registra o que existe, o que existe pela
> metade e o que não existe, com caminho de arquivo. Mantém o tom cético: onde algo é
> stub, está desligado por flag, ou depende de configuração externa não verificável no
> código, está dito em voz alta.

> **Atualização 16/10/2026:** o texto dos documentos **deixou de ser placeholder** — há
> minutas pré-lançamento publicadas em `/termos`, `/privacidade`, `/politica-de-conteudo`,
> `/contrato-de-performance` e `/verificacao-etaria` (fonte `resources/legal/*.md`; pacote
> interno em `docs/juridico/`). Continuam **minutas sem revisão de advogado**: os itens 🔴
> de "texto real" abaixo passam a 🟡 até a revisão; os demais gaps (CSAM, geobloqueio,
> cifra de mensagem, etc.) **não mudaram**.

Legenda: ✅ EXISTE · 🟡 PARCIAL · 🔴 FALTA

> **Nota transversal — o que mudou desde julho (reverte a premissa central da auditoria
> anterior):** a plataforma **passou a ter um pipeline completo de conteúdo pago**
> (`PerformerContent`, vitrine, PPV no chat, encomenda sob medida com escrow, Fã-Clube,
> stories/destaques), **moderação e denúncia de ponta a ponta** (modelo `Report`, hub
> `/moderacao/*`, papel de moderador, banimento por admin, resolução de disputa de
> encomenda), **2FA (TOTP) para performer + login OTP**, e **hard delete/anonimização
> LGPD** com carência de 30 dias e expurgo agendado. A consequência prática é que o risco
> **deixou de ser "superfícies que não existem" e passou a ser "superfícies que existem
> mas têm buracos específicos"** — principalmente: **o texto jurídico dos documentos
> ainda é placeholder**, **o scanner de CSAM é um MVP que hoje não barra nada**, e **o
> geobloqueio está construído mas inerte na config atual**. São esses três que puxam a
> fila de CRÍTICO agora.

---

## 1. Verificação de Idade (ECA Digital)

| Item | Status |
|---|---|
| Age gate de navegação (cookie) | ✅ EXISTE (controle de UI, não verificação) |
| CPF + data de nascimento no cadastro do membro | ✅ EXISTE |
| **Selfie KYC obrigatória para o MEMBRO** (novo desde julho) | ✅ EXISTE |
| Verificação contra base oficial (Serpro/DataValid) | 🔴 FALTA |
| Registro auditável do método de verificação | ✅ EXISTE |

**O que mudou:** o membro agora **também passa por um KYC de selfie obrigatório**.
`AuthService::registerConsumer` nasce com `status='pending_kyc'`; o middleware
`EnsureMemberVerified` redireciona para `consumer.kyc.index` até a aprovação; o
`ConsumerKycController::submit` recebe a selfie (jpeg/png, ≤10 MB) e cai no mesmo
provedor/webhook do performer (`KycSubmissionService::submitMemberSelfie`). Antes, o
membro era só autodeclaração.

**O que continua sendo verdade (e precisa ser dito ao auditor):** a **idade em si segue
autodeclarada**. No cadastro o membro informa `birthdate` (validado `before_or_equal`
hoje−18, em `RegisterWebRequest`) e um `cpf`. O `CpfValido` valida **só os dígitos
verificadores** — prova que um CPF estruturalmente válido foi digitado, não que a pessoa
tem 18 anos nem que o CPF é dela (gerador de CPF válido é resultado de primeira página de
busca). O CPF **nunca é persistido em texto puro**: só HMAC-SHA256 com a `APP_KEY`
(`CpfHash`) em `age_verifications.cpf_hmac`, usado para dedupe/blacklist. O
`age_verifications.method` é fixo em `'cpf_dob'`, e a própria migration diz *"NÃO é
consulta a base oficial"*; o método `'serpro'` está reservado mas **não implementado**
(grep por serpro/datavalid só acha docs e comentário de migration — zero cliente HTTP).

**Semântica do `age_verified_at` (mantida):** não é marcado no cadastro do membro (comentário
explícito em `AuthService`), só o `KycService::approve()` escreve ali — então, com o KYC de
selfie, o membro aprovado recebe `age_verified_at`, mas o lastro da idade continua sendo a
data autodeclarada + CPF estrutural, nunca uma consulta autoritativa. A redação defensável
para auditoria está em `docs/SECURITY_ISSUES.md` §"Age Verification" — usar aquela.

---

## 2. Pipeline de Verificação da Performer (KYC)

| Item | Status |
|---|---|
| KYC com documento (RG/CNH) para performer | ✅ EXISTE |
| Documentos e PII cifrados em repouso | ✅ EXISTE |
| Liveness / face match | 🟡 PARCIAL (depende do workflow da Didit, não do código) |
| Registro de sessão KYC no banco | ✅ EXISTE |
| **Vínculo entre conteúdo publicado e pessoa verificada** | ✅ EXISTE (por gate de status) |
| Revalidação periódica de performers | 🔴 FALTA |
| Flag de IP compartilhado entre performers | 🔴 FALTA |

**KYC documental + cifra.** `SubmitKycRequest` exige `document_front` + `selfie`
(jpeg/png até 10 MB). `IdentityVerification` cifra `document_number`, `full_legal_name` e
`date_of_birth` com cast `encrypted` (confirmado em `app/Models/IdentityVerification.php:19-22`);
os arquivos vão ao disco privado `kyc` cifrados com `Crypt` e sufixo `.enc`
(`KycDocumentStore`). Aprovação/rejeição gravam `reviewed_by`, `reviewed_at` e `audit_log`
(`KycService`). O webhook da Didit é bem protegido (HMAC `X-Signature-V2`, janela ±300s,
idempotente por `event_id`, fail-closed sem segredo).

**Liveness / face match — 🟡, e o motivo é o mesmo de julho.** A selfie é coletada e a
decisão vem da Didit (`DiditKycClient`), mas **quais checagens rodam é propriedade do
`workflow_id` configurado no painel da Didit, não do nosso código** — o cliente só mapeia
`Approved`/`Declined`. Para auditoria é afirmação **não verificável a partir do código**:
precisa de evidência documental do painel da Didit anexada ao dossiê. Não descrever como
"com liveness" sem esse anexo.

**⚠️ Flag novo — provedor de KYC divergente.** `config/kyc.php` tem default `KYC_PROVIDER=fake`;
o `.env.production.example` aponta `KYC_PROVIDER=unico`, **mas o binding
(`AppServiceProvider`) só tem cliente concreto para `didit` e `fake`** — `unico` cai no
genérico `KycHttpClient`, que **não é a integração da Didit**. A integração real escrita no
código é a Didit. Confirmar qual provedor vai pra produção e alinhar o `.env` antes do go-live.

**Vínculo conteúdo ↔ pessoa verificada — ✅ (era o gap mais estrutural; foi fechado).**
Toda ação de publicação em `PerformerContentController` chama `Gate::authorize('performer-active')`;
o gate (`AppServiceProvider:60`) exige `role==='performer' && status==='active'`, e o
**único** caminho para `status='active'` é a aprovação do KYC (`KycService::approve` seta
status ativo + `is_verified=true` juntos). O conteúdo é amarrado à pessoa por
`performer_profile_id` resolvido **no servidor** (`PerformerContent::$fillable = []`, nunca
do input). **Ressalva honesta:** o gate chaveia em `status==='active'`, não lê
`is_verified` diretamente — na prática são setados juntos no KYC, mas a autorização de
upload olha o status da conta, não o booleano de verificação.

**Revalidação periódica — 🔴.** Não há coluna de expiração de KYC, comando agendado nem
job. `is_verified` é booleano permanente: uma aprovação de 2026 vale para sempre.

**IP compartilhado entre performers — 🔴.** Não há detecção de rede de exploração por
colisão de IP entre performers (o `audit_logs.ip_hash` guardaria o rastro, mas nada
consulta procurando colisão).

---

## 3. Módulo de Conteúdo + Moderação + Denúncia

| Item | Status |
|---|---|
| Módulo de publicação de conteúdo pago | ✅ EXISTE (vitrine, PPV, encomenda, Fã-Clube, stories) |
| Fluxo de denúncia por membros | ✅ EXISTE |
| Fila de moderação (papel de moderador) | ✅ EXISTE |
| Remoção de conteúdo / suspensão / banimento | ✅ EXISTE |
| Congelamento de remoção sob denúncia aberta (preserva prova) | ✅ EXISTE |
| Política de Conteúdo Proibido — **texto** | 🔴 PLACEHOLDER |

**Módulo de conteúdo — ✅ maduro.** `PerformerContent` (`performer_content`, foto/vídeo,
níveis open/premium/exclusive/fc_only), `PerformerContentService`
(`publish`/`publishVideo`/`deliverCustomPiece`/`publishToFanclub`), economia de desbloqueio
(`ContentUnlockService`, `content_unlocks`). Superfícies: vitrine permanente, **PPV no chat**
(`messages.ppv_content_id`), **encomenda sob medida com escrow** (`custom_orders`,
`CustomOrderService`), **Fã-Clube** (`performer_content.fanclub`, `FanclubService`),
**stories/destaques** (`PerformerStory`, `StoryHighlight`). É a superfície que a auditoria de
julho dizia não existir — hoje é o coração do produto.

**Denúncia + moderação + remoção — ✅ os três (eram 🔴).**
- **Denúncia do membro:** `ReportController` (`report.store`), modelo `Report` append-only.
  Tipos reportáveis: performer, mensagem, story, foto de membro, conteúdo de performer,
  apelido. Tem guarda de autodenúncia, dedupe 24h, e **prioridade por motivo — "menor de
  idade" e "não consensual" entram como `urgent`**.
- **Fila do moderador:** `/moderacao/*` (middleware `moderator.access`). `ModerationController`
  + `ModeratorActionService`: overview, resolver/revisar/descartar, **advertir, suspender
  (temporário), escalar (pro admin banir), reabrir**. Filas dedicadas: voz
  (`VoiceIntroModerationController`), foto de membro (`MemberPhotoModerationController`),
  **disputa de encomenda** (`CustomOrderDisputeController` → `CustomOrderService::resolveDispute`,
  release/refund). `EvidenceController` serve o conteúdo denunciado retido enquanto o report
  está aberto.
- **Papéis:** moderador adverte/suspende/escala (não bane, não toca KYC/payout); admin bane
  (`UserBanController`, `status='banned'`). **Sob denúncia aberta, remover E fixar conteúdo é
  congelado** (`PerformerContentService` lança `UNDER_REVIEW`) — impede destruição de prova.

**⚠️ CRÍTICO — o TEXTO dos documentos ainda é placeholder.** A máquina de aceite é
produção-grade (ver §7), mas `resources/js/Pages/Legal/Document.vue` renderiza, para **os
dois** documentos (Política de Conteúdo Proibido e Contrato de Performance), uma única linha
fixa: **`[CONTEÚDO JURÍDICO — aguardando Opice Blum]`** + aviso de que "ainda não é a versão
final". Não há cláusula real em lugar nenhum. **Aceite gravado sobre placeholder tem valor
jurídico próximo de zero — escrever o texto real e bumpar a versão é pré-requisito de
go-live, não polimento.** É exatamente a F2 do escopo jurídico.

---

## 4. Filtro Anti-Contato do Chat (risco do Art. 228 CP) — o ponto mais sensível

| Item | Status |
|---|---|
| Bloqueio de intermediação de encontro pago / pagamento fora da plataforma | ✅ EXISTE (chat + textos públicos) |
| Anti-evasão (zero-width, leet, fullwidth, espaçamento) | ✅ EXISTE |
| Mensagens grátis de catálogo = modelos pré-cadastrados (fecha fuga de contato grátis) | ✅ EXISTE |
| Guarda de contato em textos PÚBLICOS (bio, apelido, broadcast, status, enquete) | ✅ EXISTE |
| Bloqueio de **troca de contato** dentro do chat 1:1 | 🟡 **DECISÃO DE PRODUTO — não bloqueia** |

Este é o ponto que os dois advogados (Felipe e Monteseiler) querem revisar, então a redação
precisa ser exata.

**O que o filtro faz (`ChatContentFilter`, `config/chat_filters.php`, aplicado em
`ChatService::assertContentAllowed` e `LiveChatService`):** bloqueia **TIPO 1 — legal**
(intermediação de encontro pago / pagamento fora da plataforma — o conjunto FOSTA-SESTA:
"programa", "pix fora", "fora da plataforma", motel/encontro + sinal de dinheiro) e **TIPO 2
— conduta** (ameaça, sextorsão, insulto dirigido), com **anti-evasão forte** (strip de
zero-width, NFKC fullwidth, mapa leet, colapso de repetição, espaçamento difuso). `CHAT_FILTER_ENABLED`
nasce `true`.

**O que o filtro NÃO faz (e é deliberado):** ele **não bloqueia troca de contato** (telefone,
WhatsApp, Instagram). O próprio cabeçalho do `config/chat_filters.php` diz isso em letras
maiúsculas: *"Troca de contato... é legítimo; barrar isso tratava o usuário como suspeito."*
Então, no corpo de uma mensagem 1:1, passar um contato **não é bloqueado — nem no grátis nem
no pago**; e no **chat já pago o texto é livre por construção**. **Voz não passa pelo filtro**
(áudio não tem texto; moderação ali é só por denúncia).

**Onde o contato É bloqueado (as superfícies anti-contato reais):** texto público — bio do
membro e `looking_for`/bio da performer (`SafeProfileText`/`NoProhibitedOffer`), `seeking` do
membro; **apelido** (`MemberNicknameService`, o mais rígido: telefone 5+ dígitos, @/email,
URL, social anti-leet, palavra reservada, impersonação); e as superfícies sociais (broadcast,
status, enquete, título de destaque, meta de gorjeta, descrição/entrega/disputa de encomenda —
todas `SafeProfileText`).

**Fechamento da fuga grátis (importante para o 228):** as 15 mensagens grátis diárias de
catálogo **deixaram de ser texto livre** — `SendCatalogMessageRequest` exige `template_id`
e o servidor renderiza de um **modelo pré-cadastrado** (`ChatCatalogTemplate`), ignorando
qualquer corpo do cliente. Isso fecha o vetor em que a performer mandava Instagram/WhatsApp
de graça. Depois que o membro **paga** o acesso ao chat, o texto livre (e o contato) é
permitido.

**Leitura para o parecer (Art. 228):** a plataforma **bloqueia a solicitação de encontro
pago** (o vetor de facilitação que o 228 mira) no chat e nos textos públicos, e fechou a fuga
de contato grátis via modelos. Ela **permite deliberadamente a troca de contato** no chat
pago e na voz — decisão de produto defensável ("depois de trocarem contato, a conversa sai da
plataforma", como o próprio Felipe comentou), mas o advogado precisa saber que é uma **escolha
consciente**, não uma lacuna. É o ponto exato da "revisão jurídica funcionalidade-por-
funcionalidade" que ele propôs.

---

## 5. CSAM e Marca d'água

| Item | Status |
|---|---|
| Hook de varredura anti-CSAM no upload de imagem | ✅ EXISTE (sempre ligado) |
| Base de hashes efetiva (NCMEC/IWF/PhotoDNA) | 🔴 FALTA (lista local vazia) |
| Near-match (distância de Hamming) / PhotoDNA | 🔴 FALTA |
| Varredura de CSAM em **vídeo** | 🔴 FALTA |
| Marca d'água em mídia | 🟡 EXISTE mas DESLIGADA por padrão (só encomenda) |

**⚠️ CSAM — a honestidade aqui é crítica para o parecer.** Existe o encanamento:
`CsamScanService` roda em `ContentStore::store()` **antes** de gravar os bytes
(scan-before-store), e re-roda ao promover story a destaque (`HighlightStore`). Nasce
**ligado** (`CSAM_SCAN_ENABLED=true`, bloqueador de go-live declarado). **Mas o detector é um
MVP que hoje não barra praticamente nada:**
- É **match EXATO de hash perceptual** contra uma **lista local que sobe vazia** — o próprio
  `config/csam.php` diz que a lista real "depende de parceria NCMEC/IWF + CNPJ". Sem
  `php artisan csam:import-hashes`, não há hash para bater.
- **Sem PhotoDNA, sem near-match, sem integração externa/nuvem** (ambos marcados como
  "follow-up" no código).
- **Fail-OPEN:** se o hasher não decodifica a imagem, loga warning, marca `verified=false` e
  **libera** o upload.
- **Vídeo não é escaneado** por CSAM (o pipeline de vídeo re-encoda e tira metadados, mas não
  chama o `CsamScanService`).

Conclusão: "ligado" ≠ "efetivo". Com lista vazia + fail-open + só imagem, **o CSAM hoje é um
stub**. Integrar a base real (NCMEC/IWF/PhotoDNA) e cobrir vídeo é **anterior ao primeiro
upload de produção** — hash-matching depois do fato é retrabalho e janela de exposição.

**Marca d'água — 🟡 construída, mas OFF.** `WatermarkService` queima "Fã #NNNN · dd/mm/aaaa"
(FanAlias + data, nunca dado real) na foto (depois do scan CSAM) e no vídeo (overlay ffmpeg).
**Só se aplica a entregas de encomenda** (cofre/PPV/Fã-Clube saem sem marca). **Kill-switch
nasce desligado** (`CUSTOM_ORDER_WATERMARK=false`) — com o default, nada é marcado. Quando
ligada, é fail-closed (falha na marca → entrega falha / peça FAILED → estorno).

---

## 6. Pagamentos — Modelo e Riscos

*Descritivo. O detalhe vivo está em `docs/DECISAO_PROCESSADOR_PAGAMENTO.md`.*

**Estado:** o Asaas (que vetou o ramo) está sendo **substituído**; a decisão provisória é
trilho doméstico PIX (Efí/Woovi confirmados; Transfeera com o melhor proof point, pendente).
A economia é **agnóstica de gateway** (ledger append-only + webhook idempotente), então a troca
não bloqueia testes/dev. O go-live depende do CNPJ (em abertura) — ver o ADR.

**Fluxo do dinheiro (inalterado no desenho):** membro → Limen (cobrança PIX; webhook idempotente
credita token no ledger) → gasto do membro move só token (escrituração interna) → Limen →
performer (payout PIX). O **recebedor legal é o Limen**; a performer é paga em transação
posterior. Não há split na origem para a compra de token (ver abaixo).

**Enquadramento como intermediador financeiro — agora VALIDADO pelo jurídico.** O parecer
Monteseiler (Parte 3, 09/10/2026) testou a hipótese e concluiu **"BLINDAGEM EFICAZ"**: o
token é **Passivo Circulante** (não receita), a receita da Limen (base ISS/IRPJ) nasce só no
consumo sobre o **split**, e a parcela da performer é **repasse** (não tributável para a
Limen). Isso afasta bitributação e a leitura de exploração do Art. 228. **Split na origem não
se aplica à carteira pré-paga** (no ato da compra ainda não há performer de destino); aplica-se
às compras diretas (PPV/encomenda), onde a Woovi cobre. **Ação de produto que caiu do parecer:**
o ToS do Membro precisa da cláusula de natureza jurídica do token — *"Créditos de Uso não
resgatáveis, sujeitos a prescrição quinquenal, sem equivalência com moeda corrente"* (entra na
F2). Descritor/razão social neutra na fatura/PIX (não "Limen Conteúdo Adulto").

---

## 7. Segurança e LGPD

| Item | Status |
|---|---|
| 2FA (TOTP) para performer | ✅ EXISTE |
| Login OTP (todos os papéis) | ✅ EXISTE |
| 2FA para membro | 🔴 FALTA (decisão: só performer) |
| Hard Delete / anonimização LGPD (art. 18) | ✅ EXISTE (3 camadas, carência 30d, expurgo agendado) |
| `audit_logs.ip` como HMAC (não texto puro) | ✅ EXISTE (corrigido) |
| TTL / expurgo de `audit_logs` | 🔴 FALTA (retido para sempre) |
| Criptografia de `messages.body` em repouso | 🔴 FALTA (texto puro, retido p/ moderação) |
| GC / retenção de mídia (áudio, stories, fotos efêmeras) | ✅ EXISTE (cron amplo) |
| Re-encode + strip EXIF em imagem no ingest | ✅ EXISTE |
| Sanitização de vídeo/voz (ffmpeg, strip metadados) | ✅ EXISTE |
| Scan/re-encode no upload de **KYC** | 🔴 FALTA (cifra, mas não sanitiza nem escaneia) |
| Backups off-site | 🟡 PARCIAL (script GPG local; off-site só comentado) |
| Plano de rotação da `APP_KEY` | 🔴 FALTA (rotação é destrutiva) |

**2FA — ✅ (era 🔴).** `TwoFactorService` (RFC 6238, Google2FA): enroll/confirm/disable,
8 códigos de recuperação, replay-proof (`two_factor_last_used_ts` sob lock), QR local. Segredo
e códigos com cast `encrypted`. Cobre **performer** (conta que dá acesso a payout + publicação);
**membro não tem segundo fator TOTP** (decisão). Login OTP por e-mail (`OtpService`, 6 dígitos,
TTL 5min, single-use, rate limit anti-enumeração) vale para todos.

**Hard Delete / LGPD — ✅ (era 🔴), implementação madura.** `DeletionService` (art. 18, carência
`GRACE_DAYS=30`): `requestDeletion` → confirmação por token 48h → carência → job agendado
(`deletions:process`, diário 04:00); cancelável até executar; bloqueia exclusão com payout/PIX
pendente. **Três camadas:** *hard delete* de bytes/PII (KYC rows + arquivos `.enc`, follows,
favoritos, saves, fotos efêmeras, stories/conteúdo); *anonimização por de-link* (ledger,
pagamentos, tips, payouts, conversas — texto livre e chaves PIX limpos; user soft-deleted +
campos nulos; stage_name→`[removido]`); *preservado* (`audit_logs` com `ip_hash`, `reports`,
`document_acceptances` — trilha de abuso). **Mensagens são soft-deleted, não hard-deleted**,
deliberadamente, para manter a trilha probatória de uma conversa de duas partes. Deleção de
arquivo roda **após** commit, best-effort com log sem PII.

**Tensão real a resolver com o escritório (segue aberta):** direito à eliminação × dever de
retenção probatória de mensagens. O código **retém** a mensagem; falta a **base legal escrita +
prazo definido** — é decisão jurídica, não de engenharia.

**`messages.body` em texto puro — 🔴 (segue aberto).** `Message::casts()` não cifra `body`
(só `read_at`/`ephemeral`/`revealed_at`/etc.). Conteúdo íntimo entre membro e performer está
legível em qualquer dump do banco e nos backups. O modo efêmero ("tocar para ver",
`revealed_at`) e o "desfazer envio" (`redacted_at`) **só escondem na UI** — o corpo **fica no
banco para moderação**. Ou seja: a confidencialidade da DM depende de controle de acesso ao
banco + GPG do backup, **não** de cifra de coluna. É a maior divergência com o princípio 4 do
CLAUDE.md na superfície de maior volume.

**`audit_logs` — 🟡.** IP **agora é HMAC** (`2026_09_24_000005_hash_ip_on_audit_logs`,
`ip`→`ip_hash` via `ClientFingerprint`) — o furo de julho (IP em texto puro) está fechado.
**Mas não há TTL/expurgo:** não existe comando `audit:purge` nem schedule; a tabela cresce para
sempre e é preservada mesmo após anonimização. Postura de "trilha legal" sem prazo máximo
definido — precisa de política de retenção escrita.

**Upload — ✅ imagem / 🔴 KYC.** Imagem: `ImageProcessingService` re-encoda (GD), tira EXIF/GPS,
mata polyglot, guarda contra image-bomb (checa dimensão/área antes de decodificar) + scan CSAM,
em todos os stores. Vídeo/voz: ffmpeg re-encoda e tira metadados. **Furo:** o upload de **KYC**
(a PII mais sensível) é cifrado mas **não passa pela sanitização nem pelo CSAM** que o resto do
produto usa — fica como bytes cifrados crus. Corrigir: rodar o mesmo pipeline de ingest no KYC.

**Backups — 🟡.** `docs/backup.sh` é sólido (mysqldump + discos privados, GPG obrigatório,
retenção 14d) — mas é **script para instalar**, `BACKUP_DIR` é local (`/home/deploy/backups`),
e **off-site é só recomendação comentada**. Backup criptografado na mesma máquina não sobrevive
à perda da máquina. Confirmar/ativar destino off-site.

**Rotação da `APP_KEY` — 🔴 (rotação é destrutiva).** Não há comando de rotação nem chave
versionada. A `APP_KEY` é chave de HMAC (CPF, FanAlias, ClientFingerprint, `audit_logs.ip_hash`)
e de cifra (colunas `encrypted` de KYC/2FA + arquivos de KYC). Rotacionar hoje **quebra a dedupe
de CPF, invalida correlação de aceites/IP e torna documentos de KYC e segredos de 2FA
ilegíveis**. Precisa de plano (re-cifra em lote, chave versionada) antes de virar incidente.

---

## 8. Geobloqueio (FOSTA-SESTA)

| Item | Status |
|---|---|
| Middleware de geobloqueio + resposta 451 | ✅ EXISTE |
| Bloqueio efetivo na config atual | 🔴 **INERTE** (`GEO_DRIVER=none`) |

**⚠️ Honestidade crítica:** o middleware (`GeoBlock`, registrado em web+api, `abort(451)` com
view custom) e o serviço (`GeoLocationService`, `config/geo.php`, país padrão bloqueado `US`)
**existem e têm teste**. **Mas `GEO_DRIVER` nasce `none` — fail-OPEN — então o middleware é um
NO-OP e não bloqueia ninguém.** Só `cloudflare` (lê `CF-IPCountry`, seguro só se o nginx
restringir às faixas da Cloudflare) ou o driver `maxmind` planejado bloqueariam de fato. O
próprio código avisa: **não** descrever como "usuários dos EUA não acessam" em política/contrato
— é bypassável por VPN e está inerte na config atual. Ligar um driver real é item de go-live.

---

## 9. Propriedade Intelectual e Societário

| Item | Status |
|---|---|
| `composer.json` não declara licença permissiva sobre código proprietário | ✅ CORRIGIDO (`"proprietary"`) |
| Arquivo LICENSE no repositório | ✅ EXISTE |
| Cessão de PI do CTO/prestadores (IP Assignment) | 🔴 FALTA (jurídico — F3) |
| Contrato Social + Vesting + Contrato de Mútuo (capital) | 🔴 FALTA (jurídico — F3) |

O furo de julho (`"license": "MIT"` default do Laravel) **está corrigido** — hoje é
`"proprietary"` e há arquivo `LICENSE`. O que resta é **societário/contratual**, não de
repositório: cessão expressa de direitos patrimoniais do código (sem ela, no Brasil, os
direitos de obra por encomenda podem não migrar), contrato social (66% Robson / 34% Bruno,
conforme ajuste do parecer), regulamento de vesting, e o **Contrato de Mútuo** para blindar o
aporte da esposa (origem dos fundos perante a Receita, fora do contrato social). Tudo isso é a
**F3** do escopo jurídico.

---

## 10. Ranking Final

| Item | Status | Classificação | Responsável |
|---|---|---|---|
| Texto real da Política de Conteúdo Proibido + Contrato de Performance (hoje placeholder) | 🔴 | **CRÍTICO** | Jurídico (F2) — pré-go-live |
| ToS do Membro + ToS da Performer + Política de Privacidade (LGPD) | 🔴 | **CRÍTICO** | Jurídico (F2) |
| CSAM efetivo (base NCMEC/IWF/PhotoDNA + near-match + cobrir vídeo) | 🟡→🔴 | **CRÍTICO** | Eng. — antes do 1º upload de produção |
| Geobloqueio efetivo (ligar driver real; hoje `GEO_DRIVER=none` é inerte) | 🔴 | **CRÍTICO** | Eng. — pré-go-live |
| Parecer penal Art. 228 + revisão funcionalidade-por-funcionalidade do chat | 🟡 | **CRÍTICO** | Jurídico (F1) |
| Cláusula de natureza jurídica do token no ToS (crédito não resgatável) | 🔴 | **CRÍTICO** | Jurídico (F2) |
| Contrato Social + IP Assignment + Vesting + Mútuo | 🔴 | **CRÍTICO** | Jurídico (F3) |
| Criptografia de `messages.body` em repouso | 🔴 | ALTO | Eng. |
| Scan/sanitização do upload de KYC (hoje pula o pipeline) | 🔴 | ALTO | Eng. |
| Base legal escrita p/ retenção de mensagens × art. 18 LGPD | 🔴 | ALTO | Jurídico |
| Evidência documental de liveness/face-match na Didit (anexar ao dossiê) | 🟡 | ALTO | Pré-go-live |
| Alinhar provedor de KYC (prod aponta `unico`, código só faz `didit`/`fake`) | 🟡 | ALTO | Eng. |
| Verificação de idade contra base oficial (Serpro/DataValid) | 🔴 | ALTO | Eng. |
| Backup off-site confirmado e rodando | 🟡 | ALTO | Infra — pré-go-live |
| Plano de rotação da `APP_KEY` (hoje é destrutiva) | 🔴 | ALTO | Eng. |
| Ligar a marca d'água (hoje OFF) após self-test de foto e vídeo | 🟡 | MÉDIO | Eng. |
| TTL / expurgo de `audit_logs` | 🔴 | MÉDIO | Eng. |
| Revalidação periódica de KYC de performers | 🔴 | MÉDIO | Eng. |
| 2FA para membro (hoje só performer) | 🔴 | BAIXO | Decisão de produto |
| Flag de IP compartilhado entre performers | 🔴 | BAIXO | Futuro |

### Leitura de uma linha

Em julho, os CRÍTICOS eram **superfícies inteiras que não existiam** (conteúdo, denúncia,
moderação, CSAM, 2FA, hard delete). Hoje **quase todas existem e estão bem construídas** —
ledger e webhook idempotentes, KYC cifrado, CPF nunca em claro, aceite append-only com
fingerprint HMAC, deleção LGPD em três camadas, moderação com papel separado e congelamento de
prova. Os CRÍTICOS que sobraram são de **duas naturezas**: (1) **texto jurídico** que só o
escritório entrega (documentos placeholder, cláusula do token, societário) — as frentes F1–F3;
e (2) **dois controles que existem mas estão inertes/stub** — o **CSAM** (lista vazia +
fail-open + só imagem) e o **geobloqueio** (`GEO_DRIVER=none`). Esses dois são os que mais
pesam numa auditoria de regulador, porque o produto *parece* tê-los e na prática não barra
nada. Ligar os dois de verdade, escrever os documentos e fechar a cifra de mensagem é o
caminho entre "administrável hoje" e "defensável no go-live".
