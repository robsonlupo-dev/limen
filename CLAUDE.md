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
- Dinheiro/tokens como inteiros (centavos / tokens), nunca float.
- Commits pequenos, em inglês, no imperativo ("add token ledger migration").
- 1 PR por entrega. Testes verdes antes de marcar como pronto.

## Fluxo de trabalho
- O Product Owner (Robson) abre issues no GitHub para bugs e mudanças.
- Cada sprint termina com: suíte de testes verde + passo de debug + revisão de segurança.
- Antes de implementar algo sensível (cadastro, KYC, pagamento, payout), rodar o subagente de segurança.

## Modelo de tokens (resumo — implementado na fundação)
- Cliente compra pacotes de tokens via PIX.
- Cliente gasta tokens (gorjeta, sessão privada).
- No gasto, a plataforma retém um split por nível do performer; o restante credita o performer.
- Tudo isso é registrado no `token_ledger` (append-only).

## Estado atual

> **Base:** `main` em `229d852` (merge do PR #69) · **556 testes verdes**, 2614 asserts.
> O detalhe completo do que foi entregue vive em `docs/MASTER_HANDOFF_SPRINT6.md` —
> esse é o doc a ler antes de pegar tarefa. Este resumo só situa.

**Sprint 5 fechado.** Próximo é o Sprint 6 (Panic Button, Ghost Mode, Read
Receipts, Photo Blur, 2FA de performers, Hard Delete LGPD, fix da correlação
`Membro #` ↔ `Fã #`).

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
