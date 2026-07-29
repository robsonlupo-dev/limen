# LIMEN — MASTER HANDOFF FINAL

> **Documento de transferência oficial — encerramento de chat.**
> O próximo chat **não terá acesso a nenhuma mensagem anterior**. Este arquivo é
> autossuficiente para continuidade imediata do projeto. Leia-o inteiro antes de
> pegar qualquer tarefa; ele complementa (não substitui) o `CLAUDE.md`, que
> continua sendo o cérebro operacional do projeto.
>
> **Gerado em:** 22/07/2026 · **Branch de origem:** `feat/sprint6-final`
> **Última atualização:** 29/07/2026 — **Sprint 9B PARCIAL** (`main`, `b620e9e`,
> PRs #101–#104). O Sprint 9A fechou antes, no mesmo dia, e **ganhou a tag
> `v1.0-sprint9a`** depois que este doc registrou a ausência dela — ver §1.
> **O 9B não está fechado:** a Foto Efêmera existe ponta a ponta e **não pode ir
> a go-live** com os 🔴 da seção "Sprint 9B — Em andamento" abertos.
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
| Suíte de testes | **1141 testes verdes, 5956 asserts** | `php artisan test` (~120 s) |
| Migrations | **75** | `ls database/migrations/*.php \| wc -l` |
| Rotas registradas | **142** | `php artisan route:list` (rodapé *Showing*) |
| `Route::` em `routes/web.php` | 107 | `grep` |
| Rotas HTTP em `routes/api.php` | 39 (**nenhuma de foto** — a Foto Efêmera é só web) | `grep` |
| Services | 26 (+ subpastas `Asaas`, `Kyc`, `Waitlist`) | `ls app/Services/` |
| Models | 32 | `ls app/Models/` |
| Controllers Web | 43 | `find app/Http/Controllers/Web` |
| Controllers API | 21 | `find app/Http/Controllers/Api` |
| Middleware | 10 | `ls app/Http/Middleware/` |
| Commands (agendáveis) | 10 | `ls app/Console/Commands/` |
| Jobs | 3 | `ls app/Jobs/` |
| Policies | 4 | `ls app/Policies/` |
| Configs | 26 | `ls config/` |
| Tag Git | `v1.0-sprint9a` (`1a51d77`), `v1.0-sprint8` (`93b2878`), `v1.0-sprint7` (`80ba300`), `v1.0-sprint6` (`5070638`), `archive/qa-pre-prod-operation`. **O Sprint 9B não tem tag — está em andamento** | `git tag` |

> **Correção do que este doc dizia no fecho do 9A: a tag `v1.0-sprint9a` EXISTE**
> (`1a51d77`, criada em 29/07/2026, depois da redação daquela seção). O parágrafo
> anterior registrava a ausência dela como quebra de padrão deliberada; o PO
> cortou a tag em seguida e o padrão dos Sprints 6→9A está restaurado. Fica o
> registro para quem cruzar as duas versões do doc. **O 9B, esse sim, não tem tag
> — e não deve ter enquanto estiver em andamento.** Range do 9B até aqui:
> `1a51d77..b620e9e`.

**Branch atual:** `main` (em `b620e9e`, com os PRs #101→#104 do **Sprint 9B**
mergeados por cima dos #88→#100 do 9A). Últimos commits relevantes (mais recente
primeiro):

```
b620e9e Merge pull request #104 from robsonlupo-dev/feat/sprint9b-photo-endpoints
4a0a972 fix the three defects the verification found
4a454f9 close the review findings on the photo endpoints
83ce137 add ephemeral photo endpoints, sharing gate and chat UI
6e32cbe Merge pull request #103 from robsonlupo-dev/fix/sprint9b-ephemeral-fixes
ce3ef60 close the gaps the review verification found
1c54015 Merge pull request #102 from robsonlupo-dev/feat/sprint9b-ephemeral-photos
245b097 harden ephemeral photo storage after security review
a85cd81 add ephemeral member photo storage and expiry mechanics
9ddddfb Merge pull request #101 from robsonlupo-dev/feat/sprint9b-image-processing
15d6e87 add image processing service that strips EXIF on ingestion
9a9193e docs: registra o fecho do Sprint 9A no MASTER_HANDOFF_FINAL
1a51d77 Merge pull request #100 from robsonlupo-dev/fix/panic-button-z-index (tag v1.0-sprint9a)
8039287 promote the panic button to a reserved top layer
bb18fdd Merge pull request #99 from robsonlupo-dev/feat/sprint9-onboarding-tutorial
2f7abf4 add first-run onboarding tutorial to the member catalogue
55b8b96 Merge pull request #98 from robsonlupo-dev/feat/sprint9-welcome-email
1fcd1fa add founder welcome email after KYC approval
e184be0 Merge pull request #97 from robsonlupo-dev/feat/sprint9-hcaptcha
f70d4f2 add hCaptcha to login and registration
6321a3b Merge pull request #96 from robsonlupo-dev/feat/sprint9-geolocation
c376b75 add opt-in location to the performer profile
        Merge pull request #95 from robsonlupo-dev/feat/sprint9-interest-visitors
        add controlled interest from the visitor panel
        Merge pull request #94 from robsonlupo-dev/feat/sprint9-catalog-filters
        Merge pull request #93 from robsonlupo-dev/fix/sprint9-chat-filter-faco
1f536ee block the paid-encounter offer written in the first person
        Merge pull request #92 from robsonlupo-dev/feat/sprint9-member-interests
        Merge pull request #91 from robsonlupo-dev/feat/sprint9-performer-tags
        Merge pull request #90 from robsonlupo-dev/feat/sprint9-verification-badges
        Merge pull request #89 from robsonlupo-dev/feat/sprint9-bio-counter
        Merge pull request #88 from robsonlupo-dev/feat/sprint9-online-indicator
93b2878 Merge pull request #87 (fecho do Sprint 8, tag v1.0-sprint8)
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

> **Estado de módulo de conteúdo — MUDOU NO SPRINT 9B. Leia com atenção.**
> Continua **não existindo** pipeline de **publicação**: não há model de post,
> feed, vídeo ou mídia paga, e o perfil da performer segue com `avatar_path` e
> `cover_path` e mais nada.
>
> **O que mudou:** o Sprint 9B abriu a **primeira superfície de upload de mídia
> por usuário** do projeto — a Foto Efêmera do Membro (seção "Sprint 9B — Em
> andamento"). Ela é 1:1 e privada
> (membro → performer com chat ativo, TTL, sem URL assinada, sem feed), então
> **não** é o "módulo de conteúdo" do `LEGAL_GAP_ANALYSIS.md` chegando. Mas é
> arquivo de usuário entrando no volume, e a consequência prática é imediata:
> **hoje uma foto enviada não pode ser denunciada** — `Report::REPORTABLE_TYPES`
> só conhece `performer` e `message` — e o GC apaga por TTL sem saber se alguém
> denunciou. É o primeiro 🔴 daquela seção e **bloqueia o go-live da feature**.
>
> A janela original **segue aberta e vale para Stories** (Sprint 9C), que é
> publicação de verdade: moderação e verificação de conteúdo devem ser construídas
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

## Sprint 8 — O que foi entregue

Fechado na tag `v1.0-sprint8` (`93b2878`). Todos os PRs seguiram a Regra de Ouro
do Git Flow (branch + PR para `main`); os únicos commits diretos na `main` foram
doc puro, que é a exceção prevista. Suíte passou de 859 → **912 testes** verdes
(4817 asserts).

| Entrega | Commit/PR | Seção deste doc |
|---|---|---|
| **Status `banned` (permanente)** separado de `suspended` + middleware de sessão viva | `3c04b7a` / PR #83 | §12 |
| **Lista negra antifraude** (hash de CPF + hash de documento + `blacklist_hit`) | `e3b9211` / PR #84 | §8, §12.4 |
| **KYC Nível 2 para membros** (selfie via Didit, `pending_kyc`, middleware `member.verified`) | `b1b9849` / PR #85 | §8, §9 |
| **Editar `worlds` no profile-edit** (checkboxes, `category` derivada no servidor) | `a0964f6` / PR #86 | §5 |
| **Toggle de visibilidade de senha** (`Input.vue`, tokens do design system) | `9d18840`, `b538f08` / PR #87 | — |
| **Varredura preventiva de falsos positivos na suíte** (3 asserts frágeis corrigidos) | `8c6b4e8`, `cc2516a` | — |
| **Security review pré-implementação Sprint 9** | `99a936b` → `docs/SECURITY_ISSUES.md` | Apêndice A |
| **Decisões de produto do Sprint 9** (foto efêmera + Stories) | `23fa409`, `db8827a`, `6551d6e` | Apêndice A |

### Detalhes que não se deduzem do diff

**Status `banned` — a mensagem específica só vai para quem tem a senha.**
`AccountBlockedException` é lançada por `attemptLogin` **depois** do
`Hash::check` passar. Senha errada continua recebendo "credenciais inválidas"
genérico: se a mensagem "conta encerrada permanentemente" viesse antes da prova
de senha, o login viraria **oráculo de enumeração de status** de qualquer e-mail.
Web e API mapeiam a exceção para mensagens distintas, ambas com audit
`auth.login_blocked`. `status` segue fora do `$fillable`; o ban passa por
`UserBanController` (`forceFill` sob `lockForUpdate` + transação, motivo
obrigatório, revoga tokens Sanctum, guardas contra auto-ban e ban de outro
admin, re-ban idempotente).

**`BlockBannedUsers` é do grupo `web` e é `banned`-only, de propósito.** O bloqueio
de login só cobre o *próximo* login — sem o middleware, o admin banindo alguém
com sessão aberta não derrubava essa sessão. Não vale para `suspended`: o
suspenso tem gates 403 por área, e o middleware transformaria um 403 já
estabelecido em 302.

**A blacklist SINALIZA, nunca bloqueia** — mesma disciplina do shared-IP flag.
Guarda só HMAC (`CpfHash`, e o novo `DocumentHash` com namespace `doc:` para um
CPF-como-documento nunca colidir com o próprio `cpf_hash`), nunca a PII crua. A
entrada **sobrevive ao Hard Delete LGPD**, porque o valor está no hash e não no
titular. `recordForBannedUser` deriva o hash do documento de KYC em **qualquer
status, inclusive `pending`** — é o caso comum de ban de performer — ou do
`age_verifications.cpf_hmac` no caso do membro. `blacklist_hit` fica fora do
`$fillable`; a fila `/admin/kyc` mostra o badge "CPF banido anteriormente".

**KYC de membro reusa a fonte única.** `KycSubmissionService` continua sendo a
única porta (mesma da performer, da API e do webhook Didit) — não nasceu um
segundo caminho de submissão. O novo status `pending_kyc` e as colunas de
documento nullable são migrations; o gate é o middleware `member.verified`.

**A varredura de asserts frágeis foi preventiva, não correção de bug.** A classe
é `'137'`/`'42'`: agulha curta procurada dentro de saída opaca que varia por
`APP_KEY`, build ou RNG — o teste passa por sorteio e um dia falha sem que nada
tenha quebrado. Os três casos (sufixo `Str::random(4)` do slug, dígitos de CPF
dentro do hex do HMAC, `'42'` dentro do handle do `FanAlias`) viraram asserts de
opacidade real (estrutura exata, `not->toBe` do valor cru). Asserts de substring
negativa legítimos — agulha longa/específica de PII, nome de campo, agulha com
maiúscula ou ponto que não ocorre em hex minúsculo — foram deixados como estavam.

> **Sprint 8 não abriu módulo de conteúdo.** A janela descrita no §1 continua
> aberta: moderação e pipeline de verificação devem ser construídos **antes** do
> primeiro upload — e o Sprint 9 (foto efêmera + Stories) é exatamente o sprint
> que abre essa superfície. Ler a pré-análise em `docs/SECURITY_ISSUES.md` antes
> de escrever a primeira linha.

---

## Sprint 9A — O que foi entregue

**Sem tag** (ver §1). Intervalo exato: `93b2878..1a51d77` — **13 PRs, #88 a #100**,
todos pela Regra de Ouro do Git Flow (branch + PR para `main`). Suíte passou de
912 → **1059 testes** verdes (5644 asserts).

> **Por que "9A" e não "Sprint 9".** O backlog do Sprint 9 tinha duas metades: a
> trilha de **UX e descoberta** (tags, filtros, badges, copy) e a trilha de
> **conteúdo efêmero** (Foto Efêmera do Membro + Stories da Performer). Só a
> primeira foi entregue. A segunda **não teve uma linha de código escrita** e
> segue no backlog com os 🔴 abertos — inclusive o pipeline de moderação exigido
> **antes** do primeiro upload. O rótulo "9A" existe para que ninguém leia
> "Sprint 9 entregue" e conclua que a superfície de conteúdo foi aberta. **Ela
> não foi.** A janela do §1 continua aberta.

| Entrega | PR | Seção deste doc |
|---|---|---|
| **Restyle do indicador de online** — o item de backlog pedia "fazer aparecer no catálogo"; já aparecia. Virou troca de estilo, como a ressalva do Sprint 8 previa | #88 | §1 (ressalva original) |
| **Contador de caracteres no bio** com copy de progresso por faixa | #89 | — |
| **Badges de verificação** no card e no perfil público — booleanos, **nunca a data** (`email_verified_at` dataria o cadastro para visitante anônimo) | #90 | §8 |
| **Tags da performer** (máx 8) + campos adicionais (`languages`, `drinks`, `smokes`, `height_cm`, `looking_for`) + **filtro de conteúdo aplicado a `bio` e `looking_for`** | #91 | §5, §17 |
| **Interesses do membro** (`interests`) + `seeking` | #92 | §5 |
| **Fix do filtro de chat: oferta em primeira pessoa** ("faço programa") passava | #93 | §17 |
| **Filtros avançados do catálogo** com service unificado entre as duas portas | #94 | §25 |
| **Interesse Controlado a partir do painel de visitantes** — segunda porta de envio, coluna `source`, cotas por origem | #95 | §14, §16 |
| **Localização opt-in da performer** — só UF exibida, `city` interno, sem coordenadas, sem API do navegador | #96 | §5, LGPD abaixo |
| **hCaptcha** no login e cadastro | #97 | R7 / Apêndice A |
| **E-mail de boas-vindas do fundador** após aprovação de KYC | #98 | §8 |
| **Tutorial de onboarding** (overlay de 4 slides, first-run no catálogo) | #99 | §15.1 |
| **Fix de camada do PanicButton** (Teleport + `z-[10001]`) | #100 | §15.1 |

**Migrations novas (7):** `create_performer_tag_table`,
`add_additional_fields_to_performer_profiles`, `create_member_interest_table`,
`add_seeking_to_users`, `add_source_to_performer_interests`,
`add_location_to_performer_profiles`, `add_welcome_email_sent_at_to_users`.
**Models novos (2):** `PerformerTag`, `MemberInterest`. **Service novo:**
`HCaptchaVerifier`. **Config novo:** `config/hcaptcha.php`.

### Decisões de produto tomadas no Sprint 9A

- **R1 — re-encode sem EXIF** vira a regra geral de ingestão de imagem, não só da
  foto efêmera. "Guardar o original" sai do escopo: o original É o arquivo com o
  metadado que a decisão manda remover, e no caso da performer a coordenada
  costuma ser a casa dela.
- **R2 — resolvido, e resolvido pelos dois lados.** Só **estado** é público (27
  UFs, grosso demais para localizar alguém); `city` é gravado e **nunca exposto**
  — não sai em resource, prop ou API. **E a UF some quando `is_live` está ligado**
  (`v-if="performer.state && !performer.is_live"` nas três superfícies), que era
  exatamente o ponto do R2: presença ao vivo + localização é outra categoria de
  risco. **Nada de coordenadas e nada de API de geolocalização do navegador** — os
  campos são digitados pela performer.
- **R3 — quem filtra e salva busca é o MEMBRO**, confirmado pelo PO. O texto de
  origem dizia "performer pode salvar" e estava trocado.
- **R8 — tabela de junção**, não `whereJsonContains`. `performer_tag` e
  `member_interest` são tabelas próprias com índice; o full scan que o R8 previa
  para ~12 facetas combináveis não chega a existir. **`worlds` (Sprint 7) segue
  em json** e continua escapando por volume baixo — quando virar gargalo, o
  caminho já está pavimentado.
- **`ethnicity` permanece cortado** (decisão de 27/07/2026). Não voltou nem como
  coluna nem como faceta.
- **Interesse Expandido a visitantes** — a performer pode sinalizar para quem
  aparece no painel de visitantes, não só para seguidor.

### Detalhes que não se deduzem do diff

**A segunda porta do Interesse não afrouxou o piso — ela consome as mesmas
linhas.** `ProfileVisitService::resolveVisitorHandle()` resolve o handle contra
exatamente as linhas que `panelFor()` renderiza. Os dois pisos, o k-anonimato por
faixa e o corte de `$limit` escondem o alvo do envio do mesmo jeito que escondem
a linha do painel — por construção, não por uma segunda checagem que poderia
divergir. É o invariante do `CLAUDE.md` (a tela e o envio têm que concordar,
senão o par 404/201 vira oráculo). Ghost Mode e Modo Discreto não precisam de
guard aqui: eles nunca produziram linha em `profile_visits`.

**A origem é ROTA, não campo do payload.** Rota e Form Request separados por
porta, de propósito: um `source` no corpo deixaria o chamador pedir "visitor" e
cair no predicado de seguidores. A coluna `source` em `performer_interests` existe
para a **cota diária por origem** (5 seguidores + 3 visitantes), não para
roteamento. O **cooldown continua comum às duas portas** — 30 dias por par
(performer, membro) — senão bastava trocar de tela para dobrar os toques. E a
origem **nunca chega ao membro**: a caixa mostra o mesmo sinal cego, ao mesmo
custo de 15 tokens.

**O filtro de conteúdo passou a valer em campo de perfil, não só em chat.** `bio`
e `looking_for` são texto livre que a performer publica; sem o filtro, a oferta
que o chat barra migrava para o perfil. É a mesma lista e o mesmo
`ChatContentFilter` — não nasceu um segundo conjunto de regras para manter em
sincronia.

**O fix do #93 é de cobertura, não de categoria nova.** A lista pegava a oferta
na segunda pessoa e escapava a primeira ("faço programa"). Continua valendo tudo
do §17.3: encontro sem valor monetário passa, troca de contato passa, palavrão
consentido passa.

**O PanicButton virou camada reservada.** `Teleport to body` + `z-[10001]`, com
`PanicButtonLayerTest` cobrando na fonte que nenhum outro `.vue` declare
`z-index >= 10001`. O 10001 (e não 10000) evita mexer no `AgeGateModal` (9999) e
na `IntroAnimation` (10000), que coexistem no `GuestLayout` nessa ordem de
propósito. Ver §15.1.

---

## Sprint 9B — Em andamento

> **NÃO FECHADO. Sem tag.** Range até aqui: `1a51d77..b620e9e` — **4 PRs, #101 a
> #104**, todos pela Regra de Ouro do Git Flow. Suíte: 912 (fecho do Sprint 8) →
> **1141 testes verdes** (5956 asserts), somando o 9A e o 9B.
>
> **O que "em andamento" quer dizer aqui:** a Foto Efêmera do Membro está
> **completa ponta a ponta** — processamento, storage cifrado, expiração,
> endpoints, UI de chat e GC — e a suíte está verde. Ela **não pode ser ligada
> para usuário real** enquanto os 🔴 abaixo estiverem abertos. Terminar a feature
> não é escrever mais tela; é fechar aquela lista.

### ENTREGUE

| Entrega | PR |
|---|---|
| **`ImageProcessingService`** — re-encode que mata EXIF/GPS na ingestão, com guardas de imagem-bomba lidas do header antes de qualquer decodificação | #101 |
| **Models, store, service e GC da foto efêmera** — `MemberPhoto`, `MemberPhotoAccess`, `MemberPhotoStore` (Crypt sobre disco privado), `MemberPhotoService`, command `DeleteExpiredMemberPhotos` | #102 |
| **Endurecimento pós-revisão de segurança** — IDOR nos quatro verbos, race do grant, teto de imagem-bomba, GC que não "limpa" em silêncio, cobertura no Hard Delete LGPD | #103 |
| **Endpoints + UI + gates** — `upload` / `share` / `revoke` / `serve`, gate de compartilhamento, `EphemeralPhotoPanel.vue` e `SharePhotoModal.vue` no chat | #104 |

**Migrations novas (2):** `create_member_photos_table`,
`create_member_photo_access_table` (73 → **75**).
**Models novos (2):** `MemberPhoto`, `MemberPhotoAccess`.
**Services novos (3):** `ImageProcessingService`, `MemberPhotoService`,
`MemberPhotoStore`. **Support novo:** `ExpirySlot`. **Command novo:**
`DeleteExpiredMemberPhotos`. **Config novo:** `config/image.php`.
**Dependência nova:** `intervention/image` — **a primeira do projeto que parseia
arquivo controlado pelo atacante** (ver o 🟡 do `composer audit`).

**Rotas (4, todas web — não há porta de API para foto):** `member.photos.store`,
`member.photos.share`, `member.photos.destroy`, `member.photos.image`, mais
`performer.photos.image` do lado que recebe. Todas dentro dos grupos que já
carregam `2fa` e `documents.accepted`, e todas em `config/ziggy.php`.

### Decisões tomadas (e o porquê de cada uma)

**Chat ativo é o gate para compartilhar.** O membro só manda foto para performer
com quem tem chat ativo — o gate vive em `MemberPhotoService::shareWith()`, e
`grantTo()` segue sem ele de propósito (é o primitivo que `shareWith()` usa
depois de checar; **chamador novo entra por `shareWith()`**). "Chat ativo"
pergunta ao `ChatAccessService` se o membro **pode enviar mensagem agora**
(`can_send`), e não se existe linha em `chat_access`: assinante de Círculo tem
chat livre e **não gera linha** — a leitura literal recusaria justamente quem
paga mais. Carência (`grace`) não passa: quem não pode nem responder não recebe
rosto novo. A recusa é sempre a mesma (`no_active_chat`), para não devolver ao
membro o estado da conta dela.

**Teto de 13 MP na imagem-bomba** (`config/image.php`, `max_pixels`). Não é
número redondo escolhido por gosto: 49 MP pedem ~200 MB dentro do
`imagecreatefrom*`, e estourar `memory_limit` em PHP é **fatal error, não
`Throwable`** — não cai no catch, o worker php-fpm morre, o cliente leva 502 e o
temporário **em claro** fica órfão em `/tmp`. Em laço, derruba o pool. E o teto
não podia ser menor: 4 MP são 2000x2000 e recusariam a foto de um iPhone
(4032x3024 = 12,2 MP), que é a entrada primária do produto. A conta está escrita
no config para quem for mexer. **Pendência registrada lá: ninguém verificou o
`memory_limit` real do php-fpm em produção**, que é o número do qual a conta
depende.

**Tempo restante em FAIXA, nunca em relógio** (`app/Support/ExpirySlot.php`).
"Expira hoje" / "em alguns dias" / "nesta semana", pelo mesmo motivo do
`visited_slot` do painel de visitantes (item 12 do `CLAUDE.md`): um countdown
"expira em 71h48" com TTL de 72h devolve o `granted_at` ao minuto — e é pior que
o caso original, porque o TTL vem de um menu de três opções e a performer conhece
a base da subtração. O TTL escolhido **também não é exibido**: 24h vs 7 dias é
postura do membro. `ExpirySlot` é classe própria porque a faixa tem dois
consumidores (o acesso da performer e a lista do membro) e duas cópias
divergiriam.

**Des-anonimização consentida — a feature não é de privacidade.** Registrado
porque é o eixo do desenho, não uma ressalva de rodapé: o `FanAlias` deriva o
pseudônimo **por par** para que nada correlacione entre perfis, e **o rosto é uma
chave de join global** — duas performers que receberam foto do mesmo membro
comparam as imagens fora da plataforma e desfazem exatamente esse isolamento. O
TTL protege o arquivo, **não a memória nem o print**. A UI diz isso no momento do
envio, não nos Termos. **Não descreva esta feature como "a performer não guarda
sua foto"** — mesma disciplina de linguagem do painel de visitantes e do
geobloqueio.

### Detalhes que não se deduzem do diff

**Expiração vale na LEITURA; o job é só garbage collection.** Se o único
mecanismo que corta o acesso fosse o job apagando o arquivo, job parado não
custaria disco — custaria privacidade. `readForPerformer()` confere os dois
prazos a cada request. É o precedente do `ChatAccess`.

**A linha morre em hard delete, não em soft.** A linha soft-deletada guardava
`user_id`, `size_bytes` e `created_at` indefinidamente — *"o membro X mandou 43
fotos, nestes horários"* — e a varredura do GC decifraria `path_encrypted` de
todas elas a cada hora só para pular. `deleted_at` sobrou como **estado
intermediário** (linha ida, bytes de pé), que é o que a varredura recolhe; a
linha só sai depois de confirmado que os bytes saíram do disco.

**Validação em rota web não vira JSON sozinha.** `shouldRenderJsonWhen` só liga
o JSON em `api/*`: uma `ValidationException` num endpoint web vira
redirect-com-erros-de-sessão mesmo com `Accept: application/json`, e o `fetch` do
front receberia HTML. Resolvido com o trait `Web\Concerns\FailsValidationAsJson`.
**Vale para todo endpoint web novo que o JavaScript consumir** — não é detalhe
desta feature.

**`SubstituteBindings` roda ANTES do middleware de rota.** Um teste de gate com
id inexistente recebe 404 do binding e **passa sem nunca exercitar o gate**. Os
testes de `role` e de `2fa` usam id existente de propósito. Pegadinha
transversal, registrada aqui porque foi aqui que apareceu.

**O serving não usa URL assinada.** A autorização é por sessão, a cada request.
`Content-Type` sai de re-sniff no servidor (`finfo` sobre os bytes decifrados,
allowlist de `image/jpeg`), nunca do que o upload declarou; `Content-Disposition:
inline` com nome genérico e `Cache-Control: no-store`.

**403 vs 404 no serving é decisão do PO, com custo conhecido.** Acesso de outra
performer dá 403; vencido dá 404. O custo: o par diz que aquele id de acesso
existe. É sinal fraco (não diz de quem para quem, e a mensagem é uniforme) — se
incomodar, o conserto é 404 nos dois.

**O disco de fotos fica fora do backup por construção.** `docs/backup.sh` é
**allowlist** — só `storage/app/private` e `storage/app/kyc` entram no tarball —
e o disco novo é `storage/app/member-photos`. Era a decisão § 1.7 (foto efêmera
não sobrevive ao TTL num backup), e ela está satisfeita **sem** ter sido escrita
como exclusão explícita: quem trocar aquele script por denylist reintroduz o
problema em silêncio.

### 🔴 BLOQUEADORES ANTES DO GO-LIVE DA FEATURE

Nenhum destes impede desenvolver; todos impedem **ligar para usuário real**.

- 🔴 **Foto não é denunciável.** `Report::REPORTABLE_TYPES` só conhece
  `performer` e `message`. O produto passou a transportar imagem de usuário e
  **não há caminho de denúncia para ela** — nem da performer que recebe algo
  ilegal, nem do membro. Numa plataforma adulta isso é exposição de compliance,
  não dívida de UX.
- 🔴 **Quarentena: o GC tem de pular foto denunciada.** Depende do item acima e
  é o outro lado dele: hoje `purgeExpired()` apaga por TTL sem consultar nada, e
  a denúncia de uma foto que expira em 24h chega para um arquivo que já não
  existe. Denúncia sem prova é denúncia que não sustenta ação — e é a mesma
  classe do 🔴 2.4 dos Stories ("auto-delete é destruição de prova embutida no
  produto").
- 🔴 **Nenhum `audit_log` no fluxo.** Upload, share, revoke e leitura pela
  performer não deixam trilha. É a única trilha que sobraria depois que os
  acessos são apagados no TTL. Quando entrar: **id da foto/acesso e nada mais** —
  sem caminho, sem nome de arquivo, sem bytes (mesma regra do filtro de chat).
- 🔴 **Extrair `canMemberSend()` — fonte única entre chat e foto.** As duas
  portas do gate (`can_send` + `conversation->status === 'active'`) são hoje uma
  **cópia** do que `ChatService::sendMessage()` faz, e cópia diverge — foi assim
  que o `status === 'active'` passou batido na primeira versão. Não foi unificado
  porque `sendMessage()` distingue as duas falhas em exceções diferentes
  (`conversationArchived` vs `accessRequired`) e unificar mudaria a resposta do
  chat. **Enquanto não for unificado: regra nova no envio de mensagem tem de ser
  replicada em `MemberPhotoService::shareWith()`.**

### 🟡 Aberto — não bloqueia go-live, mas está em dívida

- 🟡 **Cap de performers por foto (§ 1.1 do `SECURITY_ISSUES.md`).** `grantTo()`
  não limita com quantas performers a mesma foto é compartilhada, e o agregado
  *"você compartilhou sua foto com N performers"* não existe. É a **única
  mitigação registrada** do risco central da feature (o rosto como chave de join
  entre perfis) — não previne, põe o agregado na frente de quem carrega o risco.
- 🟡 **Varredura de órfãos no disco.** Os bytes são gravados **fora** da
  transação e a compensação é só o `catch`: timeout, OOM ou SIGKILL entre a
  gravação e o commit deixam arquivo cifrado **sem linha** — e como todo o GC
  parte da tabela, esse arquivo nunca é recolhido (retenção infinita, com o id do
  titular legível no nome do diretório). O Hard Delete de conta **agravou**: as
  linhas saem por `forceDelete()` na transação e os bytes depois, em best-effort.
  Mitigação barata enquanto a varredura não existe: `deleteFiles()` conferir
  `exists()` depois do delete e adiar o `forceDelete()` da linha para a rodada
  seguinte (`deletions:process` é diário e idempotente).
- 🟡 **`composer audit` como hard fail no CI.** Hoje `|| true`
  (`.github/workflows/deploy.yml`, linha 66). Era item de higiene genérico; o 9B
  **mudou o peso dele** ao adicionar `intervention/image`, a primeira dependência
  que parseia arquivo controlado pelo atacante.

### Onde está o registro completo

`docs/SECURITY_ISSUES.md`, seções "Sprint 9B — Revisão pós-implementação da Foto
Efêmera", "Follow-ups aceitos pelo PO", "Bloqueadores para o PR dos endpoints —
FECHADOS" e "Gate de compartilhamento — RESOLVIDO". A pré-análise que originou o
desenho é a seção "Sprint 9 — Pré-análise de Segurança", itens § 1.1 a § 1.11.

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

> A suíte tem **1141 testes** e leva **~2min**. Em foreground isso estoura o
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

### 5.1 Models (30) e seus domínios

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
| `PerformerTag` | tags da performer (Sprint 9A) | **tabela de junção** com índice — decisão R8, não `whereJsonContains` |
| `MemberInterest` | interesses do membro (Sprint 9A) | idem R8; mesmo conjunto de tags da performer |
| `MemberPhoto` | foto efêmera do membro (Sprint 9B) | `path_encrypted`; `user_id` e `expires_at` em `$hidden`; `ACTIVE_LIMIT` 5, `TTL_HOURS` [24,72,168]; morre em **hard delete** |
| `MemberPhotoAccess` | acesso de uma performer a uma foto (Sprint 9B) | FKs e `expires_at` **fora do `$fillable`** (o prazo é derivado do clamp) |

### 5.2 Migrations (73) — linha do tempo

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
- **Sprint 9A (jul):** `create_performer_tag_table`,
  `add_additional_fields_to_performer_profiles` (`languages`, `drinks`, `smokes`,
  `height_cm`, `looking_for`), `create_member_interest_table`,
  `add_seeking_to_users`, `add_source_to_performer_interests` (cota por origem),
  `add_location_to_performer_profiles` (`state` público / `city` interno),
  `add_welcome_email_sent_at_to_users`.
- **Sprint 9B (jul):** `create_member_photos_table`,
  `create_member_photo_access_table`. Disco novo `member_photos`
  (`storage/app/member-photos`) em `config/filesystems.php` — privado,
  `serve => false`, e **fora do `backup.sh`** (que é allowlist).

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

### 15.1 Panic Button (`0372e1e`, PR #71; camada corrigida no PR #100)

Botão de pânico que **desloga a sessão** e redireciona para uma URL neutra.
`PANIC_REDIRECT_URL` no `.env` (default `https://www.google.com.br`). Objetivo:
saída rápida da tela em situação de risco físico.

**Camada reservada (Sprint 9A, PR #100).** Nasceu `z-50` dentro da div raiz do
`AppLayout` — que **não cria stacking context** —, então qualquer overlay acima
de 50 o cobria **e engolia o clique**. Dois já faziam isso: `Modal.vue` (`z-50`,
mais tarde no DOM, então o empate ia para ele) e o overlay de onboarding do PR #99
(`z-[9000]`). No desktop restava o duplo-Escape; **no touch não há Escape**, e a
saída rápida ficava inalcançável exatamente na situação que ela existe para
resolver.

Hoje: `Teleport to body` + `z-[10001]`. O Teleport importa tanto quanto o número —
um `transform` em qualquer ancestral futuro criaria stacking context e prenderia o
botão lá dentro, por mais alto que fosse o z-index.

> **Invariante: nenhum outro componente declara `z-index >= 10001`**, e
> `tests/Feature/PanicButtonLayerTest.php` cobra isso lendo a fonte (o projeto não
> tem Vitest/Jest). **Overlay novo entra ABAIXO — não suba o overlay.** O 10001, e
> não 10000, é para não mexer no `AgeGateModal` (9999) e na `IntroAnimation`
> (10000), que coexistem no `GuestLayout` nessa ordem de propósito (a splash cobre
> o gate 18+ até terminar) e não têm inteiro livre entre elas.

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

137 rotas no total. `routes/web.php` (102 `Route::`), `routes/api.php` (39 HTTP),
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
- [ ] **hCaptcha na política de privacidade e no registro de subprocessadores**
      (R7). O código subiu no Sprint 9A (PR #97); o registro legal **não**. É
      subprocessador terceiro que enxerga o **IP de quem faz login/cadastro numa
      plataforma adulta** — a mesma classe de dado que o resto do projeto trata
      com cuidado. Bloqueia subir com a chave real em produção, não o
      desenvolvimento. Enquanto `HCAPTCHA_ENABLED=false`, nenhuma requisição sai
      para o hcaptcha.com e a exposição é zero.

### A.2 Sprint 8 — o que sobrou

O Sprint 8 fechou (tag `v1.0-sprint8`): status `banned`, lista negra antifraude,
KYC Nível 2 de membro, edição de `worlds` no profile-edit e o toggle de senha
saíram da lista — o registro do que foi entregue está na seção
"Sprint 8 — O que foi entregue". **Seguem abertos**, arrastados do Sprint 8:

- [ ] **Soft descriptor Asaas** (nome na fatura do cartão/PIX) — depende do CNPJ.
      Também aparece como item de UX no Sprint 9 (mostrar o descriptor **antes**
      de cobrar) e é bloqueador de go-live.
- [ ] **KYC Didit em produção** — sair do driver `fake`, confirmar o encoding do
      `x-signature` do webhook v3 contra o ambiente real. Agora vale para as duas
      trilhas: performer (Sprint 5) **e** membro Nível 2 (Sprint 8).
- [ ] **Fila de revisão de 48h do KYC de membro** — a submissão, o `pending_kyc`
      e a fila admin existem; o SLA de 48h não é medido nem alertado por nada.

Arrastados do Sprint 7 (previstos e **não iniciados** — seguem abertos):

- [ ] **Age verification contra base oficial** (Serpro/DataValid) — gravar
      `method = 'serpro'` na mesma tabela para distinguir níveis.
- [ ] **Login web em dois passos** (desafiar 2FA antes de estabelecer a sessão).
- [ ] **Alerta em N falhas de desafio 2FA** (hoje só grava audit, ninguém consome).
- [ ] **Fila humana de moderação com contexto** (reports com corpo/contexto).
- [ ] **Módulo de conteúdo** — quando existir, construir moderação e pipeline de
      verificação **antes** do primeiro upload. Vincular conteúdo↔pessoa
      verificada. Ver `docs/LEGAL_GAP_ANALYSIS.md`.

### Sprint 9 — UX e Monetização (baseado em SEEKING_UX_CASE_STUDY.md)

> **⚠️ Atualizado no Sprint 9B (29/07/2026). Das três trilhas do Sprint 9, duas
> saíram e a terceira — a mais perigosa — segue sem uma linha de código.**
>
> - ✅ **Trilha de UX e descoberta — ENTREGUE** (PRs #88–#100): tags, campos
>   adicionais, interesses do membro, filtros do catálogo, badges, contador de
>   bio, localização opt-in, hCaptcha, e-mail do fundador, tutorial de onboarding.
>   Ver "Sprint 9A — O que foi entregue".
> - 🟡 **Foto Efêmera do Membro — IMPLEMENTADA, NÃO LIBERADA** (PRs #101–#104).
>   Existe ponta a ponta e a suíte está verde; **4 bloqueadores 🔴 impedem o
>   go-live da feature** (denúncia de foto, quarentena no GC, audit log,
>   `canMemberSend`). Os itens `[x]` logo abaixo são o registro do escopo
>   entregue. Ver "Sprint 9B — Em andamento".
> - 🔴 **Stories da Performer — NÃO INICIADA.** Nenhuma linha de código. É o
>   Sprint 9C (§ próprio, abaixo) e continua com os **7 🔴** da pré-análise
>   abertos — incluindo o pipeline de moderação exigido **antes** do primeiro
>   upload.
>
> **Consequência para quem pega o próximo sprint:** a janela do §1 continua aberta
> **para Stories**, que é publicação de conteúdo de verdade. A Foto Efêmera abriu
> upload 1:1 privado e trouxe os próprios 🔴 junto — fechar os dela **não** fecha
> os de Stories. Começar a trilha de Stories pelos 🔴, não pela feature.
>
> **Itens de alta prioridade do Sprint 9 que NÃO saíram:** a verificação de
> documento como produto (R$9,90, badge "✓ ID Verificado") — que é a fonte de dado
> do badge cujo slot o PR #90 reservou —, o carrossel de múltiplas fotos e os
> itens de copy do checkout de KYC pago.

**Alta prioridade:**
- [ ] Verificação de documento como produto (RG/CNH via Didit) — R$9,90 taxa única
      para performers SEM assinatura ativa de Círculo. Performers com Círculo ativo
      recebem o badge de documento incluído sem custo adicional.
      Badge "✓ ID Verificado" aparece no catálogo com destaque (posição + ícone).
      Receita estimada: R$2–5 de margem por verificação (custo Didit ~R$5–8).

**Média prioridade:**
- [ ] Tutorial de produto em 4 slides (first-run overlay) para membros na primeira
      entrada no catálogo. Catálogo real aparece desfocado ao fundo.
      Slides: (1) Explore criadores verificados, (2) Tokens, (3) Gorjetas, (4) CTA "Entrar no Portal →"

**Baixa prioridade (copy e UX):**
- [ ] Contador de caracteres inline no bio de performer com copy de progresso
      (ex: "Ótimo! Perfis com bio completa recebem 3x mais interesse")
- [ ] Placeholder com audiência definida nos campos de bio
      (ex: "Conte aos membros premium o que te torna única")
- [ ] "Pagamento único. Verificação permanente." abaixo de todo checkout de KYC pago
- [ ] Item pré-selecionado no checkout de KYC pago (borda dourada, sem precisar clicar)
- [ ] Soft descriptor proativo: mostrar como o pagamento aparece na fatura ANTES de cobrar
      (elimina chargeback na origem — também é bloqueador de go-live, confirmar com Asaas)

**Feature: Controle de Visibilidade de Foto do Membro — ✅ IMPLEMENTADA no Sprint
9B (PRs #101–#104), 🔴 NÃO LIBERADA.** Lista mantida como memória do escopo; o
que falta para ligar está em "Sprint 9B — Em andamento", não aqui.

- [x] Envio de fotos privadas efêmeras para performers específicas no chat —
      **com gate: só performer com chat ativo** (decisão do PO de 29/07/2026, que
      a lista original não previa)
- [x] Foto efêmera: cifrada (`Crypt` sobre disco privado `member_photos`), TTL
      escolhido pelo membro entre 24h / 72h / 7 dias (`MemberPhoto::TTL_HOURS`)
- [x] Após expirar: arquivo deletado do disco. **E a expiração vale na LEITURA** —
      o job é só GC, não é o que corta o acesso
- [x] Membro pode revogar acesso antes de expirar (`member.photos.destroy`)
- [x] Performer vê indicador de tempo restante — **em FAIXA, nunca em relógio**
      (`ExpirySlot`), e o TTL escolhido não é exibido
- [x] Tabela: `member_photos` · [x] Tabela: `member_photo_access`
- [x] Command: `DeleteExpiredMemberPhotos` (GC; a linha sai em **hard delete**,
      só depois de confirmar que os bytes saíram)
- [x] Backup: o disco fica fora do `backup.sh` — **por allowlist, não por exclusão
      explícita** (o script só leva `storage/app/private` e `storage/app/kyc`).
      Quem trocar aquele script por denylist reintroduz o problema em silêncio
- [x] EXIF/GPS: `intervention/image` + re-encode na ingestão
      (`ImageProcessingService`), uma vez por foto, antes de cifrar
- [x] Cap: máximo 5 fotos ativas por membro (`MemberPhoto::ACTIVE_LIMIT`),
      verificado no submit, não no GC
- [ ] ~~Membro escolhe entre foto pública (permanente) ou perfil sem foto~~ —
      **não implementado.** Não há foto pública de membro no produto; a metade
      "ou perfil sem foto" é o estado atual por omissão, e a efêmera não ficou
      condicionada a ela. Se a escolha explícita for para valer, é escopo novo.

**Feature: Stories da Performer (feed efêmero — Modelo C) → virou o SPRINT 9C.
Ver a seção própria abaixo. A lista de escopo continua aqui.**
- [ ] Performer posta foto/vídeo curto com expiração de 24h fixo
- [ ] Seguir é automático — membro clica "Seguir" e já acessa
- [ ] Stories em 3 níveis de visibilidade (performer escolhe por post):
      Nível 1 — Público: todos os seguidores veem
      Nível 2 — Assinantes: só membros com Círculo ativo (qualquer tier)
      Nível 3 — Exclusivo: só membros Black e Founders Circle
- [ ] Membros Black/FC podem ver Stories públicos de performers que não seguem ainda
- [ ] Indicador de Stories não vistos no catálogo (ponto dourado no avatar)
- [ ] Stories expirados: arquivo deletado do disco, removido do feed
- [ ] Job: DeleteExpiredStories (roda a cada hora)
- [ ] Tabela: performer_stories (id, performer_profile_id, media_path_encrypted, 
      visibility_level enum(public/subscribers/exclusive), expires_at, deleted_at)
- [ ] Decisão de produto: Modelo C — seguir é livre, controle é por nível de conteúdo,
      não por aprovação de seguidor. Cria incentivo para assinar Círculo.
- [ ] Visualizações: faixa de membros únicos (não lista, não repetições)
- [ ] Nível 3 (Exclusivo): sem contador de visualizações — retorna null, não zero.
      Níveis 1 e 2: faixa de membros únicos.
- [ ] Mídia: v1 só imagem, vídeo no Sprint 10

> **Revisão de segurança pré-implementação:** `docs/SECURITY_ISSUES.md`, seção
> "Sprint 9 — Pré-análise de Segurança". A pré-análise registrou **11
> bloqueadores 🔴** somando as duas features. Estado em 29/07/2026:
>
> - **Feature 1 (Foto Efêmera) — os 4 🔴 foram endereçados no Sprint 9B**
>   (§ 1.1 des-anonimização consentida, § 1.2 faixa em vez de relógio,
>   § 1.3 expiração na leitura, § 1.5 cobertura no `DeletionService`). **Dois
>   deixaram resíduo aberto**: o cap de performers por foto (§ 1.1) e a varredura
>   de órfãos (§ 1.5), ambos 🟡 na seção do 9B. Os títulos seguem marcados 🔴 no
>   `SECURITY_ISSUES.md` porque aquela seção é o registro da pré-análise, não um
>   painel de status — **o status vive na seção do 9B**.
> - **Feature 2 (Stories) — os 7 🔴 continuam ABERTOS, todos** (§ 2.1 a § 2.7).
>   Nenhum foi tocado: nada de Stories foi codificado. Incluem a moderação — o
>   backlog exige pipeline **antes** do primeiro upload — e o padrão de URL
>   assinada que destruiria o paywall do Modelo C.

**Referência:** docs/SEEKING_UX_CASE_STUDY.md (41 telas analisadas, julho/2026)

---

### Sprint 9 — Sistema de Tags, Filtros e Descoberta ✅ ENTREGUE (Sprint 9A)

> **Esta trilha inteira foi entregue nos PRs #88–#100.** O registro do que saiu
> está em "Sprint 9A — O que foi entregue". A lista abaixo fica como **memória do
> escopo e das decisões**, com os itens marcados `[x]` — não como trabalho
> pendente. O que **continua aberto** do Sprint 9 é a outra trilha, de conteúdo
> efêmero (Foto Efêmera + Stories), logo acima nesta mesma seção.
>
> **Uma correção de premissa vale registrar:** o escopo original dizia
> `tags`/`interests` como `json[]`. **Não foi assim que saiu** — o R8 venceu e os
> dois viraram **tabela de junção** (`performer_tag`, `member_interest`) com
> índice. Onde o texto abaixo disser json, o código vence.

**Contexto:** baseado em análise de 37 telas reais do Seeking.com (julho/2026).
Objetivo: transformar o catálogo de grade de fotos em sistema de descoberta por
afinidade.

**Tags da performer** (~~campo `tags` json[]~~ → **tabela `performer_tag`**, máx 8):

- *Estilo de vida:* Viajante · Fitness · Gourmet · Praia · Arte · Música · Moda ·
  Yoga · Games · Aventura · Festa · Luxo
- *Personalidade:* Extrovertida · Misteriosa · Divertida · Intelectual ·
  Carinhosa · Discreta · Apaixonada · Dominante · Submissa
- *O que oferece:* Conversa · Companhia · Conteúdo exclusivo · Live · Fantasia ·
  Roleplay · Dança · Striptease

**Campos adicionais da performer** — todos entregues no PR #91:

- [x] `languages` — idiomas (Português/Inglês/Espanhol/Francês/Italiano/Alemão/Japonês)
- [x] `drinks` enum — Não bebe / Bebe socialmente / Bebe frequentemente
- [x] `smokes` enum — Não fuma / Fuma socialmente / Fuma
- [x] `height_cm` smallint nullable — altura em cm (slider 140–190)
- [x] `looking_for` text nullable — "O que estou procurando" (texto livre, exibido
      no perfil). **Passa pelo filtro de conteúdo** junto com a `bio` — é texto
      livre publicado, e sem isso a oferta que o chat barra migrava para o perfil.

> **`ethnicity` foi cortado do escopo (decisão do PO, 27/07/2026)** — dado pessoal
> sensível na LGPD (Art. 5º, II, "origem racial ou étnica"). Registrado aqui para
> **não voltar como novidade** num sprint futuro: não é lacuna do backlog, é
> remoção deliberada. Se um dia for reproposto, entra pela porta do princípio 4
> (tabela isolada, cifrada, consentimento específico) e não como coluna em claro
> nem como faceta de filtro público.
>
> **`drinks` e `smokes` ficaram, e a distinção é deliberada — mas eles não são
> neutros.** São **autodeclarados, opcionais e de preenchimento livre**: a
> performer escolhe informar, pode deixar em branco e pode limpar depois. Não são
> dado sensível do Art. 5º, II (não são origem racial/étnica, convicção religiosa,
> opinião política, filiação sindical, saúde, vida sexual ou biometria) — por isso
> seguem como coluna em claro e como faceta de filtro, o que `ethnicity` não
> poderia. **A ressalva:** hábito de bebida e fumo faz fronteira com dado de saúde,
> e um deles combinado com outras facetas estreita a pessoa. Duas consequências
> práticas: (1) **manter opcional para sempre** — se algum dia virar obrigatório
> no onboarding, deixa de ser autodeclaração e passa a ser coleta; (2) **não
> reaproveitar para inferência** (score, ranking, segmentação de preço) sem passar
> pelo PO — o consentimento aqui é para exibir no perfil e filtrar, nada além.
> O mesmo vale para `height_cm`.

**Campos do membro (consumer)** — entregues no PR #92:

- [x] `interests` — tags de interesse (~~json[]~~ → **tabela `member_interest`**,
      mesmo conjunto das tags de performer)
- [x] `seeking` text nullable — "O que estou buscando" (texto livre)

**Filtros do catálogo** (membro filtra performers) — entregues no PR #94, service
unificado entre as duas portas (catálogo autenticado e público):

- [x] Estilo de vida: qualquer tag do conjunto acima
- [x] O que oferece: qualquer tag do conjunto acima
- [x] Bebida: Não bebe / Bebe socialmente / Bebe frequentemente
- [x] Fumo: Não fuma / Fuma socialmente / Fuma
- [x] Idiomas: Português / Inglês / Espanhol / Francês / Italiano / Alemão / Japonês
- [x] Altura: faixa `height_min`/`height_max` (140–190)
- [x] Tier: Verificada / Select / Maison
- [x] Busca por texto: `search`, em `stage_name` e `bio`
- [x] **Localização: só ESTADO** — não cidade. `city` existe na tabela e **nunca
      sai**; ver R2 abaixo e §5.
- [x] Mundo e **Online agora**: já existiam antes do Sprint 9A.

**Continuam ABERTOS desta lista** (não entregues no 9A):

- [ ] **Verificação: "ID verificado"** — depende da verificação de documento como
      produto (item de alta prioridade acima), que **não foi implementada**. O
      badge tem o slot reservado no `VerificationBadges` (PR #90), sem a fonte de
      dado por trás.
- [ ] **Verificação: "Com fotos"** — não existe filtro nem coluna. Depende do
      carrossel de múltiplas fotos, também não entregue.
- [ ] **Salvar busca (filtros favoritos)** — nada implementado. **R3 fica
      confirmado pelo PO: quem salva é o MEMBRO**, não a performer. Quando for
      implementado, é preferência de membro, não do perfil da performer.

**Cruzamento de afinidade (futuro Sprint 10):**
Tags do membro (`interests`) cruzadas com tags da performer → score de afinidade.
Ex.: membro marca "Gourmet" e performer também tem "Gourmet" → aparece em destaque.
Base para "Compatíveis com você" no catálogo. **Ver R4 — é superfície nova de
exposição do membro à performer.**

#### Outros itens do Sprint 9 identificados nas telas do Seeking

**Indicador de online no card do catálogo — ✅ RESTYLE FEITO (PR #88).**
A ressalva do Sprint 8 estava certa e o PR tratou como restyle, não como feature
nova. Registro original preservado abaixo:

O texto de origem diz "só falta aparecer no catálogo". **Aparece.** Os dois cards
(`PerformerCard.vue` do catálogo autenticado e `PublicPerformerCard.vue` do
público) já renderizam `<LiveBadge />` em `absolute top-2 left-2` sob
`v-if="performer.is_live"`, e `is_live` já é filtro funcionando na API
(`PerformerCatalogController`), na web (`CatalogController`) e no
`FilterPanel.vue`. O que o item pede de verdade é **trocar o estilo** (bolinha
verde, canto inferior esquerdo) — não construir a feature. Tratar como novo faria
o Sprint 9 reimplementar o que já roda.

**Múltiplas fotos no perfil da performer:**
- [ ] Carrossel de até 6 fotos (hoje só `avatar_path` + `cover_path` — confirmado)
- [ ] Compressão no servidor via `intervention/image` — **não é dependência do
      projeto ainda**; entra junto com o EXIF da foto efêmera
- [ ] Qualidade: 80% JPEG, máx 1200px, ~~guardar original~~ **ver R1 — guardar o
      original colide com a decisão de EXIF já travada pelo PO**
- [ ] Contador de fotos no card ("📷 11" como no Seeking)

**Badges de verificação visíveis no perfil — ✅ PARCIAL (PR #90):**
- [x] ✓ Selfie verificada (já existia via KYC — performer desde o Sprint 5, membro
      desde o Nível 2 do Sprint 8); agora exibida no card e no perfil
- [ ] ✓ ID verificado — **slot reservado, sem fonte de dado.** Depende da
      verificação de documento como produto, não implementada.
- [x] ✓ Email verificado — **booleano, nunca a data.** `email_verified_at` no
      resource dataria o cadastro da performer para qualquer visitante anônimo.
- [ ] ✓ Instagram (OAuth Meta — Sprint 10; ver R5)

**Contador de caracteres motivacional no bio — ✅ ENTREGUE (PR #89):**
- [x] 0–49: "Conte mais sobre você..." · 50–149: "Bom começo! Continue..." ·
      150–299: "Você está indo bem! 🔥" · 300+: "Perfil completo atrai mais membros ✓"

**Email de boas-vindas do fundador — ✅ ENTREGUE (PR #98):**
- [x] Email pessoal de Robson + Bruno, enviado via Resend após KYC aprovado
      (`welcome_email_sent_at` em `users` guarda o envio — não reenvia)
- [x] **R6 atendido:** envelope neutro. O que a caixa de entrada mostra não
      denuncia cadastro em plataforma adulta; o corpo é que é pessoal.

**hCaptcha no login e cadastro — ✅ ENTREGUE (PR #97):**
- [x] `HCaptchaVerifier` + `config/hcaptcha.php`. Só a chave **pública** vai ao
      front, e só quando ligado — desligado, o componente não monta e **nenhuma
      requisição sai para o hcaptcha.com**.
- [ ] **R7 continua ABERTO e não é dívida de código:** hCaptcha é
      **subprocessador terceiro** que vê o IP de quem entra numa plataforma
      adulta. Falta entrar na **política de privacidade** e no **registro de
      subprocessadores** antes de subir com a chave real. Ver A.1.

**Geolocalização no perfil — ✅ ENTREGUE, com escopo reduzido de propósito (PR #96):**
- [x] Opt-in — a performer pode não preencher e pode limpar depois. Ausente é o
      padrão, não pendência a cobrar.
- [x] `state` **público**; `city` gravado e **nunca exposto** (nenhum resource,
      prop ou API).
- [x] **Nada de coordenadas.** Não há `lat`/`lng`, e não deve haver.
- [ ] ~~Pedir permissão de localização durante onboarding~~ **CORTADO.** A tela
      **não usa a API de geolocalização do navegador** — os campos são digitados
      pela performer. Pedir permissão traria coordenada exata para dentro do
      produto justamente onde ele depende de a performer não ser localizável.
- [x] ~~Exibir "Agora: São Paulo"~~ → **R2 resolvido:** exibe a UF, e **some
      quando `is_live` está ligado**. Presença ao vivo + localização era o risco.

#### Ressalvas registradas antes de implementar

Levantadas no fecho do Sprint 8, na conferência do texto contra o código. Não são
vetos — são pontos que colidem com decisão já travada ou com princípio do
`CLAUDE.md`, e que ficam mais baratos de resolver agora do que depois da migration.

> **Status no fecho do Sprint 9A:** **R2, R3 e R8 estão RESOLVIDOS** e viraram
> código (detalhe em "Sprint 9A — O que foi entregue"). **R1 segue valendo** como
> regra geral de ingestão de imagem — não foi exercitado porque nenhuma feature de
> upload de foto entrou no 9A. **R4 e R5 seguem abertos** (são Sprint 10 e não
> foram tocados). **R6 foi atendido** no e-mail do fundador. **R7 segue aberto e
> não é código:** hCaptcha subiu, mas a entrada dele na política de privacidade e
> no registro de subprocessadores não — ver A.1.

> A ressalva sobre `ethnicity` saiu junto com o campo: o PO cortou o escopo em
> 27/07/2026 e não há mais o que ressalvar. O registro da remoção está na lista de
> campos acima. **`drinks`/`smokes` ganharam ressalva própria ali** (autodeclarado,
> opcional, sem inferência) — não é a mesma classe de dado, mas também não é
> neutro.

- **R1 — "guardar original" contradiz a decisão de EXIF do PO (24/07/2026).** A
  decisão travada na Feature de foto efêmera é *re-encodar na ingestão, removendo
  metadado antes de cifrar*. Guardar o original preserva exatamente o EXIF/GPS que
  a decisão manda remover — e, no caso da performer, a coordenada costuma ser a
  casa dela. Se o original for necessário por qualidade, tem que ser o original
  **já re-encodado sem metadado**, não o arquivo cru do upload.

- **R2 — "Agora: São Paulo" + `is_live` é presença em tempo real com
  localização.** Cidade sozinha é grosseira; cidade **combinada com "online
  agora"** estreita muito, e é a performer (lado com KYC, endereço e rosto) que
  fica exposta. O `opt-in` cobre a parte legal, não a de segurança física. Sugerido
  ao PO: desacoplar — ou localização **ou** presença ao vivo visível, não os dois
  no mesmo card; e considerar granularidade de estado em vez de cidade.

- **R3 — "performer pode salvar filtros favoritos" está trocado.** A seção inteira
  é "membro filtra performers"; quem salva a busca é o **membro**. Mantido acima o
  texto original com a marcação, para o PO confirmar em vez de o implementador
  adivinhar.

- **R4 — o cruzamento de afinidade cria superfície nova de membro → performer.**
  Regra do `CLAUDE.md`: *toda* exposição de membro à performer passa por
  `FanAlias`, nunca pelo id. Além disso, "Compatíveis com você" pode deixar a
  performer **inferir os `interests` do membro** — e o `FanAlias` é estável por
  par, então o inferido cola no mesmo pseudônimo que já carrega gorjetas,
  seguidores e visitas. Entra na pré-análise de segurança antes de virar código
  (é Sprint 10, então há tempo).

- **R5 — OAuth de Instagram liga o perfil adulto a uma identidade real.** Badge de
  Instagram na performer é vetor de deanonimização dela (e o inverso do que o
  projeto faz pelo membro). É Sprint 10; registrar agora para não virar
  descoberta tardia.

- **R6 — o e-mail do fundador cai numa caixa de entrada que pode ser compartilhada.**
  Mesma disciplina do drip da waitlist e do Modo Discreto: remetente e assunto não
  podem denunciar que a pessoa se cadastrou numa plataforma adulta. O corpo pode
  ser pessoal; o envelope, não.

- **R7 — hCaptcha é subprocessador terceiro vendo IP no login/cadastro** de
  plataforma adulta. Não é bloqueador, mas entra na política de privacidade e no
  registro de subprocessadores antes de subir.

- **R8 — filtro sobre json[] não usa índice.** `tags`, `languages` e `interests`
  são json, e `whereJsonContains` faz varredura. Com ~12 facetas combináveis, o
  catálogo vira full scan por request. O `worlds` (Sprint 7) já tem esse formato e
  hoje escapa por volume baixo. Decidir na migration: coluna gerada + índice,
  tabela de junção, ou aceitar e medir — mas decidir, não descobrir em produção.

**Referência:** docs/SEEKING_UX_CASE_STUDY.md · análise de 37 telas (julho/2026)

---

### Sprint 9C — Stories da Performer (backlog)

**Nenhuma linha de código escrita.** O escopo do produto é a lista "Feature:
Stories da Performer (Modelo C)" acima; esta seção registra só o que trava.

- 🔴 **7 bloqueadores abertos** na pré-análise (`docs/SECURITY_ISSUES.md`,
  § 2.1 a § 2.7): lista de "quem viu meu story" derrubando o Piso de Anonimato,
  vazamento de tier nos Níveis 2 e 3, URL assinada destruindo o paywall,
  auto-delete de 24h como destruição de prova, `media_path_encrypted` para vídeo
  batendo no bloqueio das FC Sessions, `story_views` no `DeletionService` nos dois
  sentidos, e o guard de Ghost Mode / Modo Discreto no Service desde o dia 1.
- ⛔ **Depende do pipeline de moderação, que tem de existir ANTES do primeiro
  upload.** Não é recomendação: é a regra que o backlog já fixou para o módulo de
  conteúdo, e **Stories é o primeiro upload publicado** do projeto. A Foto
  Efêmera do 9B **não** satisfaz este pré-requisito — ela é 1:1 privada, e o
  próprio 9B abriu 🔴 de denúncia e quarentena que Stories herda ampliados.
- ⛔ **Depende do refactor de `role` para moderação** (§ 2.4). Hoje moderador =
  admin, e um admin vê tudo. O mesmo refactor destrava o Curador das FC Sessions:
  **duas features travadas no mesmo pré-requisito** — ver a memória
  `fc-sessions-vault-blocked`.
- **O que o 9B deixa pronto para reuso:** `ImageProcessingService` (compartilhado
  de propósito — não salva, não cifra, não conhece disco), o padrão
  expiração-na-leitura, `ExpirySlot` e os passos do `DeletionService`.

### Sprint 10 — Backlog

Aberto no fecho do Sprint 9A e ampliado no 9B. Além do que já estava marcado como
Sprint 10 acima (cruzamento de afinidade / "Compatíveis com você" — ver **R4**;
badge de Instagram via OAuth Meta — ver **R5**; vídeo nos Stories), entram os
caminhos abaixo.

> **Registrados por título, spec pendente.** O PO nomeou os dois no fecho do 9A;
> o detalhe de escopo **não foi transferido para este doc** e não está em nenhum
> commit, doc ou issue do repositório — procurei. O que segue é o nome, o vínculo
> com o que já existe e as perguntas que precisam de resposta antes de virar
> código. **Quem for implementar: peça a spec ao PO, não deduza daqui.**

Contexto comum: os dois são **caminhos alternativos de aproximação**, ao lado do
Interesse Controlado. O "Caminho 1" é o Interesse, que no Sprint 9A ganhou a
segunda porta (seguidores + visitantes, PR #95).

**Caminho 2 — Convite via Stories**
- [ ] Spec pendente com o PO.
- **Dependência dura:** Stories **não existe**. É a trilha de conteúdo efêmero do
      Sprint 9 que continua aberta, com os 🔴 da pré-análise — inclusive o
      pipeline de moderação exigido **antes** do primeiro upload. Este caminho
      não pode ser implementado antes dela.
- **Perguntas em aberto:** quem convida quem (performer→membro, como o Interesse,
      ou membro→performer?); custa token?; o convite respeita o nível de
      visibilidade do Story (público / assinantes / exclusivo)?; entra na mesma
      cota e no mesmo cooldown de 30 dias do Interesse, ou tem os seus?

**Caminho 3 — Badge "Disponível para conversa"**
- [ ] Spec pendente com o PO.
- **É sinal de presença**, e presença é a categoria de risco que o **R2** acabou
      de tratar: a localização da performer some quando `is_live` está ligado,
      justamente para não somar "onde" com "agora". Um badge de disponibilidade é
      outro "agora" — decidir se ele coexiste com a UF ou se entra na mesma
      exclusão.
- **Perguntas em aberto:** quem liga o badge (performer manualmente, ou derivado
      de atividade?); se derivado, é presença inferida e cai na mesma ressalva do
      `is_invisible` (§12.4 / props do Inertia); vale para o membro também, ou só
      para a performer?
- **Se um dia valer para o MEMBRO, é superfície nova de exposição membro →
      performer** e entra pela regra do `CLAUDE.md`: passa por `FanAlias`, nunca
      pelo id, e entra na pré-análise de segurança antes de virar código.

**OTP passwordless por e-mail** (inspirado no Seeking)
- [ ] Spec pendente com o PO.
- **A tese:** sem senha armazenada, **dump de banco não dá login**. Elimina de uma
      vez credential stuffing, reuso de senha e o valor do hash vazado — que numa
      plataforma adulta é o dado que mais machuca o titular se for correlacionado.
- **Colide com coisas já travadas — resolver antes de codar, não depois:**
      (1) o **2FA da performer** pressupõe senha como primeiro fator; OTP por
      e-mail sozinho **rebaixa** a conta que guarda o KYC a fator único, com a
      caixa de e-mail virando a chave inteira. (2) A **caixa compartilhada** é
      ameaça registrada neste projeto (R6, Modo Discreto, drip da waitlist):
      quem lê o e-mail entra na conta. (3) O **login web em dois passos**, já no
      backlog, muda o mesmo fluxo — fazer os dois de uma vez, não em sequência.
- **Perguntas em aberto:** vale para os dois papéis ou só para membro?; convive
      com senha (opcional) ou substitui?; TTL e uso único do código (o projeto já
      tem o precedente do TOTP e do recovery code sob `lockForUpdate`); e o que
      acontece com quem perde acesso ao e-mail, que hoje cai no reset de senha.

**Expansão LATAM** — pós go-live, **não antes**
- [ ] Ordem definida pelo PO: **Argentina → Chile → Paraguai.**
- **Quatro pilares, cada um com dono técnico diferente:**
      **payout** (Wise / dLocal — o Asaas é Brasil-only, e a porta `needs_review`
      e o furo 429/408 do §21 valem por país); **KYC** (Didit multi-país — o
      `x-api-key` e o webhook v3 já servem; o que muda é workflow e documento
      aceito); **age verification** (**documento direto** — o `cpf_dob` é
      brasileiro e não tem análogo, então o `method` da `age_verifications`
      passa a distinguir mais níveis, que é exatamente por que aquela coluna
      existe); **pagamento** (Stripe / dLocal — PIX não atravessa a fronteira).
- **O que não é pilar mas quebra junto:** moeda e `TIER_ORDER` (preço de tier em
      centavos de BRL), i18n (o produto é PT-BR inteiro, incluindo o filtro de
      chat, que é uma lista de termos **em português** — em espanhol ele
      simplesmente não barra nada), e o geobloqueio, que hoje tem allowlist de um
      país só.
- **Antes de qualquer código:** cada país novo é jurisdição nova de conteúdo
      adulto. É análise legal (a mesma classe do `LEGAL_GAP_ANALYSIS.md`), não
      sprint de engenharia.

### A.3 Higiene / dívida técnica

- [ ] CI de lint (Pint `--test`) — hoje não existe; a árvore está limpa (`e043077`)
      mas nada impede regressão.
- [ ] **`composer audit` como hard fail** (hoje `|| true`, linha 66 do
      `deploy.yml`). **Subiu de prioridade no Sprint 9B:** entrou
      `intervention/image`, a primeira dependência do projeto que parseia arquivo
      controlado pelo atacante. Também listado como 🟡 na seção do 9B.
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

10. **Divergência `category` ↔ `worlds` em performers legado.** Performers
    criadas antes do Sprint 7 têm `worlds=null` e são servidas pelo fallback
    `activeWorlds()=[category]`. Se uma dessas performers editar o perfil pelo
    profile-edit (que só aceita `category`), `worlds` continua `null` — o
    catálogo usa o fallback e tudo funciona. A pegadinha surge se no futuro
    `worlds` for populado manualmente (ex: via tinker ou migration de backfill)
    com valor diferente de `category`: o catálogo passa a usar `worlds` e a
    performer some do mundo que `category` dizia. Correção futura: profile-edit
    aceitar `worlds` (Sprint 8, já no backlog A.2). Até lá, **não backfill
    `worlds` sem atualizar `category` junto.** Registrado em: 80ba300
    (multi-worlds, Sprint 7).

11. **Foto Efêmera é des-anonimização consentida, não feature de privacidade**
    (Sprint 9B). O rosto é **chave de join global**: duas performers que
    receberam foto do mesmo membro comparam as imagens fora da plataforma e
    desfazem o isolamento cross-perfil que o `FanAlias` existe para dar. O TTL
    protege o arquivo, **não a memória nem o print** — apagar depois não desfaz o
    download. **Não descreva como "a performer não guarda sua foto".**

12. **O indicador de tempo restante reduz a resolução, não elimina o sinal.**
    Uma foto que nasce em "Expira nesta semana" não escolheu 24h, e isso é
    visível no primeiro carregamento. O que a faixa compra é que o **instante**
    do envio não sai junto. **Não descreva o indicador como "não revela nada
    sobre o envio"** (a ressalva está escrita também no `ExpirySlot.php`).

13. **A conta do teto de 13 MP depende de um número que ninguém verificou:** o
    `memory_limit` real do php-fpm em produção. 13 MP dão ~55 MB de pico no GD —
    seguro em 128M, confortável em 256M. Conferir antes do go-live, e refazer a
    conta antes de mexer no `max_pixels`.

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
| **Foto Efêmera** | foto privada membro→performer, cifrada, TTL 24h/72h/7d, gate de chat ativo (Sprint 9B) |
| **ExpirySlot** | tempo restante em faixa ("expira hoje"), nunca em relógio — o `visited_slot` da foto |
| **Des-anonimização consentida** | o que a Foto Efêmera é: o rosto revoga o `FanAlias` entre perfis, e a UI diz isso no envio |
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
GeoLocationService · SharedRegistrationIpService · HCaptchaVerifier
ImageProcessingService · MemberPhotoService · MemberPhotoStore   (Sprint 9B)
Asaas/ (AsaasHttpClient, FakeAsaasClient) · Kyc/ (DiditKycClient, KycHttpClient,
FakeKycClient, KycDocumentStore) · Waitlist/ (FounderPresenter, …)
```

### Support (`app/Support/`)

```
FanAlias · ReporterAlias · ClientFingerprint · CpfHash · DocumentHash · Audit
ChatContentFilter · AvatarPlaceholder · ExpirySlot   (ExpirySlot: Sprint 9B)
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
DeleteExpiredMemberPhotos   (Sprint 9B — GC; a expiração vale na LEITURA)
```

### Configs (`config/`)

```
app · asaas · auth · broadcasting · cache · chat · chat_filters · cors · database
documents · filesystems · geo · hcaptcha · image · inertia · interest · kyc · logging
mail · queue · reverb · sanctum · services · session · waitlist · ziggy
```

> `config/image.php` é config **do Limen**, não do pacote: o projeto usa a lib
> standalone `intervention/image`, não o wrapper `intervention/image-laravel`
> (que publicaria um `config/image.php` próprio). Se o wrapper entrar um dia,
> **renomeie o nosso, não o dele.**

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
- [ ] Rodar a suíte com os `DB_*` de MySQL e confirmar **1141 verdes** antes de
      começar.
- [ ] **A Foto Efêmera está implementada e NÃO liberada.** Antes de ligar para
      usuário real, fechar os 4 🔴 da seção "Sprint 9B — Em andamento".
- [ ] Endpoint web novo que o JavaScript consumir → trait
      `Web\Concerns\FailsValidationAsJson` (senão a validação vira redirect).
- [ ] Regra nova no envio de mensagem do chat → **replicar em
      `MemberPhotoService::shareWith()`** enquanto `canMemberSend` não for
      extraído para fonte única.
- [ ] Overlay novo entra **abaixo de `z-index 10001`** — a camada é reservada ao
      PanicButton e `PanicButtonLayerTest` cobra (§15.1).
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
real na branch `feat/sprint6-final`; atualizado em 27/07/2026 no fecho do Sprint 8
(`main`, `93b2878`, tag `v1.0-sprint8`), em 29/07/2026 no fecho do **Sprint 9A**
(`main`, `1a51d77`, hoje com a tag `v1.0-sprint9a`) e em 29/07/2026 com o
**Sprint 9B PARCIAL** (`main`, `b620e9e`, PRs #101–#104, sem tag — em andamento).
Números do snapshot e do 9B conferidos contra `git log`, `route:list`, o
filesystem e a suíte rodada de ponta a ponta (1141 verdes, 5956 asserts). Onde
este doc e o código divergirem no futuro, o código vence — e a divergência deve
ser registrada aqui ou no CLAUDE.md.*
