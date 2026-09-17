# Limen — Guia do Projeto (leia antes de qualquer tarefa)

Plataforma premium de conteúdo adulto verificado para o mercado brasileiro.
Este arquivo é o cérebro do projeto. O Claude Code deve segui-lo em toda sessão.

## Stack (resumo — detalhe em `docs/ARQUITETURA.md`)

PHP 8.4.24 + Laravel 13 · MySQL 8.4 (Docker) · Redis (cache/filas) · front **Inertia
+ Vue 3 + Tailwind v4** (+ Ziggy) · pagamento **Asaas/PIX** · realtime **Laravel
Reverb** (chat; servidor Reverb ainda não roda — dev/staging usam driver `log`) ·
vídeo em tempo real **LiveKit** (live/chamada/group, **sobe DESLIGADO** em produção via
`FEATURE_LIVE_ENABLED`/`FEATURE_CALL_ENABLED`) · `ffmpeg` no servidor (sanitização de
upload de vídeo/áudio). **A descrição detalhada da pilha, dos serviços e de cada tabela
vive em `docs/ARQUITETURA.md` (seção "Stack").** Mudar de stack só com aprovação do PO.

## Princípios de arquitetura (não negociáveis)
1. **Segurança e idade primeiro.** PII sensível, KYC, 18+ dos dois lados, prevenção de conteúdo ilegal. É fundação, não feature.
2. **Saldo de tokens é derivado de um ledger append-only.** NUNCA fazer `UPDATE ... saldo = saldo + x`. Todo movimento é uma linha nova em `token_ledger`; o saldo é a soma. (Erro recorrente no projeto anterior — não repetir.)
   - **Ressalva de leitura (não é violação):** existe `token_wallets.balance` como **cache materializado**. `TokenService::credit/debit` escrevem esse campo por `UPDATE` de **valor absoluto** (`balance = <novo saldo calculado em PHP>`) **sob `lockForUpdate`**, na mesma transação da linha do ledger — nunca o padrão **aditivo** `balance = balance + x` que o princípio proíbe. O invariante `balance == SUM(token_ledger.amount)` vale por construção (crédito/débito/`releaseAfterDebit` sempre escrevem valor e linha juntos). Portanto um `UPDATE ... token_wallets ... balance` no log de queries é esperado; o que seria bug é o SQL aditivo ou o saldo divergindo da soma. (Achado do Pre-Flight Sweep, ago/2026.)
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
- **Design tokens `limen-*` (redesign maison, Sprint 16).** A paleta do front vive
  no `@theme` de `resources/css/app.css`, e **todo componente novo de catálogo/
  perfil usa esses tokens**, não hex solto nem cor Tailwind crua:
  `limen-bg` (#181410, fundo), `limen-surface`/`limen-surface-2` (cartões),
  `limen-ink`/`limen-ink-soft`/`limen-ink-mute` (texto em três pesos),
  `limen-gold` (#d6b872, destaque/curadoria), `limen-line` (bordas). **`limen-live`
  (#e24b4a) é EXCLUSIVO do estado "ao vivo"** — badge/borda de live e nada mais;
  usá-lo fora disso quebra o significado da cor. Tokens antigos (`cream`, `gold` do
  tema legado) continuam onde já estavam, mas tela nova entra em `limen-*`.
- Commits pequenos, em inglês, no imperativo ("add token ledger migration").
- 1 PR por entrega. Testes verdes antes de marcar como pronto.
- **MOBILE PRIMEIRO — regra permanente (decisão do PO, ago/2026, a partir de
  `feat/performer-nav-restructure`).** Toda tela nova (e todo redesenho) é pensada
  primeiro para **360–390px** de largura e só depois adaptada ao desktop. Se algo
  não couber no celular, o problema é o DESIGN, não a tela. Navegação primária no
  celular usa padrão de app (barra fixa no rodapé), **nunca menu hambúrguer**. Alvos
  de toque ≥44px, foco visível, teclado, e `prefers-reduced-motion` honrado em
  qualquer transição.
- **Ao adicionar seção nova ao handoff (`docs/MASTER_HANDOFF_FINAL.md`), use um
  título descritivo único (nome da feature ou data), NUNCA número sequencial
  (`A.0.N`).** A numeração sequencial colide quando duas branches criadas em paralelo
  adicionam a "próxima" seção com o mesmo número — foi o que forçou o renumber
  "A.0.4 → A.0.9". Título descritivo não colide. (Ver a nota de convenção no topo do
  Apêndice A do handoff.)
- **ÍCONE DE INTERFACE É SVG, NUNCA EMOJI (regra do PO, fix/uat-round-polish).**
  Emoji renderiza diferente (ou vira um quadrado) entre sistemas/navegadores — foi o
  que aconteceu com a câmera/calendário dos botões de chamada e com o símbolo do
  token. Todo ícone de UI (botão, rótulo, estado) é `<svg>` inline (stroke,
  `currentColor`, `aria-hidden`), como o resto do projeto. Emoji só é aceitável como
  CONTEÚDO textual (ex.: numa mensagem de chat), nunca como ícone. **Ainda usam emoji
  como ícone (dívida a migrar, achado da varredura):** `LiveViewer`/`LiveOverlay` 🪙
  (token), `StoryStrip`/`PhotoCarousel`/`Subscription` 🔒 (cadeado), `NowStrip` 💌,
  `Catalog/FilterPanel` 💾, `Catalog/Index` 🌐, `Content/Index` 🎬🖼, `Links` 🔞,
  `Dashboard` ⚡, `Onboarding` 📄🕐✅ — trocar por SVG num PR de limpeza.
- **UMA formatação de token no front: `formatTokens()` de `@/lib/tokens`
  (fix/uat-round-polish).** Separador SEMPRE pt-BR (vírgula decimal, ponto de milhar).
  Casas por contexto, mas o separador não muda: **saldo/ganho da performer** mostra
  casas quando fracionário (o crédito da performer fraciona — 80% de 2 = 1,6), inteiro
  quando inteiro, até 4 casas com zeros à direita cortados (300,4 — não 300,4000 nem
  300.4000); **preço/quantidade inteira** sai inteiro. Nenhuma exibição de token usa
  `toFixed`/`replace`/`toLocaleString` ad-hoc. (Valor em REAIS continua `toLocaleString`
  BRL — outra unidade.) O rótulo de cada lançamento do ledger vem do servidor
  (`App\Support\LedgerEntryLabel`, cobre o enum inteiro) — a tela usa `entry.label`,
  nunca um mapa local (foi um mapa local incompleto que vazou `spend_call` cru).

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

## Economia e monetização — ver `docs/ECONOMIA.md`

**As regras de NEGÓCIO da economia saíram deste guia** (`docs/split-business-rules`).
A fonte canônica de preços, pacotes, splits, tiers, descontos, franquias, teto de
acúmulo, payout, retenção de conversa e arredondamento é **`docs/ECONOMIA.md`** — em
linguagem de negócio, para leitor não-técnico. As decisões de produto de agosto/2026
(portão de conversa removido, chat 80/20, decimal exato, preço simétrico, etc.) estão
em **`docs/DECISOES_2026-08.md`**; as questões que dependem do jurídico em
**`docs/PENDENCIAS_JURIDICAS.md`**.

O que ANTES vivia aqui como "M.1–M.14" (o modelo de monetização fechado + a emenda
decimal + a regra única de arredondamento R1–R4) foi consolidado nesses documentos. Os
rótulos `M.x` seguem citados nas seções de feature em `docs/ARQUITETURA.md` como
**referência histórica**;
a redação canônica agora é a do `ECONOMIA.md`. **Precedência inalterada:** o modelo
fechado vence `docs/SUBSCRIPTION_TIERS.md` e `docs/CIRCLES_SYSTEM_V4.md`; os slugs de
tier são os de `Circle::TIER_ORDER`
(`explorador / insider / prestige / black / founders_circle`).

### Invariantes de ENGENHARIA da economia (ficam aqui — é COMO implementar, não o preço)

Os NÚMEROS e o PORQUÊ de negócio estão em `docs/ECONOMIA.md`. O que fica neste guia é o
contrato técnico que um dev quebraria sem saber:

- **Ledger append-only (princípio nº 2, acima):** todo movimento é uma LINHA NOVA; o
  saldo é a soma. NUNCA `UPDATE ... saldo = saldo + x`. **Cada novo tipo de gasto/crédito
  é uma migration no enum de `entry_type`** — nunca um `UPDATE` de saldo.
- **Aritmética de carteira é DECIMAL EXATA (bcmath, escala 4), dona única
  `App\Support\TokenMath`.** `amount`/`balance_after`/`token_wallets.balance` são
  `DECIMAL(20,4)`. NUNCA operador nativo (`+ - < (int) (float)`) sobre valor/saldo.
  "Nunca float" continua valendo — DECIMAL+bcmath é decimal EXATO, não ponto flutuante.
  O split percentual tem ponto ÚNICO em `TokenCreditPolicy::applyRate` (exato, sem
  `intdiv`/round-half-up).
- **O SALDO DO MEMBRO é INTEIRO por construção** (compra/gasta inteiro); só o CRÉDITO da
  performer fraciona. Override registrado da convenção "tokens são inteiros": ela vale
  para o saldo do membro e para PREÇOS, não para o crédito da performer.
- **Arredondamento: UMA única vez, no PAYOUT, sempre FLOOR**
  (`PayoutService::calculatePayoutCentavos` = `floor(tokens × 60)`); a **sobra do floor
  FICA** no saldo da performer (`tokens_consumed = centavos ÷ 60`). O LEDGER nunca
  arredonda. `credited + retained == gross` por construção do complemento. (Regra R1–R4
  em `docs/ECONOMIA.md` §13.)
- **Contrato de leitura uniforme (`TokenMath::readable`):** INT quando inteiro
  ("500.0000" → 500), STRING decimal de 4 casas quando fracionário ("1.6000").
- **Ganho sacável vs. entrada que respeita o teto:** a chave é o `entry_type`, NUNCA o
  `role`. Ganho (todo `*_credit`) nunca respeita o teto e entra no allowlist de payout;
  compra/bônus/`subscription_grant` respeitam o teto e não são sacáveis.
- **`entry_type` do agendamento de chamada:** `spend_call_reservation` (débito do
  depósito), `call_reservation_refund` (devolução 100% ao membro — nunca respeita teto,
  fora do payout: é devolução, não ganho), `call_noshow_credit` (no-show do MEMBRO →
  100% à performer, `applied_rate=100`, ganho sacável). O minuto 1 da chamada agendada
  reusa `call_credit` (70/30) — sem tipo novo. Regras de negócio em `docs/ECONOMIA.md` §8.
## Estado atual

- **Branch principal:** `main` · **último commit:** `9078a56` (Merge PR #213 —
  `fix/voice-access-and-chat-avatar`: avatar do membro ao lado do FanAlias no chat +
  acesso à intro de voz).
- **Suíte:** ~2184 testes / ~17144 asserts (com `feat/performer-profile-redesign`).
  **Roda verde local com 1 falha conhecida** — o `GeoBlockTest` da view 451, que só falha
  **neste clone de dev** (view não compilada; verde no CI). Ver "Ambiente de dev". **135
  migrations.**
- **Como rodar a suíte, deploy e ambiente:** ver "Ambiente de dev" e as "Notas
  operacionais" mais abaixo.

> **Histórico completo (o que foi entregue em cada Sprint 1→16, follow-ups, PRs
> mergeados, estado de cada janela) → `docs/HISTORICO_SPRINTS.md`.** Saiu daqui para o
> guia caber no contexto; nada se perdeu.

## Mapa de features → `docs/ARQUITETURA.md`

A descrição detalhada de cada feature/serviço (regras, invariantes de privacidade,
contratos de cada superfície) **saiu do CLAUDE.md e vive em `docs/ARQUITETURA.md`**.
Ao mexer numa feature, leia a seção dela lá. Cobertas:

- **Privacidade do membro** (decisões locked, piso de anonimato, piso de visitantes,
  k-anonimato), **FanAlias** (pseudônimo por par), **Apelido do membro**
  (`feat/member-nickname`: rótulo público opcional que a performer vê no lugar do
  "Fã #NNNN" em 6 telas — camada de EXIBIÇÃO; o FanAlias segue como identificador no
  ledger/extrato/auditoria. Validação mais rígida que a do chat: barra telefone (5+
  dígitos consecutivos), contato, rede social com anti-leet, palavra reservada e nome de
  performer. Erro genérico anti-oráculo; troca 1×/7 dias).
- **Favoritos, Notas da performer, Boost pago, Convite via Stories, Buscas salvas,
  Filtro de cidade** — superfícies do catálogo.
- **Catálogo de membros (superfície invertida), Chat economy v2, Vitrine de conteúdo,
  Extrato de ganhos, Foto de perfil do membro, Visitas bidirecionais, Sinais de
  atividade, Teaser de mensagem, Microinterações, Intro de voz** — melhorias recentes.
- **Redesenho do perfil público da performer** (`feat/performer-profile-redesign`): capa
  como faixa (não parede), avatar sobreposto, VOZ em faixa de destaque (assinatura), abas
  Fotos/Sobre/Conteúdo, ações sempre alcançáveis (barra fixa no mobile / coluna sticky no
  desktop), valores acima do conteúdo, selo de verificada clicável (só critérios reais).
  Componentes em `Components/Profile/*`; detalhe no `MASTER_HANDOFF_FINAL.md`.
- **Landing cinematográfica** (a porta pública `/`), **Anti-CSAM, Som de notificação**.
- **LiveKit:** Agendamento de chamada, Console de live, Chamada privada a partir da live,
  controles de transmissão, navegação do catálogo ao vivo.
- **PanicButton, Navegação (painel performer e membro)** — mobile-first.
- **Foto Efêmera, Stories da Performer** — conteúdo efêmero e moderação.
- **Segurança/compliance:** 2FA (TOTP), Login OTP, Captcha (driver hCaptcha/Turnstile),
  Geobloqueio (FOSTA-SESTA), Filtro de conteúdo do chat, Aceite de documentos.

## Ambiente de dev (atualizado 31/07/2026) e suas limitações
- **O dev roda NO SERVIDOR via SSH** (`deploy@62.238.46.212`, `~/limen-dev`). A
  **VM local (`~/teste`) foi descontinuada.** `~/limen-dev` (dev) e
  `/var/www/limen` (staging/prod) são **clones SEPARADOS** — não presuma estado
  comum entre eles.
- **`git credential store` configurado no servidor:** push/pull não pedem senha.
  Mas **segue sem `gh` CLI** — abrir PR ou issue por código continua impossível; o
  push devolve a URL de `pull/new` para o PO abrir manualmente.
- **Sem `pdo_sqlite`**, e o `phpunit.xml` aponta para sqlite. **Não edite o
  `phpunit.xml`** — prefixe os `DB_*` no comando (é o que o CI faz). A senha do
  banco de teste está no **`.env` do servidor** (usuário `limen`, banco
  `limen_test`) — **fora do Git** (princípio 5), **não** é o `limen_dev_pw` que
  este exemplo trazia antes (placeholder, não funciona):
  ```bash
  DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
  DB_DATABASE=limen_test DB_USERNAME=limen DB_PASSWORD='<ver .env>' \
  HCAPTCHA_ENABLED=false php artisan test
  ```
- **`HCAPTCHA_ENABLED=false` ao rodar testes localmente:** o `.env` do servidor
  tem o captcha LIGADO (é dev real), e com ele ligado os Form Requests de auth
  exigem o campo `captcha_token` — a suíte inteira de auth quebra. O CI roda com
  ele desligado; reproduza isso no comando (acima), **não** editando o config.
  **Desde o driver de captcha (§ "Captcha"), o interruptor é `CAPTCHA_PROVIDER`;**
  `HCAPTCHA_ENABLED=false` no comando continua funcionando pela ponte de
  compatibilidade (sem `CAPTCHA_PROVIDER` definido, `HCAPTCHA_ENABLED=false` cai
  em `none`). Equivalente e mais explícito: `CAPTCHA_PROVIDER=none`.
- Migration quebrada faz o Pest re-rodar `migrate:fresh` a cada teste e **parece
  hang**, não erro. Rode `php artisan migrate:fresh` sozinho para ver a exceção.
- **Ressalva de suíte local:** `GeoBlockTest` "bloqueia com 451" falha **só neste
  clone de dev** — a view custom de erro 451 não está compilada aqui, então cai na
  página de erro padrão do Symfony. É verde no CI. Não é regressão; não persiga.

## Nota operacional — 06/08/2026 (ambiente de teste)

- **PHP subiu de 8.4.22 para 8.4.24** no servidor (upgrade via apt junto com
  `php8.4-sqlite3`). A stack no topo deste arquivo já cita 8.4.24.
- **`php8.4-sqlite3` agora ESTÁ instalado** (antes ausente por premissa). Isso
  torna o `phpunit.xml` (que aponta para sqlite `:memory:`) uma armadilha: rodar
  `php artisan test` PURO agora executa as migrations em sqlite e quebra em massa,
  porque várias migrations usam SQL específico de MySQL (`IF()`, `MODIFY ... ENUM`,
  `UPDATE ... JOIN`). **Isso NÃO é bug** — as migrations estão corretas para MySQL.
- **Regra:** rode a suíte SEMPRE com as variáveis MySQL prefixadas, nunca
  `php artisan test` puro:
```bash
  DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
  DB_DATABASE=limen_test DB_USERNAME=limen DB_PASSWORD='<ver .env>' \
  HCAPTCHA_ENABLED=false php artisan test
```
  Resultado esperado (`main` `ae389c2`, pós-PRs #173–#187): **~2016 passam, 1 falha**
  de **~2017 testes / 16083 asserts** (o `GeoBlockTest` da view 451, falha documentada
  só neste clone de dev; verde no CI). O número consolida a fila de melhorias — Turnstile
  (#174), sinais de atividade (#175), teaser (#176), filtro de cidade (#177), visitas
  bidirecionais (#178), microinterações (#179), intro de voz (#180) — mais a landing
  cinematográfica (#184) e o foco em lista de espera (#187), todos mergeados na `main`.
  `feat/landing-motion` / `fix/landing-motion-v2` não adicionam testes (a camada é
  visual; os testes de landing existentes — `LandingCinematicTest`,
  `LandingCinematicAssetsTest`, `ExternalAssetPolicyTest` — seguem verdes, confirmados
  nesta sessão em 2017 testes / 16083 asserts).

## Nota operacional — 14/08/2026 (fix do flake de relógio do PrivacyPerksTest)

- **Branch `fix/privacy-test-and-waitlist-copy`** (sobre a `feat/landing-marble-bg`).
  O flake de relógio do `PrivacyPerksTest` — que só aparecia rodando a suíte DEPOIS de
  julho/2026 — está **RESOLVIDO**. Os testes de faixa do painel de visitantes usavam
  datas FIXAS (`2026-07-21`) via `travelTo`; como o `ProfileVisitService` calcula
  elegibilidade de piso por IDADE DE CONTA relativa a `now()` e os seguidores/visitantes
  nascem por `now()->subDays(30)`, viajar para um passado distante os fazia parecer novos
  demais para o piso de 7 dias e o painel sumia (faixa vazia). **Correção só no teste:**
  todas as datas fixas viraram RELATIVAS a `now()` via o helper `perkTodayAt(int $hour)`
  (hora de HOJE no fuso `DISPLAY_TIMEZONE`), `perkVisitorsAt` recebe `Carbon`. Passa hoje,
  amanhã e em 2027. **Nenhum service mudou.** `PrivacyPerksTest` 66/66.
- **Além disso**, mesmo PR: texto/nomes da lista de espera na `Landing.vue` (subtítulo da
  entrada sem "Sem spam"; aviso de spam só na tela de sucesso; rótulos do seletor "Membro"
  → "Associado" e "Performer" → "Residente", **só o rótulo visível — valor técnico
  `member`/`performer` intacto e nenhum outro arquivo tocado**). Ver a seção do handoff
  "Landing — nomes da waitlist + fix do flake de relógio do PrivacyPerksTest".
- **Resultado esperado da suíte com este PR:** volta a **1 única falha** (o `GeoBlockTest`
  451 deste clone de dev) — as falhas do `PrivacyPerksTest` somem, que é o objetivo.
## Ponteiros — onde está cada coisa

- **Economia (preços, pacotes, splits, tiers, descontos, franquias, teto, payout,
  retenção, arredondamento):** `docs/ECONOMIA.md` (fonte canônica). As **invariantes de
  ENGENHARIA** da economia (ledger append-only, decimal exato, R1–R4) ficam neste guia,
  seção "Invariantes de ENGENHARIA da economia".
- **Decisões de produto de agosto/2026:** `docs/DECISOES_2026-08.md`.
- **Pendências que dependem do jurídico:** `docs/PENDENCIAS_JURIDICAS.md`.
- **Histórico de sprints (auditoria):** `docs/HISTORICO_SPRINTS.md`.
- **Arquitetura detalhada e features/serviços:** `docs/ARQUITETURA.md`.
- **Handoff mestre:** `docs/MASTER_HANDOFF_FINAL.md` (ler antes de pegar tarefa nova).
- **Segurança/achados:** `docs/SECURITY_ISSUES.md`.

## Contas de UAT e dados de teste

As credenciais da massa de QA (50 performers + 100 membros, senhas e papéis) vivem em
**`docs/qa/TEST_ACCOUNTS.md`**, persistidas pelo `LimenTestSeeder`/`LimenStagingSeeder`.
**Dados reais só em produção** (princípio nº 6): dev/staging usam dados sintéticos.
