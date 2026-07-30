# Limen — Guia do Projeto (leia antes de qualquer tarefa)

Plataforma premium de conteúdo adulto verificado para o mercado brasileiro.
Este arquivo é o cérebro do projeto. O Claude Code deve segui-lo em toda sessão.

## Stack
- PHP 8.4.22 + Laravel 13 (`laravel/framework: ^13.8`)
- MySQL 8.4 (via Docker) — banco principal
- Redis (via Docker) — cache/filas
- Front-end: **Inertia + Vue 3 + Tailwind v4** (+ Ziggy para rotas no JS).
  Blade sobrou só no layout raiz. Mudar de stack, só com aprovação do PO.
- Pagamento: Asaas / PIX (entregue na fundação)
- Realtime: Laravel Reverb (chat). O servidor Reverb **ainda não roda** —
  dev/staging usam o driver `log`. Ver `config/broadcasting.php`.
- Streaming de vídeo (LiveKit): **planejado, nada implementado.** Não há
  dependência no projeto — não presuma que existe.

## Princípios de arquitetura (não negociáveis)
1. **Segurança e idade primeiro.** PII sensível, KYC, 18+ dos dois lados, prevenção de conteúdo ilegal. É fundação, não feature.
2. **Saldo de tokens é derivado de um ledger append-only.** NUNCA fazer `UPDATE ... saldo = saldo + x`. Todo movimento é uma linha nova em `token_ledger`; o saldo é a soma. (Erro recorrente no projeto anterior — não repetir.)
3. **Idempotência em pagamento.** Crédito de tokens só via webhook idempotente por id de evento. Reprocessar nunca duplica saldo.
4. **PII isolada e criptografada.** CPF, documentos e dados de verificação ficam em tabela separada, criptografados em repouso, em storage privado. Nunca em log, nunca em URL.
5. **Nada de segredo no Git.** Tudo em `.env` (fora do versionamento). 
6. **Dados reais só em produção.** Dev/staging usam dados sintéticos.

## Convenções
- Migrations versionadas para TODA mudança de schema. Nunca alterar o banco à mão.
- Validação sempre via Form Requests (nunca confiar no input cru).
- Queries via Eloquent/Query Builder com bind. Nunca concatenar string em SQL.
- **Duas portas de auth, não confundir:** a API (`/api/v1/*`) usa Sanctum; o
  frontend Vue fala com as rotas **web** (sessão + CSRF). Consequência prática:
  fora de `api/*` uma exceção não vira JSON automaticamente — erro que o front
  precisa consumir exige `response()->json()` explícito.
  **Isso vale para VALIDAÇÃO também:** `shouldRenderJsonWhen` só liga o JSON em
  `api/*`, então uma `ValidationException` numa rota web vira
  redirect-com-erros-de-sessão **mesmo com `Accept: application/json`** — e o
  `fetch` do front recebe HTML. Endpoint web novo que o JavaScript consumir usa o
  trait `App\Http\Controllers\Web\Concerns\FailsValidationAsJson` (achado do
  Sprint 9B).
- **`SubstituteBindings` roda ANTES do middleware de rota.** Teste de gate com id
  inexistente leva 404 do binding e **passa sem exercitar o gate** — use id
  existente ao testar `role`, `2fa` ou `documents.accepted`.
- Dinheiro/tokens como inteiros (centavos / tokens), nunca float.
- Commits pequenos, em inglês, no imperativo ("add token ledger migration").
- 1 PR por entrega. Testes verdes antes de marcar como pronto.

## Fluxo de trabalho
- O Product Owner (Robson) abre issues no GitHub para bugs e mudanças.
- Cada sprint termina com: suíte de testes verde + passo de debug + revisão de segurança.
- Antes de implementar algo sensível (cadastro, KYC, pagamento, payout), rodar o subagente de segurança.

## Regra de Ouro — Git Flow

**Nenhum commit direto na `main`.** O Limen lida com pagamentos e dados sensíveis;
um erro na main derruba o site em produção.

### Fluxo obrigatório para toda feature/fix do Sprint 7 em diante:

1. Criar branch a partir da main:
   `git checkout -b feat/sprint7-<descricao-curta>`

2. Desenvolver e commitar na branch

3. Abrir PR no GitHub apontando para main

4. Aguardar aprovação do Robson antes de mergear

5. Após aprovação: merge via GitHub (squash ou merge commit — nunca force push na main)

### Nomenclatura de branches:
- `feat/sprint7-<descricao>` — nova feature
- `fix/sprint7-<descricao>` — correção de bug
- `docs/<descricao>` — documentação apenas

### Exceções permitidas (único caso):
- Commits de documentação pura (ex: atualização de MASTER_HANDOFF_FINAL.md ou CLAUDE.md)
  podem ir direto na main, desde que não toquem em código PHP, Vue ou configuração.

## Modelo de tokens (resumo — implementado na fundação)
- Cliente compra pacotes de tokens via PIX.
- Cliente gasta tokens (gorjeta, sessão privada).
- No gasto, a plataforma retém um split por nível do performer; o restante credita o performer.
- Tudo isso é registrado no `token_ledger` (append-only).

## Estado atual

> **Estado atual** (`main`, `57aab21`, Sprint 9C fechado): **1245 testes verdes**,
> 6359 asserts. **Base original** (PR #69, `229d852`): 556 testes, 2614.
> O detalhe completo vive em **`docs/MASTER_HANDOFF_FINAL.md`** — esse é o doc a
> ler antes de pegar tarefa (o `MASTER_HANDOFF_SPRINT6.md` é histórico). Este
> resumo só situa.

**Sprints 6, 7, 8, 9A e 9C fechados** (tags `v1.0-sprint6` a `v1.0-sprint9a`, mais
**`v1.0-sprint9`** no fecho do 9C). **O Sprint 9B não tem tag própria** e não está
fechado.

> **`v1.0-sprint9` é marco de SPRINT, não carimbo de go-live.** Ela fecha o arco
> Sprint 9 inteiro (9A + 9B + 9C) e o código do 9B viaja dentro dela — mas a Foto
> Efêmera **continua desligada**, com os 4 🔴 abaixo abertos. Também não é "a
> versão sem sufixo" da `v1.0-sprint9a`: as duas coexistem, e não existem
> `v1.0-sprint9b` nem `v1.0-sprint9c`.

> **A Foto Efêmera do Membro está implementada e NÃO liberada.** Existe ponta a
> ponta (PRs #101–#104) com a suíte verde, e **não pode ser ligada para usuário
> real**: 4 bloqueadores 🔴 abertos — foto não é denunciável, o GC não tem
> quarentena, o fluxo não deixa audit log, e o gate de chat é cópia da regra do
> chat. Lista em `MASTER_HANDOFF_FINAL.md`, seção "Sprint 9B — Em andamento".
> Terminar a feature não é escrever mais tela; é fechar aquela lista.

**O Sprint 9C entregou Stories da Performer** (PRs #105–#108, § abaixo) e começou
pelos 🔴, como mandava a regra: os **7 bloqueadores** da pré-análise
(`SECURITY_ISSUES.md`, § 2.1–2.7) foram endereçados, e o **pipeline de moderação
subiu antes do primeiro upload** (denúncia + quarentena + `content_hash`).

**O que continua travado, e é o topo do Sprint 10:**
1. **O refactor de `role` NÃO foi feito.** Era a outra dependência dura do 9C.
   Hoje **moderador = admin, e admin vê tudo**; a fila é `/admin/reports` sob
   `role:admin`. Agora há conteúdo publicado esperando revisão, não só backlog.
   Destrava junto o **Curador das FC Sessions** — duas features, um pré-requisito.
2. ~~**Os 4 🔴 da Foto Efêmera**~~ — **fechados** na branch
   `fix/sprint9b-photo-moderation` (denúncia, quarentena, audit e a extração de
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
- Fora da trilha numerada: **Waitlist** (double opt-in, drip, painel admin) e **Círculos** (assinaturas por tier — Fase A Explorador→Prestige, Fase B Black/FC).

> **Sprint 2 não tem registro** nos docs; a numeração pula de 1 para 3 de propósito.
> Não é lacuna de documentação a preencher — é como o histórico ficou.

## Privacidade do membro — decisões locked (não rediscutir sem o PO)
Regra central do produto, não detalhe de implementação. Fonte única:
`app/Services/FollowerVisibilityService.php`. A tela de seguidores e o envio de
Interesse **têm** que consultar o mesmo serviço: se discordarem, o par 404/201 do
envio vira oráculo para reconstruir a lista que a tela esconde.

1. **Piso de Anonimato:** a performer só vê a lista a partir de 5 seguidores.
2. **Modo Discreto** (Black/FC): o membro conta para o piso mas nunca é listado.
   `discrete_mode` **NÃO** está em `$fillable` do `User` (anti mass assignment);
   a troca passa pelo endpoint dedicado, que checa o tier.
3. **Perder o tier não desativa** o Modo Discreto — quem está discreto continua
   (não reexpomos por lapso de pagamento), sempre consegue DESLIGAR, mas não
   religar sem o tier.
4. **Piso vs. faixa:** o piso conta só contas com 7+ dias **e** e-mail verificado
   (mitigação de sybil); a faixa exibida conta **todos** os ativos. Logo, "5+" com
   a lista escondida é estado **legítimo**, não bug. Os cortes valem para
   *destravar*, não para filtrar: aberta a lista, conta nova aparece nela.
5. Contagem de seguidores é sempre exibida **em faixa**, inclusive para a própria
   performer — faixar só as telas públicas deixaria a correlação de pé.

### Piso de visitantes (`profile_visits`, painel do dashboard)
O painel "visitantes recentes" é a segunda superfície que expõe membro à
performer, e o piso de seguidores sozinho não a cobre: ele libera a tela, não
limita quem aparece nela. Por isso o painel tem **dois** cortes — `canRevealList()`
(seguidores) **e** um piso de visitantes distintos.

6. **O piso de visitantes conta só elegíveis:** conta com 7+ dias, e-mail
   verificado, `role=consumer` e `status=active`. É a mesma mitigação de sybil do
   item 4, e pelo mesmo motivo: contando todo visitante distinto, a performer com
   o piso de seguidores já destravado criava 4 contas de véspera, visitava o
   próprio perfil com cada uma e o quinto alias — o único que ela não plantou —
   saía identificado por eliminação (casando o horário de cada visita própria com
   a linha correspondente). Como o `FanAlias` é estável por par, esse vínculo ia
   junto para as gorjetas e para a lista de seguidores.
   O critério tem uma dona só: `FollowerVisibilityService::applyFloorEligibility()`.
   **Não copie o número nem a regra** para outro service.
7. **Elegibilidade destrava, não filtra** (item 4 vale aqui): aberto o painel, a
   lista sai **completa** — visitante de conta nova aparece nela normalmente. Só
   o CONTADOR do piso aplica os cortes.
8. **`limit < piso` lança `LogicException`** (`ProfileVisitService::panelFor()`),
   nunca clamp silencioso. O piso é contado sobre a janela inteira e a lista sai
   cortada em `$limit`: se `$limit` for menor, o painel abre exibindo menos
   aliases do que o piso exige. É erro de chamador — nenhum request alcança isso —
   então quebra alto em teste e staging.
9. **O guard do Ghost Mode vive no Service**, em `ProfileVisitService::record()`,
   não nos controllers. São dois pontos de entrada hoje (`CatalogController` e
   `PublicCatalogController`) e ambos só delegam; a checagem no controller viraria
   duas cópias, e a terceira rota que aparecesse nasceria vazando. `record()`
   também barra Modo Discreto (item 2) e a própria performer.
   **Não existe coluna `hidden`/`ghost` em `profile_visits`:** visita de quem tem
   o perk não é gravada. A ausência de linha É o produto — guardar a visita
   marcada como oculta deixaria o dado a um JOIN de distância, e um bug de query
   viraria o vazamento exato que o perk vende.
10. **O painel usa `FanAlias::label(performer_profile_id, visitor_id)`** — nunca o
    `visitor_id`. `visitor_id` é chave interna e não sai do service.
11. **`profile_visits` são apagadas no Hard Delete** (`DeletionService::purgeProfileVisits()`),
    com `DELETE` real dentro da transação. É o mapa de interesses do titular, sem
    valor fiscal nem trilha legal — não há o que preservar. Retenção normal são
    7 dias (`visits:purge`), enquanto o painel consome 24h. As visitas RECEBIDAS
    pelo perfil saem junto quando a **performer** encerra
    (`purgeVisitsToOwnProfile()`) — as FKs `cascadeOnDelete` de `profile_visits`
    **nunca disparam**, porque os dois lados são soft-delete. Não escreva código
    contando com o cascade.
12. **Horário só em FAIXA, nunca em relógio.** O painel devolve `visited_slot`
    (Madrugada/Manhã/Tarde/Noite, faixas de 6h; só a data fora do dia corrente).
    **`visited_at` não é exposto.** Com `d/m/Y H:i`, a performer mandava o link
    para UMA pessoa às 14:31, via o alias novo carimbado 14:32 e ligava o
    pseudônimo a um nome — e o `FanAlias` é estável por par, então o vínculo ia
    junto para gorjetas e seguidores.
    A faixa é derivada de `ProfileVisitService::DISPLAY_TIMEZONE`
    (`America/Sao_Paulo`), **não** de `config('app.timezone')`, que é `UTC`:
    derivar dali rotularia 21:00 em São Paulo como "Madrugada".
13. **Ordem embaralhada dentro da faixa** (`revealableSlots()`). Sem isso a lista
    saía por recência e a POSIÇÃO entregava o que o relógio entregava. A ordem
    ENTRE faixas fica (mais recente primeiro) — essa é a informação legítima.
14. **k-anonimato por faixa: a faixa só aparece com `SLOT_MIN_K` (3) aliases.**
    Faixa incompleta some por inteiro — **sem** placeholder, contador ou "1 visita
    oculta", que reporiam o sinal que o k tira. Pela mesma razão, a copy de lista
    vazia na tela é deliberadamente ambígua ("Nada a mostrar"), e **não** afirma
    que não houve visita: distinguir "zero" de "abaixo de k" diria à performer que
    alguém passou.
    O k é filtro DENTRO da lista, **não** substituto do piso: `visible` continua
    decidido só pelos pisos, e `visible: true` com lista vazia é estado legítimo.

> **Ressalvas conhecidas — o painel de visitantes NÃO é anônimo contra um
> adversário ativo.** Registrado para não ser redescoberto como novidade:
>
> - **Polling numa faixa já visível.** O k protege a transição escondida→visível:
>   a faixa surge já com 3 aliases, e quem chegou no intervalo é um entre 3. Mas
>   uma faixa **já visível** que ganha um visitante o entrega por diferença entre
>   dois refreshes — verificado em teste: o diff devolve exatamente 1 alias novo.
>   Fechar isso exigiria só revelar a faixa depois de encerrada (release em lote),
>   o que não está implementado.
> - **A2 — eliminação com contas envelhecidas.** Os cortes do piso (7 dias +
>   e-mail verificado) são custo de setup ÚNICO, não recorrente: pagos uma vez, o
>   painel fica destravado e cada visitante real seguinte sai por eliminação
>   contra os aliases que a performer plantou. O k e a faixa encarecem; não
>   eliminam.
>
> Consequência prática: **não descreva este painel como anônimo** em copy de
> produto, política de privacidade ou auditoria. Ele reduz correlação passiva.

## Pseudônimo do membro — `FanAlias` (fechado no Sprint 6)
Toda exposição de membro à performer passa por `app/Support/FanAlias.php`:
pseudônimo derivado por par (performer_profile_id, member_id) com HMAC sobre a
`APP_KEY`. Antes, `Membro #12345` (seguidores) e `Fã #2345` (gorjetas,
`consumer_id % 10000`) correlacionavam de forma determinística — a lista de
gorjetas não passa por piso nenhum, então bastava mandar uma gorjeta.

Duas saídas, e a distinção importa:
- `for()`/`label()` → 4 dígitos, **exibição**. Colide; nunca use como chave.
- `handle()` → 16 hex, **identificação**. É o que a tela de Seguidores manda no
  lugar do `member_id` e o que volta no POST do Interesse, resolvido contra os
  seguidores listáveis do perfil. Trocar só o rótulo teria sido maquiagem: o id
  cru continuaria legível nas props do Inertia.

Nova superfície que mostre membro à performer usa `FanAlias`, não o id.
O id segue sendo a chave interna (ledger, audit log) — isto é apresentação.
Registro completo em `docs/SECURITY_ISSUES.md`.

## Foto Efêmera do Membro — Sprint 9B (implementada, NÃO liberada)

Foto privada que o membro manda para a performer no chat: cifrada em disco
privado, TTL de 24h/72h/7d escolhido por ele, revogável. Services:
`MemberPhotoService` (regra), `MemberPhotoStore` (bytes), `ImageProcessingService`
(ingestão — **de fato compartilhado com os Stories desde o Sprint 9C**: mudança
ali afeta as duas superfícies de upload).

**Não é feature de privacidade — é des-anonimização consentida.** O `FanAlias`
deriva o pseudônimo por PAR para que nada correlacione entre perfis, e **o rosto é
uma chave de join global**: duas performers que receberam foto do mesmo membro
comparam as imagens fora da plataforma e desfazem exatamente esse isolamento. O
TTL protege o arquivo, **não a memória nem o print**. A UI diz isso no momento do
envio, não nos Termos. **Nunca descreva como "a performer não guarda sua foto"** —
mesma disciplina do painel de visitantes e do geobloqueio.

- **A expiração vale na LEITURA; o command é só GC.** Se o único mecanismo que
  corta o acesso fosse o job apagando o arquivo, job parado não custaria disco —
  custaria privacidade. `readForPerformer()` confere os dois prazos a cada
  request. É o precedente do `ChatAccess`.
- **Tempo restante só em FAIXA** (`app/Support/ExpirySlot.php`), nunca em relógio,
  e **o TTL escolhido não é exibido**. Um countdown "expira em 71h48" com TTL de
  72h devolve o `granted_at` ao minuto — é o oráculo do `visited_at` (item 12)
  voltando por uma barra de progresso, e pior, porque a performer conhece a base
  da subtração. `ExpirySlot` tem dois consumidores e **uma dona só**.
- **O gate de compartilhar é chat ativo**, e vive em
  `MemberPhotoService::shareWith()`. `grantTo()` segue sem ele de propósito — é o
  primitivo. **Chamador novo entra por `shareWith()`.**
- **"Chat ativo" é `ChatAccessService::canMemberSendTo()` — fonte única, lida
  pelo chat E pela foto.** Ela pergunta se o membro pode ENVIAR agora
  (`can_send`), nunca "existe linha em `chat_access`": assinante de Círculo tem
  chat livre e **não gera linha** — a leitura literal recusaria quem paga mais.
  Carência não passa. A recusa é sempre `no_active_chat`, para não devolver ao
  membro o estado da conta dela.
  **Regra nova sobre "o membro pode falar com esta performer" entra LÁ** e fecha
  as duas portas de uma vez. O que NÃO está lá, de propósito: a performer estar
  de pé (perfil encerrado / conta suspensa) é gate exclusivo da foto e continua
  em `shareWith()` — trazê-lo para a fonte única passaria a impedir o membro de
  responder no chat de uma performer suspensa, que é mudar o chat, não unificar.
- **Foto recebida é denunciável pela performer** (`Report::REPORTABLE_TYPES`
  conhece `member_photo`), pela porta `/reportar` que já existe. **O handle é o
  `access_id`, nunca o id da foto** — este é comum a todas as performers com quem
  o mesmo membro compartilhou, e exibi-lo daria um identificador correlacionável
  entre perfis, que é o que o `FanAlias` existe para impedir. Quem traduz é
  `Report::resolveFromHandle()`; quem autoriza é `MemberPhotoService::performerCanView()`,
  a mesma regra do serving. **Foto vencida não é mais denunciável** — a janela de
  denúncia é a de exibição, igual ao story.
- **Denúncia em aberto CONGELA o revoke do titular e o GC** (`Report::OPEN_STATUSES`,
  a mesma constante do story). Sem isso, quem envia conteúdo ilegal tem o botão
  de destruir a prova contra si a um clique. O congelamento vale para a LINHA e
  os BYTES, **não para a visibilidade**: foto congelada e vencida não é legível
  por ninguém — nem pela performer que denunciou —, senão denunciar viraria a
  forma de esticar o próprio acesso.
- **Audit no fluxo: `member_photo.shared`, `.viewed`, `.revoked` — id e nada
  mais.** Sem caminho, sem nome de arquivo e sem `performer_profile_id`. O
  `.viewed` é gravado só na PRIMEIRA abertura (a tela é uma `<img>`; sem a dedup,
  recarregar a página enterra a trilha — mesma disciplina do filtro de chat).
- **O SUJEITO do `.viewed` é o ACESSO, não a foto — e isso é o controle, não um
  detalhe.** `Audit::log()` grava o ATOR em `user_id` e o alvo em `subject_id`.
  Como o ator do `.shared` é o MEMBRO e o do `.viewed` é a PERFORMER, apontar os
  dois para a mesma foto deixaria o par (membro, performer) a um
  `JOIN ... ON subject_id` de distância — **cópia permanente do mapa que morre
  com a foto**, na única tabela que o `DeletionService` preserva intacta.
  Apontando para o acesso, a ligação depende da linha de `member_photo_access`,
  que morre em hard delete junto com a foto. **Omitir o id da performer do
  metadata não fecha isso sozinho** (foi o achado da revisão de 30/07): evento
  novo neste fluxo escolhe o sujeito pelo LADO de quem age.
- **A linha morre em HARD delete**, e só depois de confirmar que os bytes saíram
  do disco. Linha soft-deletada guardaria "o membro X mandou 43 fotos, nestes
  horários" e faria o GC decifrar `path_encrypted` de todas a cada hora só para
  pular. `deleted_at` é estado intermediário (linha ida, bytes de pé).
- **Ingestão re-encoda para matar EXIF/GPS** e, no mesmo passo, polyglot — o
  arquivo servido deixa de ser o arquivo enviado. `Content-Type` sai de re-sniff
  no **servidor**, nunca do que o upload declarou. **Nada de URL assinada:** a
  autorização é por sessão, a cada request.
- **Teto de imagem-bomba lido do HEADER, antes de qualquer decodificação**
  (`config/image.php`). 13 MP não é número de gosto: estourar `memory_limit` é
  **fatal error, não `Throwable`** — não cai no catch, o worker morre e o
  temporário EM CLARO fica órfão em `/tmp`. E não pode ser menor: 4 MP recusariam
  a foto de iPhone (12,2 MP), que é a entrada primária do produto. A conta está no
  config. **Não suba o número sem refazê-la contra o `memory_limit` real.**
- **`user_id` e `expires_at` são `$hidden`; FKs e `expires_at` do acesso ficam
  fora do `$fillable`** — mesma regra de `discrete_mode` e do 2FA.
- **O disco `member_photos` fica FORA do backup** — foto efêmera não pode
  sobreviver ao TTL dentro de um tarball. Hoje isso vale porque `docs/backup.sh`
  é **allowlist** (só `storage/app/private` e `storage/app/kyc`), não porque haja
  exclusão escrita: **quem converter aquele script para denylist reintroduz o
  problema em silêncio.**

> **Os 4 🔴 que bloqueavam o go-live foram FECHADOS** (branch
> `fix/sprint9b-photo-moderation`): (1) foto denunciável pela performer via
> `member_photo`; (2) denúncia congela GC e revoke; (3) audit em share/view/revoke;
> (4) `canMemberSendTo` como fonte única. Detalhe nos itens acima.
>
> **Fechar os 🔴 não é o mesmo que liberar** — a decisão de ligar para usuário
> real é do PO, e continua valendo tudo o que esta seção diz sobre a natureza da
> feature (des-anonimização consentida, o rosto como chave de join global).
> **Segue em aberto**, agora como 🟡 e não como bloqueador: o cap de performers
> por foto (§ 1.1), a varredura de órfãos no disco (§ 1.5), e os achados da
> revisão de segurança de 30/07 registrados em `MASTER_HANDOFF_FINAL.md` — com
> destaque para **a recusa do revoke, que entrega a denunciante ao denunciado**
> (decisão de produto pendente), o Hard Delete de conta que ainda apaga foto
> denunciada, e a ausência de prazo máximo de quarentena.

## Stories da Performer — Sprint 9C (entregue, tag `v1.0-sprint9`)

**É a primeira publicação de conteúdo do projeto** — 1:N, com paywall por nível.
Imagem só (v1), TTL **fixo** de 24h, três níveis (`public` / `subscribers` /
`exclusive`). Três services com fronteira deliberada: `PerformerStoryService`
(ciclo de vida), `PerformerStoryStore` (bytes), **`StoryVisibilityService`
(paywall)**. Registro completo em `MASTER_HANDOFF_FINAL.md`, "Sprint 9C".

- **`StoryVisibilityService` é a dona única de "quem alcança este nível", e serve
  o feed E o serving.** Se as duas superfícies discordarem, o par vira oráculo
  (feed mostra + imagem 403 anuncia o que foi publicado para quem não pode ver) ou
  buraco de paywall (o inverso). `LEVEL_CAPABILITIES` mapeia nível → capacidades;
  o predicado de linha intersecta o mapa e o filtro SQL pergunta ao mapa. **Nível
  não mapeado falha FECHADO dos dois lados.** Regra nova de visibilidade entra
  lá — nunca no controller, nunca no Vue.
- **Não existe lista de "quem viu meu story" — em superfície nenhuma.** A única
  saída é a **faixa de membros únicos**, reusando `PerformerProfile::followersLabelFor`
  (a mesma dona da faixa de seguidores). Story **exclusivo retorna `null`, nunca
  zero**: zero já contaria quantos Black existem. `DISTINCT member_id` vem **antes**
  da faixa — faixar aberturas devolveria comportamento do membro, e quem reabre 5
  vezes empurraria a faixa sozinho.
- **Nenhuma URL assinada para os bytes.** Autorização por sessão, com follow e
  tier resolvidos **a cada request** — assinatura não amarra espectador, então a
  URL do membro Black seria bearer token. Há teste cobrando a stack de middleware
  para impedir a regressão. Disco `performer_stories`, privado, `serve => false`.
- **Sem `Crypt`, ao contrário da foto efêmera** (§ 2.5): story é 1:N e o `Crypt`
  carregaria o arquivo inteiro por espectador simultâneo, sem `Range`/seek. A
  autorização por request num disco não servido faz o papel. **Não "corrija" isso
  cifrando** — foi decidido contra o precedente do 9B de propósito.
- **A expiração vale na LEITURA; `stories:purge` é só GC** (precedente do
  `ChatAccess` e da foto). TTL fixo em 24h, então o relógio **não** é oráculo de
  TTL e não há prazo a faixar — `ExpirySlot` não se aplica aqui.
- **Denúncia aberta (`pending`/`reviewed`) congela GC E delete manual.** O
  auto-delete de 24h seria destruição de prova embutida no produto; GC educado ao
  lado de um botão de apagar manual não protege nada. `content_hash` (SHA-256 dos
  bytes **já processados**, calculado no store) é a prova que sobrevive ao
  arquivo — `$hidden` e **fora do `$fillable`**: prova escolhida pelo acusado não
  é prova.
- **Story entra pela porta `/reportar` existente**, não por rota própria: o dedup,
  o lock anti-duplo-submit, a recusa de autodenúncia e a resposta uniforme já
  vivem no `ReportController`. `Report::visibleTo` delega a
  `StoryVisibilityService::canView`, senão o POST vira **oráculo de existência**.
- **Blur de CSS não é paywall.** Tile bloqueado não recebe `image_url` nenhum —
  miniatura "borrada" está intacta no DevTools. A tela desenha placeholder.
- **404 para vencido ou performer fora do ar, 403 para tier insuficiente**, e a
  escolha vive na regra (`denialFor()`), não no controller. O estado da conta dela
  não é assunto do membro; o 403 é o upsell que o Modelo C monetiza.
- **Audit: `story.published` (id + nível) e `story.deleted` (id).** Sem caminho,
  sem hash, sem bytes — mesma disciplina do filtro de chat. **`story.reported`
  NÃO existe, de propósito:** poria o IP do denunciante em claro ao lado da
  acusação (ver `ReportController`). Decisão registrada para o PO, não silenciada.
- **O disco `performer_stories` fica FORA do backup**, como `member_photos`, e
  pela mesma razão: TTL de 24h não pode sobreviver num tarball de 14 dias. Vale
  hoje porque `docs/backup.sh` é **allowlist** — convertê-lo para denylist
  reintroduz o problema **nas duas features de uma vez**.

> **Ghost Mode: o ponto dourado nunca apaga para membro Black/FC — e isso é o
> perk funcionando.** O guard do § 2.7 vale para `story_views` (a "terceira rota"
> do item 9): a visualização **não gera linha**, não há coluna `hidden`. Logo ele
> não entra em contador nenhum e o feed marca todo story como não visto, para
> sempre. **Quem for "consertar" isso está desligando o Ghost Mode.**

> **Ainda travado:** a fila humana é `/admin/reports` sob `role:admin` — o
> **refactor de `role` não aconteceu**, moderador segue sendo admin, e admin vê
> tudo. Vídeo é Sprint 10 e esbarra no bloqueio das FC Sessions.

## 2FA da performer — TOTP (Sprint 6)
A conta da performer guarda o KYC (documento + selfie) e é a identidade
verificada sob a qual o conteúdo é publicado: um take-over vaza PII sensível E
deixa terceiro publicar como ela. Senha não é fator suficiente para isso.

Fortify **não** está instalado (e não é dependência do core do Laravel). O TOTP
é `pragmarx/google2fa` direto; o QR é desenhado **localmente** em SVG inline
(`bacon/bacon-qr-code`) — nunca por serviço externo de QR, porque a `otpauth://`
carrega o segredo em claro. Regra em `app/Services/TwoFactorService.php`.

- **`two_factor_confirmed_at` é o que liga o 2FA**, não a presença do secret:
  entre `enable()` e `confirm()` a performer ainda não provou o autenticador, e
  gatear nesse intervalo trancaria a conta com um QR nunca escaneado.
- Secret e recovery codes: cast `encrypted` (APP_KEY), `$hidden`, **fora do
  `$fillable`** (mesma regra de `discrete_mode`). Rotacionar APP_KEY derruba os
  dois — a performer cai no re-cadastro do autenticador.
- **Recovery code é de uso único, sob `lockForUpdate`.** Dois POSTs simultâneos
  com o mesmo código autenticariam duas sessões sem o lock.
- **TOTP também é de uso único** (`two_factor_last_used_ts`, `verifyKeyNewer`).
  Sem isso o código valia os ~90s da janela: o capturado no desafio servia em
  seguida para `/2fa/disable` e desligava o próprio fator.
- **`confirm()` NÃO aceita recovery code** — o passo existe para provar que o
  app autenticador funciona. `disable()` e a reemissão de códigos aceitam, e
  **exigem** um fator: quem só tem a sessão não remove o segundo fator.

### O gate vale nas DUAS portas de auth — e a prova é diferente em cada uma
Middleware `2fa` (`TwoFactorChallenge`). Ignora quem não é performer com 2FA
confirmado, então pode ser aplicado em grupo compartilhado, como o
`documents.accepted`.

- **Web (sessão):** marca na sessão, que guarda o **id do usuário**, não `true`
  — assim não é herdável por uma sessão que trocou de dono. Aplicado no grupo
  `auth` INTEIRO, não só em `performer.*`: a sessão da performer alcança chat e
  catálogo, e gatear só o dashboard deixaria a conta sequestrada conversando
  com membros.
- **API (Sanctum):** não há sessão onde marcar, então o fator vem **antes do
  token**. `POST /api/v1/auth/login` de quem tem 2FA devolve `two_factor_required`
  + um token com a habilidade `2fa:challenge` e mais nada (10 min);
  `POST /api/v1/auth/2fa/challenge` troca por código e devolve o token real. O
  middleware testa a habilidade com `in_array` **e não `$token->can()`** — o
  `can()` do Sanctum responde true para qualquer coisa num token `*`, o que
  barrava justamente quem tinha passado pelo desafio.
- `/broadcasting/auth` entra pelo `withBroadcasting` com `['web','auth','2fa']`.
  No padrão (`channels:` no `withRouting`) ele sai só com `web` e a sessão
  mandada ao desafio ainda assinava `conversation.{id}`.
- **Fora do gate ficam só o desafio e o logout** (senão o redirect aponta para
  rota que ele mesmo bloqueia, e quem perdeu o autenticador não sai da conta).

**Rota autenticada nova entra no gate** — nas duas portas. Foi a lição do
`documents.accepted`: gate que fecha uma porta só não é gate.

> Ressalva conhecida: o login da web COMPLETA antes do fator (`Auth::login` e
> depois o middleware barra). É mais fraco que desafiar antes de estabelecer a
> sessão; o que fecha o buraco na prática é o gate cobrir o grupo `auth`
> inteiro. Trocar por um login em dois passos é follow-up.
>
> Não implementado: alerta em N falhas de desafio (hoje só grava
> `performer.2fa_challenge_failed` no audit e ninguém consome).

## Geobloqueio (FOSTA-SESTA) — montado, NÃO ativo
Middleware `GeoBlock` nos grupos `web` e `api`, 451. **Com `GEO_DRIVER=none` (o
padrão e o valor de hoje) ele não bloqueia ninguém** — falta a fonte de
geolocalização. Fail-OPEN de propósito: fail-closed sem fonte derruba o site.
Detalhe e passos de ativação em `docs/GEOBLOCKING.md`.

- `/up` fica fora — monitor de uptime sonda dos EUA e viraria alarme falso.
- O driver `cloudflare` **só funciona com o origin fechado aos ranges do CF**:
  `CF-IPCountry` é header, e um `curl -H` direto no IP do servidor passa.
- Audit `access.geo_blocked` **deduplicado por IP/hora** — sem isso um bot em
  laço num endpoint não autenticado enterra a trilha.
- **Não é garantia jurídica: VPN contorna.** Não escreva "americanos não
  acessam" em política, contrato ou auditoria — mesma disciplina de linguagem
  do painel de visitantes.

## Filtro de conteúdo do chat — duas categorias
Listas em `config/chat_filters.php`; casamento em `app/Support/ChatContentFilter.php`.

- **TIPO 1 `legal`** — encontro mediante pagamento e transação fora do ledger.
  422 com mensagem que **cita os Termos de Uso**.
- **TIPO 2 `conduct`** — ameaça/sextorsão e insulto **direcionado**. 422 com
  mensagem de política de conduta + `flagged_for_review` no audit.

### O que o filtro deliberadamente NÃO barra (decisão do PO — não "consertar")
1. **Troca de contato é PERMITIDA.** WhatsApp, telefone, Instagram, endereço:
   legítimo num produto de conteúdo adulto/dating. A versão anterior barrava
   isso e derrubava "comprei um fone de ouvido" e "vi seu instagram".
2. **Palavrão em contexto sexual consentido é PERMITIDO.** "que puta gostosa"
   é o vocabulário do produto. Só entra insulto DIRECIONADO (pronome +
   xingamento), e um qualificador consensual na mensagem — `safada`, `gostosa`,
   `linda` — **desarma** o casamento: "sua puta safada" passa, "sua puta
   nojenta" não. Heurística: erra no elogio seco ("sua puta"). O caminho para
   o caso ambíguo é a denúncia (`Report`), que tem contexto e um humano.
3. **Encontro SEM valor monetário é PERMITIDO.** "vamos num motel" passa;
   "motel, 300 reais" não. Termo ambíguo (`programa`, `motel`, `presencial`)
   só bloqueia junto de `money_signals` na MESMA mensagem — é o único jeito de
   usar `programa` sem barrar "qual seu programa favorito".

### Invariantes
- **O filtro roda ANTES da máscara de opt-out** em
  `ChatService::performerMessageFromInterest`. Depois dela, o suprimido daria
  202 e o normal 422 — o par viraria oráculo do opt-out. Guardado por teste.
- Normalização fecha ZWSP e **fullwidth** (achados da revisão de segurança):
  `\p{Cf}` sai ANTES do `Str::ascii` (que virava o ZWSP em espaço real) e
  NFKC colapsa fullwidth (que o `Str::ascii` DESCARTAVA, zerando a mensagem).
- `audit_logs` leva **categoria + `rule_hash` (HMAC)**, nunca a regra em claro
  (a lista está no repo: `sha256` seria revertido por tabela) e **nunca o
  corpo** — seria 2ª cópia do conteúdo do chat, fora do soft-delete do LGPD.
  Deduplicado por (usuário, regra), senão enumerar a lista enterra a trilha.
- **A moderação age por REPETIÇÃO**, não por caso isolado: sem o corpo, o
  admin vê "usuário X disparou conduta 9x". Fila humana de verdade (com
  contexto) é follow-up — `reports` exige `reporter_id` e um alvo morfável, e
  mensagem bloqueada não é persistida.

> **Não é anti-evasão, e o "segredo" nunca foi real.** A lista está no repo e o
> remetente distingue as categorias pela resposta. A mensagem de erro é
> específica de propósito: dizer o que foi violado vale mais do que uma
> vaguidade que o evasor contorna em duas tentativas e que só prejudica quem
> agiu de boa-fé. Ausência de bloqueio **não** é prova de que nada foi combinado.

## Aceite de documentos da performer — `documents.accepted`
Política de Conteúdo Proibido + Contrato de Performance. Versão vigente em
`config/documents.php`; **bumpar a versão força re-aceite de todas** — não bumpe
por typo. A versão nunca vem do request: o servidor resolve pelo config, senão
bastaria postar a versão velha para satisfazer o gate sem ver o texto novo.

`document_acceptances` é append-only (o model recusa `update`): versão nova é
LINHA nova, é o histórico que dá o lastro jurídico. IP e user-agent entram como
HMAC (`app/Support/ClientFingerprint.php`), nunca crus — mas o `audit_logs` do
mesmo evento ainda grava o IP em claro; a ressalva está em `docs/SECURITY_ISSUES.md`.

**Rota nova de performer entra no grupo `documents.accepted`.** Vale para as
duas portas de auth: web (redirect) e API Sanctum (403 JSON). O middleware ignora
quem não é performer, então rota compartilhada (chat) pode recebê-lo direto sem
afetar o membro. Fora do gate ficam só a própria tela de aceite (senão o redirect
dá loop) e as páginas públicas dos textos.

O texto jurídico ainda é placeholder (aguardando Opice Blum) — **não descrever
para auditoria como "contrato aceito"** até o texto definitivo entrar.

## Limitações do ambiente de dev
- **Sem `gh` CLI e sem token:** não é possível abrir PR ou issue por código. O
  push devolve a URL de `pull/new` para o PO abrir manualmente.
- **Sem `pdo_sqlite`**, e o `phpunit.xml` aponta para sqlite. **Não edite o
  `phpunit.xml`** — prefixe os `DB_*` no comando (é o que o CI faz):
  ```bash
  DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
  DB_DATABASE=limen_test DB_USERNAME=limen DB_PASSWORD=limen_dev_pw \
  php artisan test
  ```
  Migration quebrada faz o Pest re-rodar `migrate:fresh` a cada teste e **parece
  hang**, não erro. Rode `php artisan migrate:fresh` sozinho para ver a exceção.
